//go:build integration

package integration

// Phase 5: inbound DSN/complaint processing, correlation, the global
// suppression policy (D-30), the unmatched-DSN workflow, crash/retry
// idempotency and spool retention - against PostgreSQL with the real
// migrations and grants, as smarthost_delivery.

import (
	"crypto/rand"
	"encoding/base64"
	"encoding/hex"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"smarthost.local/delivery/internal/dsnspool"
	"smarthost.local/delivery/internal/ids"
	"smarthost.local/delivery/internal/logx"
	"smarthost.local/delivery/internal/store"
)

var verpCfg = ids.VERP{LocalPart: "bounce", Delimiter: "+", Domain: bounceDomain}

// sent is a message Postfix (the test server) accepted.
type sent struct {
	ID, Job, Client, Addr, VERP, ReturnPath, QID, Sender string
}

func tag() string {
	b := make([]byte, 4)
	_, _ = rand.Read(b)
	return hex.EncodeToString(b)
}

// sendTo runs a job of client (with its verified domain) to dispatch and
// returns the accepted messages in recipient order.
func (e *env) sendTo(client, domain string, addrs ...string) []sent {
	e.t.Helper()
	rs := make([]rcpt, len(addrs))
	for i, a := range addrs {
		rs[i] = rcpt{addr: a}
	}
	job := e.newJob(jobOpts{client: client, domain: domain}, rs)
	w := e.worker("p5-" + tag())
	j := e.claim(w)
	w.ProcessJob(e.ctx, j)
	rows, err := owner.Query(e.ctx, `
SELECT m.id::text, m.recipient_address, m.verp_token, m.return_path, COALESCE(m.postfix_queue_id, ''), j.sender_email, r.email_address
  FROM messages m JOIN send_jobs j ON j.id = m.send_job_id JOIN send_job_recipients r ON r.id = m.send_job_recipient_id
 WHERE m.send_job_id = $1 ORDER BY r.id`, job)
	if err != nil {
		e.t.Fatal(err)
	}
	defer rows.Close()
	var out []sent
	for rows.Next() {
		s := sent{Job: job, Client: client}
		var orig string
		if err := rows.Scan(&s.ID, &s.Addr, &s.VERP, &s.ReturnPath, &s.QID, &s.Sender, &orig); err != nil {
			e.t.Fatal(err)
		}
		out = append(out, s)
	}
	return out
}

// otherClient creates a second active client with a verified sending domain.
func (e *env) otherClient() (string, string) {
	c := e.newClient("active")
	return c, e.newDomain(c, "verified")
}

func (e *env) spool(id string) *dsnspool.Processor {
	e.t.Helper()
	dir := filepath.Join(e.obs, "spool")
	for _, d := range []string{"inbound/new", "inbound/tmp", "inbound/cur", "processing", "done", "failed"} {
		if err := os.MkdirAll(filepath.Join(dir, d), 0o770); err != nil {
			e.t.Fatal(err)
		}
	}
	return &dsnspool.Processor{Dir: dir, Store: e.st, Log: logx.New("error", "json"), VERP: verpCfg, BounceDomain: bounceDomain,
		PollInterval: 100 * time.Millisecond, StaleAfter: time.Minute, Retention: 7 * 24 * time.Hour, Instance: id, DisableWatch: true}
}

var spoolSeq atomic.Int64

// deliver writes a rendered fixture into inbound/new under a Maildir unique
// name, as Postfix virtual(8) does, and returns the name (the D-06 key).
func (e *env) deliver(p *dsnspool.Processor, fixture string, vars map[string]string) string {
	e.t.Helper()
	name := fmt.Sprintf("%d.V803I%dM%06d.postfix", time.Now().Unix(), 1000+spoolSeq.Add(1), time.Now().Nanosecond()/1000)
	if err := os.WriteFile(filepath.Join(p.Dir, "inbound", "new", name), render(e.t, fixture, vars), 0o600); err != nil {
		e.t.Fatal(err)
	}
	return name
}

func render(t *testing.T, name string, vars map[string]string) []byte {
	t.Helper()
	b, err := os.ReadFile(filepath.Join("..", "dsn", "testdata", name))
	if err != nil {
		t.Fatal(err)
	}
	var pairs []string
	for k, v := range vars {
		pairs = append(pairs, "{{"+k+"}}", v)
	}
	return []byte(strings.NewReplacer(pairs...).Replace(string(b)))
}

// vars correlates a fixture with message s by every identifier.
func vars(s sent) map[string]string {
	return map[string]string{"VERP": s.ReturnPath, "VERP_SENDER": s.ReturnPath, "ENVID": s.ID, "MSGID": s.ID, "BOUNCE": bounceDomain,
		"QID": s.QID, "RCPT": s.Addr, "OTHER": "other-" + tag() + "@elsewhere.test", "FROM": s.Sender,
		"B64_STATUS": base64.StdEncoding.EncodeToString([]byte("Reporting-MTA: dns; mx.example.org\n\nFinal-Recipient: rfc822; " + s.Addr +
			"\nAction: failed\nStatus: 5.1.1\nDiagnostic-Code: smtp; 550 5.1.1 unknown\n"))}
}

// only keeps the given identifiers of vars (the others point nowhere).
func only(v map[string]string, keep ...string) map[string]string {
	out := map[string]string{"BOUNCE": bounceDomain, "VERP": "postmaster@" + bounceDomain, "VERP_SENDER": "bounce@" + bounceDomain,
		"ENVID": "none", "MSGID": "none", "QID": "NOQUEUEID00", "FROM": "nobody@unrelated.test"}
	for k, x := range v {
		if _, set := out[k]; !set {
			out[k] = x
		}
	}
	for _, k := range keep {
		out[k] = v[k]
	}
	return out
}

func (e *env) pass(p *dsnspool.Processor) dsnspool.PassResult {
	e.t.Helper()
	r, err := p.Pass(e.ctx)
	if err != nil {
		e.t.Fatal(err)
	}
	return r
}

func (e *env) suppressions(addr string) []map[string]any {
	e.t.Helper()
	rows, err := owner.Query(e.ctx, `
SELECT id::text, reason, (client_id IS NULL) AS global, COALESCE(source_message_id::text, '') AS source_message,
       COALESCE(source_event_id::text, '') AS source_event, expires_at IS NOT NULL AS expires, lifted_at IS NULL AS unlifted
  FROM suppressions WHERE address_or_domain = $1 ORDER BY created_at`, addr)
	if err != nil {
		e.t.Fatal(err)
	}
	defer rows.Close()
	var out []map[string]any
	for rows.Next() {
		v, _ := rows.Values()
		f := rows.FieldDescriptions()
		m := map[string]any{}
		for i := range v {
			m[f[i].Name] = v[i]
		}
		out = append(out, m)
	}
	return out
}

// ---------------------------------------------------------------------------

// D-30 acceptance: Client A's recipient-specific hard bounce suppresses the
// address for Client B; B's later message is suppressed, never submitted.
func TestHardBounceDSNCreatesGlobalSuppressionAcrossClients(t *testing.T) {
	e := newEnv(t)
	addr := "Hard.Bounce-" + tag() + "@Example.TEST"
	norm := addr[:strings.LastIndexByte(addr, '@')] + "@example.test"
	a := e.sendTo(e.client, e.domain, addr)[0]
	if a.QID == "" || a.Addr != norm {
		t.Fatalf("message not submitted: %+v", a)
	}
	p := e.spool("a")
	key := e.deliver(p, "postfix-hard-5.1.1.eml", vars(a))
	e.pass(p)

	if st := e.str(`SELECT current_status FROM messages WHERE id = $1`, a.ID); st != "hard_bounced" {
		t.Fatalf("status %s", st)
	}
	if n := e.count(`SELECT count(*) FROM message_events WHERE message_id = $1 AND event_type = 'hard_bounce' AND event_source = 'dsn_spool'
  AND source_event_key = $2 AND failure_scope = 'recipient' AND enhanced_status_code = '5.1.1' AND metadata_json->>'correlation' = 'verp'`, a.ID, key); n != 1 {
		t.Fatalf("hard_bounce events %d", n)
	}
	if n := e.count(`SELECT count(*) FROM webhook_events WHERE subject_id = $1 AND event_type = 'message.hard_bounced' AND client_id = $2`, a.ID, a.Client); n != 1 {
		t.Fatalf("message.hard_bounced outbox rows %d", n)
	}
	sups := e.suppressions(norm)
	if len(sups) != 1 || sups[0]["reason"] != "hard_bounce" || sups[0]["global"] != true || sups[0]["source_message"] != a.ID ||
		sups[0]["source_event"] == "" || sups[0]["expires"] != false {
		t.Fatalf("suppressions %+v", sups)
	}
	if _, err := os.Stat(filepath.Join(p.Dir, "done", key)); err != nil {
		t.Fatalf("processed file not retained in done/: %v", err)
	}

	// Client B, same normalised recipient (different domain case).
	bClient, bDomain := e.otherClient()
	before := len(e.smtp.Messages())
	b := e.sendTo(bClient, bDomain, strings.Replace(addr, "@Example.TEST", "@EXAMPLE.test", 1))[0]
	if st := e.str(`SELECT current_status FROM messages WHERE id = $1`, b.ID); st != "suppressed" || b.QID != "" {
		t.Fatalf("client B message %s (queue id %q)", st, b.QID)
	}
	if e.str(`SELECT metadata_json->>'reason' FROM message_events WHERE message_id = $1 AND event_type = 'message_suppressed'`, b.ID) != "hard_bounce" {
		t.Fatal("message_suppressed must name the global hard_bounce")
	}
	if len(e.smtp.Messages()) != before {
		t.Fatal("a globally suppressed recipient reached Postfix")
	}
	// A local part differing in case is a different address (D-18): not suppressed.
	c := e.sendTo(bClient, bDomain, strings.ToLower(addr[:strings.LastIndexByte(addr, '@')])+"@example.test")[0]
	if c.QID == "" {
		t.Fatal("the local part must not be case-folded for suppression matching")
	}
}

func TestCorrelationLevels(t *testing.T) {
	e := newEnv(t)
	p := e.spool("c")
	cases := []struct {
		name, fixture, method string
		keep                  []string
	}{
		{"verp", "postfix-hard-5.1.1.eml", "verp", []string{"VERP"}},
		{"envelope id", "missing-optional-fields.eml", "envelope_id", []string{"ENVID"}},
		{"returned message id", "message-id-only.eml", "message_id", []string{"MSGID"}},
		{"postfix queue id", "queue-id-only.eml", "queue_id", []string{"QID"}},
		{"recipient and sender", "message-id-only.eml", "recipient", []string{"FROM"}},
		{"message id quoted in a non-standard bounce", "nonstandard-bounce.eml", "message_id", []string{"MSGID"}},
	}
	for _, c := range cases {
		s := e.sendTo(e.client, e.domain, "corr-"+tag()+"@rcpt.test")[0]
		v := only(vars(s), c.keep...)
		if c.method == "recipient" {
			v["MSGID"] = "none" // returned headers without a Smarthost id: only recipient + sender remain
		}
		key := e.deliver(p, c.fixture, v)
		e.pass(p)
		got := e.str(`SELECT metadata_json->>'correlation' FROM message_events WHERE event_source = 'dsn_spool' AND source_event_key = $1 AND message_id = $2`, key, s.ID)
		if got != c.method {
			t.Errorf("%s: correlation %q, want %q (unmatched rows: %d)", c.name, got, c.method,
				e.count(`SELECT count(*) FROM unmatched_dsns WHERE spool_ingest_key = $1`, key))
		}
	}

	// Never a guess: two candidate messages to the same recipient, no identifier.
	addr := "twice-" + tag() + "@rcpt.test"
	m1 := e.sendTo(e.client, e.domain, addr)[0]
	e.sendTo(e.client, e.domain, addr)
	key := e.deliver(p, "message-id-only.eml", only(vars(m1), "FROM"))
	e.pass(p)
	if e.count(`SELECT count(*) FROM unmatched_dsns WHERE spool_ingest_key = $1 AND status = 'open' AND detail_json->>'reason' LIKE 'several candidate%'`, key) != 1 ||
		e.count(`SELECT count(*) FROM message_events WHERE source_event_key = $1`, key) != 0 {
		t.Error("an ambiguous recipient must become an unmatched DSN, never a guessed event")
	}
	if len(e.suppressions(addr)) != 0 {
		t.Error("an unmatched DSN must not suppress")
	}
	// A recipient without corroborating sender is not enough either.
	single := e.sendTo(e.client, e.domain, "alone-"+tag()+"@rcpt.test")[0]
	key = e.deliver(p, "message-id-only.eml", only(vars(single)))
	e.pass(p)
	if e.count(`SELECT count(*) FROM unmatched_dsns WHERE spool_ingest_key = $1`, key) != 1 {
		t.Error("recipient alone must not correlate")
	}
	// Conflicting identifiers (VERP of one message, envelope id of another).
	x := e.sendTo(e.client, e.domain, "conflict-a-"+tag()+"@rcpt.test")[0]
	y := e.sendTo(e.client, e.domain, "conflict-b-"+tag()+"@rcpt.test")[0]
	v := vars(x)
	v["ENVID"], v["MSGID"] = y.ID, y.ID
	key = e.deliver(p, "postfix-hard-5.1.1.eml", v)
	e.pass(p)
	if e.count(`SELECT count(*) FROM unmatched_dsns WHERE spool_ingest_key = $1 AND detail_json->>'reason' LIKE 'conflicting%'`, key) != 1 {
		t.Error("conflicting evidence must be unmatched")
	}
	// Identifiers naming no message: unmatched, with the raw and parsed evidence.
	key = e.deliver(p, "postfix-hard-5.1.1.eml", map[string]string{"VERP": verpCfg.ReturnPath(ids.VERPToken()), "VERP_SENDER": "x@" + bounceDomain,
		"ENVID": ids.UUIDv7(), "MSGID": ids.UUIDv7(), "BOUNCE": bounceDomain, "QID": "4UNKNOWNQUEUE1", "RCPT": "ghost@rcpt.test", "FROM": "a@b.test"})
	e.pass(p)
	if e.count(`SELECT count(*) FROM unmatched_dsns WHERE spool_ingest_key = $1 AND status = 'open' AND classification = 'hard_bounce'
  AND final_recipient = 'ghost@rcpt.test' AND enhanced_status_code = '5.1.1' AND reporting_mta = 'smarthost.example'
  AND verp_token IS NOT NULL AND raw_message LIKE '%Undelivered Mail Returned to Sender%' AND length(content_sha256) = 64`, key) != 1 {
		t.Error("unknown identifiers must become an open unmatched DSN with raw and parsed evidence")
	}
}

func TestExcludedFailureScopesNeverSuppress(t *testing.T) {
	e := newEnv(t)
	e.exec(`DELETE FROM delivery_ingest_cursors`)
	p := e.spool("x")
	for _, f := range []struct{ fixture, event, scope string }{
		{"provider-policy-5.7.1.eml", "hard_bounce", "provider_policy"},
		{"domain-failure-5.1.2.eml", "hard_bounce", "domain"},
		{"delay-notice.eml", "connection_failure", "connection"},
		{"remote-mailbox-full-4.2.2.eml", "soft_bounce", "recipient"}, // a single soft bounce
	} {
		s := e.sendTo(e.client, e.domain, "scope-"+tag()+"@rcpt.test")[0]
		key := e.deliver(p, f.fixture, vars(s))
		e.pass(p)
		if n := e.count(`SELECT count(*) FROM message_events WHERE source_event_key = $1 AND event_type = $2 AND failure_scope = $3`, key, f.event, f.scope); n != 1 {
			t.Errorf("%s: event %s/%s not recorded", f.fixture, f.event, f.scope)
		}
		if sups := e.suppressions(s.Addr); len(sups) != 0 {
			t.Errorf("%s: suppressed %+v", f.fixture, sups)
		}
	}
	// The same policy for Postfix log evidence: 5.7.1 never, 5.1.1 recipient yes.
	policy := e.sendTo(e.client, e.domain, "logpolicy-"+tag()+"@rcpt.test")[0]
	hard := e.sendTo(e.client, e.domain, "loghard-"+tag()+"@rcpt.test")[0]
	ts := logTime(time.Now())
	e.writeLog(
		ts+" pf postfix/smtp[9]: "+policy.QID+": to=<"+policy.Addr+">, relay=mx[192.0.2.1]:25, delay=1, delays=0/0/0/1, dsn=5.7.1, status=bounced (host mx[192.0.2.1] said: 550 5.7.1 Rejected: sending IP listed on a block list (in reply to RCPT TO command))",
		ts+" pf postfix/smtp[9]: "+hard.QID+": to=<"+hard.Addr+">, relay=mx[192.0.2.1]:25, delay=1, delays=0/0/0/1, dsn=5.1.1, status=bounced (host mx[192.0.2.1] said: 550 5.1.1 User unknown (in reply to RCPT TO command))",
	)
	if err := ingester(e).Pass(e.ctx); err != nil {
		t.Fatal(err)
	}
	if e.str(`SELECT current_status FROM messages WHERE id = $1`, policy.ID) != "hard_bounced" || len(e.suppressions(policy.Addr)) != 0 {
		t.Error("a provider-policy bounce from the log must project hard_bounced but never suppress")
	}
	if sups := e.suppressions(hard.Addr); len(sups) != 1 || sups[0]["reason"] != "hard_bounce" || sups[0]["source_message"] != hard.ID {
		t.Errorf("a recipient hard bounce from the Postfix log must create the same global suppression: %+v", sups)
	}
	// ... and the DSN about the same failure adds no second suppression.
	e.deliver(p, "postfix-hard-5.1.1.eml", vars(hard))
	e.pass(p)
	if n := len(e.suppressions(hard.Addr)); n != 1 {
		t.Errorf("log + DSN evidence created %d suppressions", n)
	}
	if n := e.count(`SELECT count(*) FROM webhook_events WHERE subject_id = $1 AND event_type = 'message.hard_bounced'`, hard.ID); n != 1 {
		t.Errorf("message.hard_bounced written %d times", n)
	}
}

func TestRepeatedSoftBouncesAcrossClients(t *testing.T) {
	e := newEnv(t)
	p := e.spool("s")
	addr := "soft-" + tag() + "@rcpt.test"
	bClient, bDomain := e.otherClient()
	soft := func(client, domain string) sent {
		s := e.sendTo(client, domain, addr)[0]
		e.deliver(p, "remote-mailbox-full-4.2.2.eml", vars(s))
		e.pass(p)
		return s
	}
	soft(e.client, e.domain)
	soft(bClient, bDomain)
	// Excluded scopes in between neither count nor reset.
	x := e.sendTo(e.client, e.domain, addr)[0]
	e.deliver(p, "delay-notice.eml", vars(x))
	e.deliver(p, "provider-policy-5.7.1.eml", vars(x))
	e.pass(p)
	if n := len(e.suppressions(addr)); n != 0 {
		t.Fatalf("suppressed after 2 recipient soft bounces (%d)", n)
	}
	// The same message's DSN and log record are one occurrence.
	again := e.sendTo(e.client, e.domain, addr)[0]
	e.deliver(p, "remote-mailbox-full-4.2.2.eml", vars(again))
	e.pass(p)
	sups := e.suppressions(addr)
	if len(sups) != 1 || sups[0]["reason"] != "repeated_soft_bounce" || sups[0]["global"] != true || sups[0]["expires"] != true {
		t.Fatalf("third consecutive recipient soft bounce (two clients): %+v", sups)
	}
	window := e.str(`SELECT round(extract(epoch FROM expires_at - created_at) / 86400)::text FROM suppressions WHERE address_or_domain = $1`, addr)
	if window != "30" {
		t.Errorf("repeated_soft_bounce lifetime %s days, want the 30-day window", window)
	}
	// Active: a later send of either client is suppressed.
	if b := e.sendTo(bClient, bDomain, addr)[0]; b.QID != "" {
		t.Error("repeated_soft_bounce must suppress every client")
	}
	// Expiry (temporary rule): once expired, the address is sendable again.
	e.exec(`UPDATE suppressions SET expires_at = now() - interval '1 second' WHERE address_or_domain = $1`, addr)
	if b := e.sendTo(bClient, bDomain, addr)[0]; b.QID == "" {
		t.Error("an expired repeated_soft_bounce suppression must not apply")
	}
}

func TestRemoteAcceptedResetsTheSoftBounceSequence(t *testing.T) {
	e := newEnv(t)
	e.exec(`DELETE FROM delivery_ingest_cursors`)
	p := e.spool("r")
	addr := "reset-" + tag() + "@rcpt.test"
	for i := 0; i < 2; i++ {
		s := e.sendTo(e.client, e.domain, addr)[0]
		e.deliver(p, "remote-mailbox-full-4.2.2.eml", vars(s))
		e.pass(p)
	}
	ok := e.sendTo(e.client, e.domain, addr)[0]
	e.writeLog(logTime(time.Now().Add(time.Second)) + " pf postfix/smtp[9]: " + ok.QID + ": to=<" + addr + ">, relay=mx[192.0.2.1]:25, delay=1, delays=0/0/0/1, dsn=2.0.0, status=sent (250 2.0.0 Ok)")
	if err := ingester(e).Pass(e.ctx); err != nil {
		t.Fatal(err)
	}
	time.Sleep(2100 * time.Millisecond) // the next soft bounces happen after the acceptance (whole-second times)
	for i := 0; i < 2; i++ {
		s := e.sendTo(e.client, e.domain, addr)[0]
		e.deliver(p, "remote-mailbox-full-4.2.2.eml", vars(s))
		e.pass(p)
	}
	if n := len(e.suppressions(addr)); n != 0 {
		t.Fatalf("remote_accepted must reset the sequence (2 + 2 soft bounces): %d suppressions", n)
	}
	s := e.sendTo(e.client, e.domain, addr)[0]
	e.deliver(p, "remote-mailbox-full-4.2.2.eml", vars(s))
	e.pass(p)
	if n := len(e.suppressions(addr)); n != 1 {
		t.Fatalf("three consecutive after the reset: %d suppressions", n)
	}
}

// A relay accepted each message (remote_accepted from the Postfix log) and the
// final mailbox then returned a soft-bounce DSN: those acceptances are
// contradicted by the same message's DSN and do not reset the sequence.
func TestRelayAcceptedThenSoftBouncedCounts(t *testing.T) {
	e := newEnv(t)
	e.exec(`DELETE FROM delivery_ingest_cursors`)
	p := e.spool("relay")
	addr := "relay-" + tag() + "@rcpt.test"
	for i := 0; i < 3; i++ {
		s := e.sendTo(e.client, e.domain, addr)[0]
		e.writeLog(logTime(time.Now()) + " pf postfix/smtp[9]: " + s.QID + ": to=<" + addr + ">, relay=relay[192.0.2.1]:25, delay=1, delays=0/0/0/1, dsn=2.0.0, status=sent (250 2.0.0 Ok)")
		if err := ingester(e).Pass(e.ctx); err != nil {
			t.Fatal(err)
		}
		if e.str(`SELECT current_status FROM messages WHERE id = $1`, s.ID) != "remote_accepted" {
			t.Fatal("not remote_accepted")
		}
		time.Sleep(2100 * time.Millisecond) // the DSN arrives after the relay's acceptance (whole-second times)
		e.deliver(p, "remote-mailbox-full-4.2.2.eml", vars(s))
		e.pass(p)
		if st := e.str(`SELECT current_status FROM messages WHERE id = $1`, s.ID); st != "soft_bounced" {
			t.Fatalf("DSN after acceptance projected %s", st)
		}
		if n := len(e.suppressions(addr)); (i < 2 && n != 0) || (i == 2 && n != 1) {
			t.Fatalf("after %d relay-accepted soft bounces: %d suppressions", i+1, n)
		}
	}
}

func TestConcurrentEvidenceCreatesOneSuppression(t *testing.T) {
	e := newEnv(t)
	// Hard bounces: five messages to one address, processed by four workers at once.
	addr := "race-" + tag() + "@rcpt.test"
	var msgs []sent
	for i := 0; i < 5; i++ {
		msgs = append(msgs, e.sendTo(e.client, e.domain, addr)[0])
	}
	base := e.spool("base")
	// Soft bounces: two committed, then two concurrent ones cross the threshold together.
	soft := "racesoft-" + tag() + "@rcpt.test"
	for i := 0; i < 2; i++ {
		s := e.sendTo(e.client, e.domain, soft)[0]
		e.deliver(base, "remote-mailbox-full-4.2.2.eml", vars(s))
		e.pass(base)
	}
	for _, m := range msgs {
		e.deliver(base, "postfix-hard-5.1.1.eml", vars(m))
	}
	for i := 0; i < 2; i++ {
		e.deliver(base, "remote-mailbox-full-4.2.2.eml", vars(e.sendTo(e.client, e.domain, soft)[0]))
	}
	var wg sync.WaitGroup
	for i := 0; i < 4; i++ {
		p := e.spool(fmt.Sprint("w", i))
		wg.Add(1)
		go func() {
			defer wg.Done()
			if _, err := p.Pass(e.ctx); err != nil {
				t.Error(err)
			}
		}()
	}
	wg.Wait()
	if n := e.count(`SELECT count(*) FROM message_events WHERE message_id = ANY($1::text[]::uuid[]) AND event_type = 'hard_bounce'`, ids5(msgs)); n != 5 {
		t.Fatalf("hard bounce events %d, want 5 (each file exactly once)", n)
	}
	if sups := e.suppressions(addr); len(sups) != 1 {
		t.Fatalf("concurrent hard bounces created %d suppressions", len(sups))
	}
	if sups := e.suppressions(soft); len(sups) != 1 || sups[0]["reason"] != "repeated_soft_bounce" {
		t.Fatalf("concurrent threshold crossing created %+v", sups)
	}
}

func ids5(ms []sent) []string {
	var out []string
	for _, m := range ms {
		out = append(out, m.ID)
	}
	return out
}

func TestComplaints(t *testing.T) {
	e := newEnv(t)
	p := e.spool("f")
	s := e.sendTo(e.client, e.domain, "complainer-"+tag()+"@rcpt.test")[0]
	e.exec(`UPDATE messages SET current_status = 'remote_accepted', resolved_at = now() WHERE id = $1`, s.ID)
	e.exec(`UPDATE send_jobs SET status = 'completed', completed_at = now(), summary_counts_json = '{"remote_accepted": 1}' WHERE id = $1`, s.Job)
	key := e.deliver(p, "arf-abuse.eml", vars(s))
	e.pass(p)
	if e.str(`SELECT current_status FROM messages WHERE id = $1`, s.ID) != "complained" ||
		e.count(`SELECT count(*) FROM message_events WHERE source_event_key = $1 AND event_type = 'complaint' AND failure_scope IS NULL AND metadata_json->>'feedback_type' = 'abuse'`, key) != 1 {
		t.Fatal("ARF complaint not recorded")
	}
	if e.count(`SELECT count(*) FROM webhook_events WHERE subject_id = $1 AND event_type = 'message.complained'`, s.ID) != 1 {
		t.Fatal("message.complained not in the outbox")
	}
	if e.jobStatus(s.Job) != "completed" {
		t.Fatal("a complaint must never reopen a completed job")
	}
	if sups := e.suppressions(s.Addr); len(sups) != 1 || sups[0]["reason"] != "complaint" || sups[0]["global"] != true {
		t.Fatalf("complaint suppression %+v", sups)
	}
	// A duplicate complaint (another report about the same message) adds an
	// event but neither a second suppression nor a second webhook.
	e.deliver(p, "arf-abuse.eml", vars(s))
	e.pass(p)
	if len(e.suppressions(s.Addr)) != 1 || e.count(`SELECT count(*) FROM webhook_events WHERE subject_id = $1 AND event_type = 'message.complained'`, s.ID) != 1 {
		t.Fatal("duplicate complaint duplicated side effects")
	}

	// Missing Original-Rcpt-To, correlated by the returned Message-ID: still the exact message.
	m := e.sendTo(e.client, e.domain, "norcpt-"+tag()+"@rcpt.test")[0]
	e.deliver(p, "arf-missing-recipient.eml", only(vars(m), "MSGID"))
	e.pass(p)
	if len(e.suppressions(m.Addr)) != 1 {
		t.Error("complaint correlated by Message-ID without Original-Rcpt-To must suppress")
	}
	// Unknown message: preserved for the operator, nobody suppressed.
	u := "unknown-" + tag() + "@rcpt.test"
	key = e.deliver(p, "arf-unknown-message.eml", map[string]string{"BOUNCE": bounceDomain, "RCPT": u})
	e.pass(p)
	if e.count(`SELECT count(*) FROM unmatched_dsns WHERE spool_ingest_key = $1 AND classification = 'complaint' AND status = 'open'`, key) != 1 || len(e.suppressions(u)) != 0 {
		t.Error("an uncorrelated complaint must stay an open unmatched DSN without suppression")
	}
	// Malformed feedback report (no Feedback-Type) about a known message: no complaint.
	mf := e.sendTo(e.client, e.domain, "malformed-"+tag()+"@rcpt.test")[0]
	key = e.deliver(p, "arf-malformed.eml", vars(mf))
	e.pass(p)
	if e.count(`SELECT count(*) FROM message_events WHERE source_event_key = $1 AND event_type = 'dsn_unmatched'`, key) != 1 || len(e.suppressions(mf.Addr)) != 0 {
		t.Error("a malformed feedback report must not be a complaint")
	}
	// A complaint naming another recipient than the correlated message: not attributed.
	other := e.sendTo(e.client, e.domain, "attributed-"+tag()+"@rcpt.test")[0]
	v := vars(other)
	v["RCPT"] = "someone-else@rcpt.test"
	e.deliver(p, "arf-abuse.eml", v)
	e.pass(p)
	if len(e.suppressions(other.Addr)) != 0 || len(e.suppressions("someone-else@rcpt.test")) != 0 {
		t.Error("a complaint for another recipient must not suppress anyone")
	}
}

func TestLateAuthoritativeEventsProjectWithoutReopeningJobs(t *testing.T) {
	e := newEnv(t)
	p := e.spool("l")
	// remote_accepted -> later DSN -> hard_bounced; outcome_unknown -> hard_bounced.
	a := e.sendTo(e.client, e.domain, "late-a-"+tag()+"@rcpt.test")[0]
	b := e.sendTo(e.client, e.domain, "late-b-"+tag()+"@rcpt.test")[0]
	e.exec(`UPDATE messages SET current_status = 'remote_accepted', resolved_at = now() WHERE id = $1`, a.ID)
	e.exec(`UPDATE messages SET current_status = 'outcome_unknown', resolved_at = now() WHERE id = $1`, b.ID)
	for _, s := range []sent{a, b} {
		e.exec(`UPDATE send_jobs SET status = 'completed', completed_at = now() WHERE id = $1`, s.Job)
		e.deliver(p, "postfix-hard-5.1.1.eml", vars(s))
	}
	e.pass(p)
	for _, s := range []sent{a, b} {
		if st := e.str(`SELECT current_status FROM messages WHERE id = $1`, s.ID); st != "hard_bounced" {
			t.Errorf("late DSN projected %s", st)
		}
		if e.jobStatus(s.Job) != "completed" {
			t.Error("a late DSN must not reopen a completed job")
		}
	}
	// A hard bounce after a hard bounce (rank) changes nothing and writes no second webhook.
	e.deliver(p, "postfix-hard-5.1.1.eml", vars(a))
	e.pass(p)
	if e.count(`SELECT count(*) FROM webhook_events WHERE subject_id = $1`, a.ID) != 1 {
		t.Error("message.hard_bounced must be written once")
	}
	// A DSN for a dispatched message completes its job in the same transaction.
	d := e.sendTo(e.client, e.domain, "complete-"+tag()+"@rcpt.test")[0]
	if e.jobStatus(d.Job) != "dispatched" {
		t.Fatalf("job %s", e.jobStatus(d.Job))
	}
	e.deliver(p, "postfix-hard-5.1.1.eml", vars(d))
	e.pass(p)
	if e.jobStatus(d.Job) != "completed" || e.count(`SELECT count(*) FROM webhook_events WHERE subject_id = $1 AND event_type = 'send.completed'`, d.Job) != 1 {
		t.Error("the last unresolved message's DSN must complete the job with send.completed")
	}
}

func TestSpoolIdempotencyCrashAndRetention(t *testing.T) {
	e := newEnv(t)
	s := e.sendTo(e.client, e.domain, "crash-"+tag()+"@rcpt.test")[0]
	p := e.spool("c1")
	// Crash after the database commit, before the move to done/.
	crashed := errorf("simulated crash")
	p.AfterCommit = func(string) error { return crashed }
	key := e.deliver(p, "postfix-hard-5.1.1.eml", vars(s))
	e.pass(p)
	if _, err := os.Stat(filepath.Join(p.Dir, "processing", key)); err != nil {
		t.Fatalf("the crashed claim must stay in processing/: %v", err)
	}
	// Another worker reclaims the stale claim; the key is already recorded.
	q := e.spool("smarthost/c2") // real worker ids contain "/"
	q.StaleAfter = 0
	time.Sleep(10 * time.Millisecond)
	e.pass(q)
	if q.Stats.Reclaimed.Load() != 1 || q.Stats.Already.Load() != 1 {
		t.Fatalf("reclaim stats: reclaimed %d already %d", q.Stats.Reclaimed.Load(), q.Stats.Already.Load())
	}
	if _, err := os.Stat(filepath.Join(p.Dir, "done", key)); err != nil {
		t.Fatalf("reclaimed file not moved to done/: %v", err)
	}
	// Duplicate delivery of the same Maildir file (e.g. a restored copy, a
	// repeated notification) is recognised by its key.
	raw, _ := os.ReadFile(filepath.Join(p.Dir, "done", key))
	_ = os.WriteFile(filepath.Join(p.Dir, "inbound", "new", key+":2,"), raw, 0o600)
	e.pass(q)
	if n := e.count(`SELECT count(*) FROM message_events WHERE source_event_key = $1`, key); n != 1 {
		t.Fatalf("%d events for one DSN after crash, reclaim and duplicate delivery", n)
	}
	if n := len(e.suppressions(s.Addr)); n != 1 {
		t.Fatalf("%d suppressions", n)
	}
	// Crash before the commit (database unavailable): the claim is retried, nothing lost.
	s2 := e.sendTo(e.client, e.domain, "retry-"+tag()+"@rcpt.test")[0]
	r := e.spool("c3")
	broken, _ := store.New(e.ctx, dsn("nobody", "wrong"), 1)
	r.Store = broken
	key2 := e.deliver(r, "postfix-hard-5.1.1.eml", vars(s2))
	if res := e.pass(r); res.Retry != 1 {
		t.Fatalf("a failing database must leave the claim for retry: %+v", res)
	}
	r.Store = e.st
	r.Now = func() time.Time { return time.Now().Add(time.Minute) }
	e.pass(r)
	if e.count(`SELECT count(*) FROM message_events WHERE source_event_key = $1`, key2) != 1 || r.Stats.Retries.Load() != 1 {
		t.Fatal("the retried claim was not processed exactly once")
	}
	// Unmatched rows are idempotent too.
	u := e.deliver(r, "postfix-hard-5.1.1.eml", map[string]string{"VERP": "postmaster@" + bounceDomain, "BOUNCE": bounceDomain, "RCPT": "nobody@rcpt.test"})
	e.pass(r)
	raw, _ = os.ReadFile(filepath.Join(r.Dir, "done", u))
	_ = os.WriteFile(filepath.Join(r.Dir, "inbound", "new", u), raw, 0o600)
	e.pass(r)
	if e.count(`SELECT count(*) FROM unmatched_dsns WHERE spool_ingest_key = $1`, u) != 1 {
		t.Fatal("duplicate unmatched DSN")
	}
	// Retention: old processed files are deleted, the database records stay.
	old := time.Now().Add(-8 * 24 * time.Hour)
	_ = os.Chtimes(filepath.Join(r.Dir, "done", key2), old, old)
	n, err := r.Sweep()
	if err != nil || n != 1 {
		t.Fatalf("sweep deleted %d (%v)", n, err)
	}
	if _, err := os.Stat(filepath.Join(r.Dir, "done", key2)); !os.IsNotExist(err) {
		t.Fatal("retention did not delete the old file")
	}
	if _, err := os.Stat(filepath.Join(r.Dir, "done", u)); err != nil {
		t.Fatal("retention deleted a recent file")
	}
	if e.count(`SELECT count(*) FROM message_events WHERE source_event_key = $1`, key2) != 1 {
		t.Fatal("retention must never touch the durable event")
	}
	// Unreadable files go to failed/ (operator alert), never silently lost.
	bad := filepath.Join(r.Dir, "inbound", "new", "1790000000.V1I999M1.unreadable")
	_ = os.WriteFile(bad, []byte("x"), 0o000)
	if os.Geteuid() != 0 { // root can read anything: the permission case only applies to the daemon's identity
		e.pass(r)
		if _, err := os.Stat(filepath.Join(r.Dir, "failed", "1790000000.V1I999M1.unreadable")); err != nil {
			t.Fatalf("unreadable file not moved to failed/: %v", err)
		}
	}
}

func TestConcurrentWorkersClaimEachFileOnce(t *testing.T) {
	e := newEnv(t)
	base := e.spool("base")
	var keys []string
	var msgs []sent
	for i := 0; i < 30; i++ {
		s := e.sendTo(e.client, e.domain, fmt.Sprintf("claim-%s-%d@rcpt.test", tag(), i))[0]
		msgs = append(msgs, s)
		keys = append(keys, e.deliver(base, "delay-notice.eml", vars(s)))
	}
	var wg sync.WaitGroup
	var claimed atomic.Int64
	for i := 0; i < 6; i++ {
		p := e.spool(fmt.Sprint("p", i))
		wg.Add(1)
		go func() {
			defer wg.Done()
			_, _ = p.Pass(e.ctx)
			claimed.Add(p.Stats.Claimed.Load())
		}()
	}
	wg.Wait()
	if claimed.Load() != 30 {
		t.Fatalf("claimed %d of 30 (each file exactly once)", claimed.Load())
	}
	if n := e.count(`SELECT count(*) FROM message_events WHERE event_source = 'dsn_spool' AND source_event_key = ANY($1)`, keys); n != 30 {
		t.Fatalf("%d events for 30 files", n)
	}
	for _, s := range msgs {
		if st := e.str(`SELECT current_status FROM messages WHERE id = $1`, s.ID); st != "deferred" {
			t.Fatalf("delay DSN projected %s", st)
		}
	}
}

// operatorUser creates an operator (as Symfony would) for match requests.
func (e *env) operatorUser() string {
	id := ids.UUIDv7()
	e.exec(`INSERT INTO users (id, email, global_role) VALUES ($1, $2, 'operator')`, id, "op-"+tag()+"@smarthost.test")
	return id
}

// requestMatch does what the Symfony smarthost:dsn:match command does.
func (e *env) requestMatch(unmatched, message, operator string) {
	e.exec(`UPDATE unmatched_dsns SET status = 'match_requested', matched_message_id = $2, resolution_requested_by = $3,
  resolution_requested_at = now() WHERE id = $1 AND status = 'open'`, unmatched, message, operator)
}

func TestUnmatchedDSNOperatorResolution(t *testing.T) {
	e := newEnv(t)
	p := e.spool("u")
	s := e.sendTo(e.client, e.domain, "resolve-"+tag()+"@rcpt.test")[0]
	// The DSN carries no usable identifier (e.g. a provider stripped everything).
	key := e.deliver(p, "postfix-hard-5.1.1.eml", only(vars(s)))
	e.pass(p)
	row := e.str(`SELECT id::text FROM unmatched_dsns WHERE spool_ingest_key = $1 AND status = 'open'`, key)
	if row == "" || e.count(`SELECT count(*) FROM message_events WHERE message_id = $1 AND event_type = 'hard_bounce'`, s.ID) != 0 {
		t.Fatal("the DSN must be an open unmatched DSN with no guessed event")
	}
	op := e.operatorUser()
	e.requestMatch(row, s.ID, op)
	resolver := &dsnspool.Resolver{Store: e.st, Log: logx.New("error", "json"), PollInterval: time.Second}
	// Two resolvers at once: the row is claimed once (SKIP LOCKED in one transaction).
	var wg sync.WaitGroup
	var handled atomic.Int64
	for i := 0; i < 3; i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			n, err := resolver.Pass(e.ctx)
			if err != nil {
				t.Error(err)
			}
			handled.Add(int64(n))
		}()
	}
	wg.Wait()
	if handled.Load() != 1 {
		t.Fatalf("match request handled %d times", handled.Load())
	}
	if e.count(`SELECT count(*) FROM unmatched_dsns d JOIN message_events ev ON ev.id = d.resolution_event_id
  WHERE d.id = $1 AND d.status = 'matched' AND d.resolved_at IS NOT NULL AND d.matched_message_id = $2 AND d.resolution_requested_by = $3
    AND ev.message_id = $2 AND ev.event_type = 'hard_bounce' AND ev.event_source = 'unmatched_dsn_resolution' AND ev.source_event_key = $1::text`,
		row, s.ID, op) != 1 {
		t.Fatal("resolution not recorded with event, operator, message and time")
	}
	if e.str(`SELECT current_status FROM messages WHERE id = $1`, s.ID) != "hard_bounced" {
		t.Fatal("resolution not projected")
	}
	if sups := e.suppressions(s.Addr); len(sups) != 1 || sups[0]["reason"] != "hard_bounce" {
		t.Fatalf("resolution must apply the suppression policy: %+v", sups)
	}
	if e.count(`SELECT count(*) FROM unmatched_dsns WHERE id = $1`, row) != 1 {
		t.Fatal("the unmatched row must be retained")
	}
	// Nothing more to do: retries create nothing.
	if n, _ := resolver.Pass(e.ctx); n != 0 || e.count(`SELECT count(*) FROM message_events WHERE source_event_key = $1`, row) != 1 {
		t.Fatal("resolution repeated")
	}

	// A DSN that reports no failure for the requested message returns to open with the reason.
	r2 := e.sendTo(e.client, e.domain, "noresolve-"+tag()+"@rcpt.test")[0]
	key = e.deliver(p, "arf-not-spam.eml", only(vars(r2)))
	e.pass(p)
	row2 := e.str(`SELECT id::text FROM unmatched_dsns WHERE spool_ingest_key = $1`, key)
	e.requestMatch(row2, r2.ID, op)
	if n, _ := resolver.Pass(e.ctx); n != 1 {
		t.Fatal("request not processed")
	}
	if e.str(`SELECT status FROM unmatched_dsns WHERE id = $1`, row2) != "open" ||
		e.count(`SELECT jsonb_array_length(detail_json->'resolution_failures') FROM unmatched_dsns WHERE id = $1`, row2) != 1 ||
		e.count(`SELECT count(*) FROM message_events WHERE message_id = $1 AND event_source = 'unmatched_dsn_resolution'`, r2.ID) != 0 {
		t.Fatal("an uninterpretable resolution must return to open with the reason and no event")
	}
}

func TestEveryActiveSuppressionReasonStopsSubmission(t *testing.T) {
	e := newEnv(t)
	bClient, bDomain := e.otherClient()
	reasons := map[string]string{
		"hard_bounce":              `INSERT INTO suppressions (id, address_or_domain, scope_type, reason) VALUES ($1, $2, 'address', 'hard_bounce')`,
		"complaint":                `INSERT INTO suppressions (id, address_or_domain, scope_type, reason) VALUES ($1, $2, 'address', 'complaint')`,
		"repeated_soft_bounce":     `INSERT INTO suppressions (id, address_or_domain, scope_type, reason, expires_at) VALUES ($1, $2, 'address', 'repeated_soft_bounce', now() + interval '1 day')`,
		"operator_block":           `INSERT INTO suppressions (id, address_or_domain, scope_type, reason) VALUES ($1, $2, 'address', 'operator_block')`,
		"client_abuse_block":       `INSERT INTO suppressions (id, address_or_domain, scope_type, reason) VALUES ($1, $2, 'address', 'client_abuse_block')`,
		"recipient_global_opt_out": `INSERT INTO suppressions (id, address_or_domain, scope_type, reason, source_client_id, idempotency_key, request_hash) VALUES ($1, $2, 'address', 'recipient_global_opt_out', '` + e.client + `', gen_random_uuid()::text, 'h')`,
	}
	before := len(e.smtp.Messages())
	for reason, sql := range reasons {
		addr := "every-" + strings.ReplaceAll(reason, "_", "-") + "-" + tag() + "@rcpt.test"
		e.exec(sql, ids.UUIDv7(), addr)
		m := e.sendTo(bClient, bDomain, addr)[0]
		if st := e.str(`SELECT current_status FROM messages WHERE id = $1`, m.ID); st != "suppressed" {
			t.Errorf("%s: client B message %s", reason, st)
		}
	}
	// A global operator domain block.
	dom := "blocked-" + tag() + ".test"
	e.exec(`INSERT INTO suppressions (id, address_or_domain, scope_type, reason) VALUES ($1, $2, 'domain', 'operator_block')`, ids.UUIDv7(), dom)
	if m := e.sendTo(bClient, bDomain, "anyone@"+dom)[0]; m.QID != "" {
		t.Error("global domain block not applied")
	}
	if len(e.smtp.Messages()) != before {
		t.Fatal("a suppressed recipient reached Postfix")
	}
	// Lifted and expired rows do not apply; lifting one of two rows keeps the other.
	addr := "lifted-" + tag() + "@rcpt.test"
	e.exec(`INSERT INTO suppressions (id, address_or_domain, scope_type, reason, lifted_at) VALUES ($1, $2, 'address', 'hard_bounce', now())`, ids.UUIDv7(), addr)
	e.exec(`INSERT INTO suppressions (id, address_or_domain, scope_type, reason, expires_at) VALUES ($1, $2, 'address', 'repeated_soft_bounce', now() - interval '1 second')`, ids.UUIDv7(), addr)
	if m := e.sendTo(bClient, bDomain, addr)[0]; m.QID == "" {
		t.Error("lifted/expired suppressions must not apply")
	}
	both := "both-" + tag() + "@rcpt.test"
	optOut := ids.UUIDv7()
	e.exec(reasons["recipient_global_opt_out"], optOut, both)
	e.exec(reasons["hard_bounce"], ids.UUIDv7(), both)
	e.exec(`UPDATE suppressions SET lifted_at = now() WHERE id = $1`, optOut)
	if m := e.sendTo(bClient, bDomain, both)[0]; m.QID != "" {
		t.Error("lifting the opt-out must not override the independent hard bounce")
	}
	// A client-scoped row of client A does not apply to client B.
	scoped := "scoped-" + tag() + "@rcpt.test"
	e.exec(`INSERT INTO suppressions (id, client_id, address_or_domain, scope_type, reason) VALUES ($1, $3, $2, 'address', 'operator_block')`, ids.UUIDv7(), scoped, e.client)
	if m := e.sendTo(bClient, bDomain, scoped)[0]; m.QID == "" {
		t.Error("a client-scoped suppression must not apply to another client")
	}
}

// Inverse order: the job is staged and its messages are queued in Go before the
// suppression exists; the re-check immediately before submission stops it.
func TestSuppressionCreatedAfterStagingStopsSubmission(t *testing.T) {
	e := newEnv(t)
	addr := "staged-" + tag() + "@rcpt.test"
	job := e.newJob(jobOpts{}, []rcpt{{addr: addr}, {addr: "unaffected-" + tag() + "@rcpt.test"}})
	w := e.worker("staged")
	w.Lim.PauseAll(3 * time.Second) // hold submissions after expansion
	j := e.claim(w)
	done := make(chan struct{})
	go func() { w.ProcessJob(e.ctx, j); close(done) }()
	deadline := time.Now().Add(10 * time.Second)
	for e.count(`SELECT count(*) FROM messages WHERE send_job_id = $1 AND current_status = 'queued'`, job) < 2 && time.Now().Before(deadline) {
		time.Sleep(50 * time.Millisecond)
	}
	// Another client's trusted opt-out arrives while the messages wait for a slot.
	other, _ := e.otherClient()
	e.exec(`INSERT INTO suppressions (id, address_or_domain, scope_type, reason, source_client_id, idempotency_key, request_hash)
VALUES ($1, $2, 'address', 'recipient_global_opt_out', $3, gen_random_uuid()::text, 'h')`, ids.UUIDv7(), addr, other)
	<-done
	if st := e.str(`SELECT current_status FROM messages WHERE send_job_id = $1 AND recipient_address = $2`, job, addr); st != "suppressed" {
		t.Fatalf("message %s (must be suppressed before submission)", st)
	}
	if e.str(`SELECT metadata_json->>'checked' FROM message_events ev JOIN messages m ON m.id = ev.message_id
  WHERE m.send_job_id = $1 AND m.recipient_address = $2 AND ev.event_type = 'message_suppressed'`, job, addr) != "before_submission" {
		t.Fatal("message_suppressed must record the pre-submission check")
	}
	if e.smtp.Accepted()[addr] != 0 {
		t.Fatal("the suppressed recipient reached Postfix")
	}
	if e.jobStatus(job) != "dispatched" {
		t.Fatalf("job %s", e.jobStatus(job))
	}
	e.summaryMatches(job)
}

type errorf string

func (e errorf) Error() string { return string(e) }
