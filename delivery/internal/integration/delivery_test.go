//go:build integration

package integration

import (
	"bytes"
	"context"
	"io"
	"mime"
	"mime/multipart"
	"mime/quotedprintable"
	"net/mail"
	"os"
	"strings"
	"sync"
	"testing"
	"time"

	"smarthost.local/delivery/internal/ingest"
	"smarthost.local/delivery/internal/logx"
	"smarthost.local/delivery/internal/reconcile"
	"smarthost.local/delivery/internal/store"
	"smarthost.local/delivery/internal/testsmtp"
)

func TestClaimIsExclusiveAndSkipsSuspendedClients(t *testing.T) {
	e := newEnv(t)
	suspended := e.newClient("suspended")
	pending := e.newClient("pending_approval")
	e.newJob(jobOpts{client: suspended, domain: e.newDomain(suspended, "verified")}, e.rcpts(1))
	e.newJob(jobOpts{client: pending, domain: e.newDomain(pending, "verified")}, e.rcpts(1))
	job := e.newJob(jobOpts{}, e.rcpts(1))
	var mu sync.Mutex
	var got []string
	var wg sync.WaitGroup
	for i := 0; i < 8; i++ {
		wg.Add(1)
		go func(i int) {
			defer wg.Done()
			j, err := e.st.Claim(e.ctx, "w"+string(rune('a'+i)), 30*time.Second)
			if err != nil {
				t.Error(err)
			}
			if j != nil {
				mu.Lock()
				got = append(got, j.ID)
				mu.Unlock()
			}
		}(i)
	}
	wg.Wait()
	if len(got) != 1 || got[0] != job {
		t.Fatalf("claimed %v, want exactly [%s] (suspended and pending-approval clients never claimed)", got, job)
	}
	if e.str(`SELECT status FROM send_jobs WHERE id = $1`, job) != "processing" || e.count(`SELECT attempt_count FROM send_jobs WHERE id = $1`, job) != 1 {
		t.Fatal("claim did not move the job to processing")
	}
}

func TestLeaseRenewalExpiryReclaimAndFencing(t *testing.T) {
	e := newEnv(t)
	job := e.newJob(jobOpts{}, e.rcpts(3))
	a := e.worker("a")
	a.Cfg.Lease = 2 * time.Second
	j, err := e.st.Claim(e.ctx, a.Me, 2*time.Second)
	if err != nil || j == nil {
		t.Fatal(err)
	}
	if _, ok, _ := e.st.Renew(e.ctx, job, a.Me, 2*time.Second); !ok {
		t.Fatal("renewal failed")
	}
	if b, _ := e.st.Claim(e.ctx, "b/1", 30*time.Second); b != nil {
		t.Fatal("a live lease was reclaimed")
	}
	time.Sleep(2500 * time.Millisecond)
	if _, ok, _ := e.st.Renew(e.ctx, job, a.Me, 2*time.Second); ok {
		t.Fatal("an expired lease was renewed")
	}
	b, _ := e.st.Claim(e.ctx, "b/1", 30*time.Second)
	if b == nil || b.ID != job || b.AttemptCount != 2 {
		t.Fatalf("reclaim after expiry: %+v", b)
	}
	// The stale worker's writes are fenced.
	if _, err := e.st.CreateMessages(e.ctx, j, a.Me, []store.NewMessage{{}}); err != store.ErrLeaseLost {
		t.Fatalf("stale CreateMessages: %v", err)
	}
	if err := e.st.FailJob(e.ctx, j, a.Me, "stale"); err != store.ErrLeaseLost {
		t.Fatalf("stale FailJob: %v", err)
	}
	// Suspension during the lease stops renewal and every write.
	e.exec(`UPDATE clients SET status = 'suspended' WHERE id = $1`, e.client)
	if _, ok, _ := e.st.Renew(e.ctx, job, "b/1", 30*time.Second); ok {
		t.Fatal("lease renewed for a suspended client")
	}
	if _, err := e.st.CreateMessages(e.ctx, b, "b/1", []store.NewMessage{{}}); err != store.ErrLeaseLost {
		t.Fatalf("write for a suspended client: %v", err)
	}
}

func TestEndToEndWithSubmissionOutcomes(t *testing.T) {
	e := newEnv(t)
	rs := e.rcpts(40, "a.test", "b.test", "c.test")
	rs = append(rs,
		rcpt{addr: testsmtp.RejectRcpt + "1@a.test"},
		rcpt{addr: testsmtp.RejectData + "1@b.test"},
		rcpt{addr: testsmtp.OnceTempfail + "1@c.test"},
		rcpt{addr: testsmtp.TempfailRcpt + "1@d.test"}, // stays temporary: never failed, never metered
	)
	job := e.newJob(jobOpts{}, rs)
	w := e.worker("w")
	j := e.claim(w)
	done := make(chan struct{})
	ctx, cancel := context.WithCancel(e.ctx)
	go func() { w.ProcessJob(ctx, j); close(done) }()
	// Wait until everything but the permanently-tempfailing recipient is resolved.
	deadline := time.Now().Add(60 * time.Second)
	for time.Now().Before(deadline) && e.count(`SELECT count(*) FROM messages WHERE send_job_id = $1 AND current_status <> 'queued'`, job) < len(rs)-1 {
		time.Sleep(200 * time.Millisecond)
	}
	cancel()
	<-done

	if n := e.count(`SELECT count(*) FROM messages WHERE send_job_id = $1`, job); n != len(rs) {
		t.Fatalf("%d messages for %d recipients", n, len(rs))
	}
	if n := e.count(`SELECT count(*) FROM messages WHERE send_job_id = $1 AND current_status = 'submitted' AND postfix_queue_id IS NOT NULL`, job); n != 41 {
		t.Errorf("submitted %d, want 41 (40 + once-tempfail after retry)", n)
	}
	if n := e.count(`SELECT count(*) FROM messages WHERE send_job_id = $1 AND current_status = 'failed'`, job); n != 2 {
		t.Errorf("failed %d, want 2 (RCPT 5xx, end-of-data 5xx)", n)
	}
	// purge only with recorded acceptance; content of failed/queued recipients kept
	if n := e.count(`SELECT count(*) FROM send_job_recipients r JOIN messages m ON m.send_job_recipient_id = r.id
  WHERE m.send_job_id = $1 AND (r.content_purged_at IS NOT NULL) <> (m.postfix_queue_id IS NOT NULL)`, job); n != 0 {
		t.Errorf("%d recipients whose purge state disagrees with Postfix acceptance", n)
	}
	if n := e.count(`SELECT count(*) FROM send_job_recipients r JOIN messages m ON m.send_job_recipient_id = r.id
  WHERE m.send_job_id = $1 AND r.content_purged_at IS NOT NULL AND (r.subject IS NOT NULL OR r.text_body IS NOT NULL OR r.html_body IS NOT NULL OR r.content_sha256 IS NULL)`, job); n != 0 {
		t.Errorf("%d purged recipients still hold content or lost their hash", n)
	}
	// message_submitted usage: exactly one per accepted message
	if n := e.count(`SELECT count(*) FROM usage_records u JOIN messages m ON m.id = u.reference_id WHERE m.send_job_id = $1 AND u.usage_type = 'message_submitted'`, job); n != 41 {
		t.Errorf("usage rows %d, want 41", n)
	}
	if n := e.count(`SELECT count(*) FROM (SELECT reference_id FROM usage_records GROUP BY reference_id HAVING sum(quantity) > 1) x`); n != 0 {
		t.Errorf("%d messages metered more than once", n)
	}
	// the server accepted each accepted recipient exactly once (no duplicate submission)
	for to, n := range e.smtp.Accepted() {
		if n != 1 {
			t.Errorf("%s accepted %d times", to, n)
		}
	}
	// events
	if n := e.count(`SELECT count(*) FROM message_events ev JOIN messages m ON m.id = ev.message_id
  WHERE m.send_job_id = $1 AND ev.event_type = 'submitted_to_postfix' AND ev.event_source = 'postfix_submission'
    AND ev.source_event_key = m.id::text || ':' || m.postfix_queue_id`, job); n != 41 {
		t.Errorf("submitted_to_postfix events %d", n)
	}
	if n := e.count(`SELECT count(*) FROM message_events ev JOIN messages m ON m.id = ev.message_id
  WHERE m.send_job_id = $1 AND ev.event_type IN ('message_created', 'message_queued')`, job); n != 2*len(rs) {
		t.Errorf("lifecycle events %d", n)
	}
	if code := e.count(`SELECT ev.smtp_code FROM message_events ev JOIN messages m ON m.id = ev.message_id
  WHERE m.recipient_address = $1 AND ev.event_type = 'submission_failed'`, testsmtp.RejectRcpt+"1@a.test"); code != 550 {
		t.Errorf("submission_failed smtp_code %d", code)
	}
	tempfail := e.str(`SELECT current_status FROM messages WHERE recipient_address = $1`, testsmtp.TempfailRcpt+"1@d.test")
	if tempfail != "queued" {
		t.Errorf("temporarily failing message is %s (must stay queued, content kept, unmetered)", tempfail)
	}
	if e.jobStatus(job) != "processing" {
		t.Errorf("job with a pending retry is %s", e.jobStatus(job))
	}
	e.summaryMatches(job)
}

func TestExpansionIsExactlyOnceAcrossRestarts(t *testing.T) {
	e := newEnv(t)
	job := e.newJob(jobOpts{}, e.rcpts(1200))
	e.smtp.RefuseAll.Store(true) // nothing can be submitted: test expansion only
	for i := 0; i < 3; i++ {
		w := e.worker("x")
		w.Cfg.Lease = 2 * time.Second
		j, err := e.st.Claim(e.ctx, w.Me, 2*time.Second)
		if err != nil || j == nil {
			t.Fatalf("round %d claim: %v", i, err)
		}
		ctx, cancel := context.WithTimeout(e.ctx, 1500*time.Millisecond)
		w.ProcessJob(ctx, j) // stops when the context ends (simulated crash)
		cancel()
		time.Sleep(2100 * time.Millisecond) // let the lease expire
	}
	if n := e.count(`SELECT count(*) FROM messages WHERE send_job_id = $1`, job); n != 1200 {
		t.Fatalf("%d messages for 1200 recipients", n)
	}
	if n := e.count(`SELECT count(*) FROM (SELECT send_job_recipient_id FROM messages WHERE send_job_id = $1 GROUP BY 1 HAVING count(*) > 1) x`, job); n != 0 {
		t.Fatal("a recipient was expanded twice")
	}
	if n := e.count(`SELECT count(*) FROM message_events ev JOIN messages m ON m.id = ev.message_id WHERE m.send_job_id = $1 AND ev.event_type = 'message_created'`, job); n != 1200 {
		t.Fatalf("message_created events %d", n)
	}
	if n := e.count(`SELECT count(DISTINCT verp_token) FROM messages WHERE send_job_id = $1`, job); n != 1200 {
		t.Fatal("VERP tokens not unique")
	}
	if e.count(`SELECT attempt_count FROM send_jobs WHERE id = $1`, job) != 3 {
		t.Fatal("attempt_count")
	}
	e.summaryMatches(job)
}

func TestSuppressionsAreHonoured(t *testing.T) {
	e := newEnv(t)
	other := e.newClient("active")
	e.exec(`INSERT INTO suppressions (id, client_id, address_or_domain, scope_type, reason) VALUES
 (gen_random_uuid(), $1, 'Blocked@rcpt.test', 'address', 'hard_bounce'),
 (gen_random_uuid(), NULL, 'blocked-domain.test', 'domain', 'operator_block'),
 (gen_random_uuid(), $2, 'other-client@rcpt.test', 'address', 'complaint')`, e.client, other)
	e.exec(`INSERT INTO suppressions (id, client_id, address_or_domain, scope_type, reason, expires_at) VALUES (gen_random_uuid(), NULL, 'expired@rcpt.test', 'address', 'repeated_soft_bounce', now() - interval '1 day')`)
	e.exec(`INSERT INTO suppressions (id, client_id, address_or_domain, scope_type, reason, lifted_at) VALUES (gen_random_uuid(), NULL, 'lifted@rcpt.test', 'address', 'hard_bounce', now())`)
	job := e.newJob(jobOpts{}, []rcpt{{addr: "Blocked@RCPT.test"}, {addr: "blocked@rcpt.test"}, {addr: "x@blocked-domain.test"},
		{addr: "other-client@rcpt.test"}, {addr: "expired@rcpt.test"}, {addr: "lifted@rcpt.test"}})
	w := e.worker("s")
	j := e.claim(w)
	w.ProcessJob(e.ctx, j)
	suppressed := map[string]bool{}
	rows, _ := owner.Query(e.ctx, `SELECT recipient_address FROM messages WHERE send_job_id = $1 AND current_status = 'suppressed'`, job)
	for rows.Next() {
		var a string
		_ = rows.Scan(&a)
		suppressed[a] = true
	}
	rows.Close()
	// D-18: the local part is case-sensitive, so blocked@ (lower case) is not suppressed.
	if len(suppressed) != 2 || !suppressed["Blocked@rcpt.test"] || !suppressed["x@blocked-domain.test"] {
		t.Fatalf("suppressed %v", suppressed)
	}
	for _, m := range e.smtp.Messages() {
		if suppressed[m.To] {
			t.Errorf("suppressed %s was submitted", m.To)
		}
	}
	if n := e.count(`SELECT count(*) FROM message_events ev JOIN messages m ON m.id = ev.message_id
 WHERE m.send_job_id = $1 AND ev.event_type = 'message_suppressed' AND ev.metadata_json ? 'suppression_id'`, job); n != 2 {
		t.Errorf("message_suppressed events %d", n)
	}
	if e.count(`SELECT count(*) FROM suppressions`) != 5 {
		t.Error("Phase 4 must not create suppressions")
	}
	if e.jobStatus(job) != "dispatched" {
		t.Fatalf("job %s", e.jobStatus(job))
	}
	e.summaryMatches(job)
}

func TestHeadersTrackingAndVERP(t *testing.T) {
	e := newEnv(t)
	html := `<html><body><a href="https://shop.test/x">x</a> <a href="mailto:a@b.test">m</a> <a href="UNSUB">u</a></body></html>`
	rs := []rcpt{{addr: "Reader@News.test", subject: "Grüße", html: html, text: "text https://shop.test/plain"}}
	job := e.newJob(jobOpts{subscription: true, opens: true, clicks: true, replyTo: "desk@client.test"}, rs)
	unsub := e.str(`SELECT unsubscribe_url FROM send_job_recipients WHERE send_job_id = $1`, job)
	e.exec(`UPDATE send_job_recipients SET html_body = replace(html_body, 'UNSUB', unsubscribe_url) WHERE send_job_id = $1`, job)
	w := e.worker("h")
	j := e.claim(w)
	w.ProcessJob(e.ctx, j)
	msgs := e.smtp.Messages()
	if len(msgs) != 1 {
		t.Fatalf("%d messages", len(msgs))
	}
	m := msgs[0]
	mid := e.str(`SELECT id::text FROM messages WHERE send_job_id = $1`, job)
	verp := e.str(`SELECT verp_token FROM messages WHERE id = $1`, mid)
	token := e.str(`SELECT tracking_token FROM messages WHERE id = $1`, mid)
	if m.From != "bounce+"+verp+"@"+bounceDomain || m.To != "Reader@news.test" {
		t.Fatalf("envelope %s -> %s", m.From, m.To)
	}
	if !strings.Contains(m.MailParams, "ENVID="+mid) {
		t.Errorf("ENVID %q", m.MailParams)
	}
	msg, err := mail.ReadMessage(bytes.NewReader(m.Data))
	if err != nil {
		t.Fatal(err)
	}
	h := msg.Header
	dec := new(mime.WordDecoder)
	subj, _ := dec.DecodeHeader(h.Get("Subject"))
	if h.Get("Message-ID") != "<"+mid+"@"+bounceDomain+">" || h.Get("X-Smarthost-Message-ID") != mid || subj != "Grüße" ||
		h.Get("List-Unsubscribe") != "<"+unsub+">" || h.Get("List-Unsubscribe-Post") != "List-Unsubscribe=One-Click" ||
		h.Get("List-Id") != "Weekly <weekly.client.test>" || !strings.Contains(h.Get("Reply-To"), "desk@client.test") {
		t.Fatalf("headers %v", h)
	}
	_, params, _ := mime.ParseMediaType(h.Get("Content-Type"))
	mr := multipart.NewReader(msg.Body, params["boundary"])
	var parts []string
	for {
		p, err := mr.NextRawPart()
		if err != nil {
			break
		}
		b, _ := io.ReadAll(quotedprintable.NewReader(p))
		parts = append(parts, string(b))
	}
	if len(parts) != 2 || parts[0] != "text https://shop.test/plain\r\n" {
		t.Fatalf("text part changed: %q", parts)
	}
	htmlOut := parts[1]
	if !strings.Contains(htmlOut, "https://smarthost.test/t/o/"+token+".gif") || !strings.Contains(htmlOut, "https://smarthost.test/t/c/"+token+"/1") ||
		strings.Contains(htmlOut, "shop.test/x") || !strings.Contains(htmlOut, "mailto:a@b.test") || !strings.Contains(htmlOut, unsub) {
		t.Fatalf("html instrumentation: %s", htmlOut)
	}
	if e.str(`SELECT target_url FROM message_links WHERE message_id = $1 AND link_index = 1`, mid) != "https://shop.test/x" ||
		e.count(`SELECT count(*) FROM message_links WHERE message_id = $1`, mid) != 1 {
		t.Fatal("message_links")
	}
}

// Phase 6: a text-only message of a tracked job carries no pixel and no rewritten
// link, so its tracking token never appears in any mail and no link is mapped.
func TestTextOnlyMessageOfTrackedJobIsNotInstrumented(t *testing.T) {
	e := newEnv(t)
	job := e.newJob(jobOpts{opens: true, clicks: true}, []rcpt{{addr: "plain@text.test", text: "Visit https://shop.test/plain today"}})
	w := e.worker("txt")
	w.ProcessJob(e.ctx, e.claim(w))
	msgs := e.smtp.Messages()
	if len(msgs) != 1 {
		t.Fatalf("%d messages", len(msgs))
	}
	mid := e.str(`SELECT id::text FROM messages WHERE send_job_id = $1`, job)
	token := e.str(`SELECT COALESCE(tracking_token, '') FROM messages WHERE id = $1`, mid)
	body := string(msgs[0].Data)
	if strings.Contains(body, "/t/o/") || strings.Contains(body, "/t/c/") || (token != "" && strings.Contains(body, token)) {
		t.Fatalf("text-only message was instrumented: %s", body)
	}
	if !strings.Contains(body, "https://shop.test/plain") {
		t.Fatalf("text part changed: %s", body)
	}
	if e.count(`SELECT count(*) FROM message_links WHERE message_id = $1`, mid) != 0 {
		t.Fatal("a text-only message has no link mapping")
	}
}

func TestAmbiguousSubmissionResolvedFromLogWithoutResubmission(t *testing.T) {
	e := newEnv(t)
	job := e.newJob(jobOpts{}, []rcpt{{addr: testsmtp.DropAfterData + "1@x.test"}})
	w := e.worker("amb")
	j := e.claim(w)
	go func() {
		// Postfix did queue it: its log shows the cleanup and queue-manager records.
		time.Sleep(500 * time.Millisecond)
		mid := e.str(`SELECT id::text FROM messages WHERE send_job_id = $1`, job)
		now := time.Now()
		e.writeLog(
			logTime(now)+" pf postfix/cleanup[1]: 4AMBIGUOUS01: message-id=<"+mid+"@"+bounceDomain+">",
			logTime(now)+" pf postfix/qmgr[2]: 4AMBIGUOUS01: from=<x@"+bounceDomain+">, size=10, nrcpt=1 (queue active)")
	}()
	w.ProcessJob(e.ctx, j)
	if attempts := len(e.smtp.Messages()); attempts != 1 {
		t.Fatalf("message sent %d times (an ambiguous submission must not be resubmitted)", attempts)
	}
	if q := e.str(`SELECT postfix_queue_id FROM messages WHERE send_job_id = $1`, job); q != "4AMBIGUOUS01" {
		t.Fatalf("queue id %q", q)
	}
	if e.count(`SELECT count(*) FROM message_events ev JOIN messages m ON m.id = ev.message_id WHERE m.send_job_id = $1
  AND ev.event_type = 'submitted_to_postfix' AND ev.event_source = 'postfix_log'`, job) != 1 ||
		e.count(`SELECT count(*) FROM usage_records u JOIN messages m ON m.id = u.reference_id WHERE m.send_job_id = $1`, job) != 1 {
		t.Fatal("recovered acceptance not recorded exactly once")
	}
}

func TestCrashAfterAcceptanceIsRecoveredNotResubmitted(t *testing.T) {
	e := newEnv(t)
	job := e.newJob(jobOpts{}, e.rcpts(20, "a.test", "b.test"))
	// Worker A expands, then "dies" right after Postfix accepted message 0:
	a := e.worker("a")
	a.Cfg.Lease = 2 * time.Second
	ja, _ := e.st.Claim(e.ctx, a.Me, 2*time.Second)
	ctx, cancel := context.WithCancel(e.ctx)
	e.smtp.RefuseAll.Store(true)
	go func() { time.Sleep(700 * time.Millisecond); cancel() }()
	a.ProcessJob(ctx, ja)
	first := e.str(`SELECT id::text FROM messages WHERE send_job_id = $1 ORDER BY id LIMIT 1`, job)
	now := time.Now()
	e.writeLog(
		logTime(now)+" pf postfix/cleanup[1]: 4CRASHED0001: message-id=<"+first+"@"+bounceDomain+">",
		logTime(now)+" pf postfix/qmgr[2]: 4CRASHED0001: from=<x@"+bounceDomain+">, size=10, nrcpt=1 (queue active)")
	time.Sleep(2200 * time.Millisecond) // lease expires
	e.smtp.RefuseAll.Store(false)
	b := e.worker("b")
	jb := e.claim(b)
	if jb.AttemptCount != 2 {
		t.Fatal("not a reclaim")
	}
	b.ProcessJob(e.ctx, jb)
	if e.jobStatus(job) != "dispatched" {
		t.Fatalf("job %s", e.jobStatus(job))
	}
	firstAddr := e.str(`SELECT recipient_address FROM messages WHERE id = $1`, first)
	for _, m := range e.smtp.Messages() {
		if m.To == firstAddr {
			t.Fatal("the message Postfix had already accepted was submitted again")
		}
	}
	if len(e.smtp.Messages()) != 19 || e.count(`SELECT count(*) FROM usage_records u JOIN messages m ON m.id = u.reference_id WHERE m.send_job_id = $1`, job) != 20 {
		t.Fatalf("submissions %d, usage %d", len(e.smtp.Messages()), e.count(`SELECT count(*) FROM usage_records u JOIN messages m ON m.id = u.reference_id WHERE m.send_job_id = $1`, job))
	}
	e.summaryMatches(job)
}

func TestPacingLimitsHold(t *testing.T) {
	e := newEnv(t)
	job := e.newJob(jobOpts{}, e.rcpts(90, "big.test", "big.test", "big.test", "small.test", "other.test"))
	w := e.worker("p") // GlobalConcurrency 6, per-domain 2
	j := e.claim(w)
	w.ProcessJob(e.ctx, j)
	if e.jobStatus(job) != "dispatched" {
		t.Fatalf("job %s", e.jobStatus(job))
	}
	if w.Lim.MaxGlobal > e.cfg.GlobalConcurrency || w.Lim.MaxDomain > e.cfg.DomainConcurrency {
		t.Fatalf("limits exceeded: global %d/%d, domain %d/%d", w.Lim.MaxGlobal, e.cfg.GlobalConcurrency, w.Lim.MaxDomain, e.cfg.DomainConcurrency)
	}
	if w.Lim.MaxGlobal < 3 {
		t.Errorf("no concurrency reached (%d)", w.Lim.MaxGlobal)
	}
}

func TestMilterTempfailRetriesWithoutPurgeOrUsage(t *testing.T) {
	e := newEnv(t)
	job := e.newJob(jobOpts{}, []rcpt{{addr: testsmtp.Milter + "1@x.test"}})
	w := e.worker("m")
	j := e.claim(w)
	ctx, cancel := context.WithTimeout(e.ctx, 7*time.Second)
	w.ProcessJob(ctx, j)
	cancel()
	if n := len(e.smtp.Messages()); n < 2 {
		t.Fatalf("milter tempfail not retried (%d attempts)", n)
	}
	if e.str(`SELECT m.current_status FROM messages m WHERE m.send_job_id = $1`, job) != "queued" ||
		e.count(`SELECT count(*) FROM send_job_recipients WHERE send_job_id = $1 AND content_purged_at IS NULL AND subject IS NOT NULL`, job) != 1 ||
		e.count(`SELECT count(*) FROM usage_records u JOIN messages m ON m.id = u.reference_id WHERE m.send_job_id = $1`, job) != 0 {
		t.Fatal("a tempfailed message must stay queued with its content and without usage")
	}
}

func TestDomainGateFailsJob(t *testing.T) {
	e := newEnv(t)
	d := e.newDomain(e.client, "pending")
	job := e.newJob(jobOpts{client: e.client, domain: d}, e.rcpts(2))
	w := e.worker("g")
	j := e.claim(w)
	w.ProcessJob(e.ctx, j)
	if e.jobStatus(job) != "failed" || e.count(`SELECT count(*) FROM webhook_events WHERE subject_id = $1 AND event_type = 'send.failed'`, job) != 1 ||
		e.count(`SELECT count(*) FROM messages WHERE send_job_id = $1`, job) != 0 {
		t.Fatal("unverified sending domain must fail the job with send.failed and no messages")
	}
}

// submittedJob runs a job to dispatch and returns message id -> queue id.
func (e *env) submittedJob(n int) (string, map[string]string) {
	job := e.newJob(jobOpts{}, e.rcpts(n))
	w := e.worker("s")
	j := e.claim(w)
	w.ProcessJob(e.ctx, j)
	out := map[string]string{}
	rows, _ := owner.Query(e.ctx, `SELECT id::text, postfix_queue_id FROM messages WHERE send_job_id = $1 ORDER BY id`, job)
	for rows.Next() {
		var id, q string
		_ = rows.Scan(&id, &q)
		out[id] = q
	}
	rows.Close()
	return job, out
}

func ingester(e *env) *ingest.Ingester {
	return &ingest.Ingester{Store: e.st, Log: logx.New("error", "json"), Dir: e.obs + "/log", BounceDomain: bounceDomain, Interval: time.Second}
}

func TestLogIngestionCursorRotationReplayAndCompletion(t *testing.T) {
	e := newEnv(t)
	e.exec(`DELETE FROM delivery_ingest_cursors`)
	job, qids := e.submittedJob(3)
	var ms []string
	for m := range qids {
		ms = append(ms, m)
	}
	q := func(i int) string { return qids[ms[i]] }
	now := time.Now()
	ts := logTime(now)
	e.writeLog(
		ts+" pf postfix/postlog: starting the Postfix mail system",
		ts+" pf postfix/qmgr[2]: "+q(0)+": from=<b@"+bounceDomain+">, size=10, nrcpt=1 (queue active)",
		ts+" pf postfix/smtp[3]: "+q(0)+": to=<x@rcpt.test>, relay=none, delay=0.1, delays=0/0/0/0, dsn=4.4.1, status=deferred (connect to mailpit[10.0.0.1]:1025: Connection refused)",
		ts+" pf postfix/qmgr[2]: "+q(0)+": from=<b@"+bounceDomain+">, size=10, nrcpt=1 (queue active)",
		ts+" pf postfix/smtp[3]: "+q(0)+": to=<x@rcpt.test>, relay=mailpit[10.0.0.1]:1025, delay=9, delays=9/0/0/0, dsn=2.0.0, status=sent (250 2.0.0 Ok: queued as AB)",
		ts+" pf postfix/smtp[3]: "+q(0)+": to=<x@rcpt.test>, relay=none, delay=0.1, delays=0/0/0/0, dsn=4.4.1, status=deferred (connect to mailpit[10.0.0.1]:1025: Connection refused)",
		ts+" pf postfix/qmgr[2]: UNRELATED001: from=<other@x>, size=10, nrcpt=1 (queue active)",
		ts+" pf postfix/smtp[3]: UNRELATED001: to=<a@b.test>, relay=x[1.2.3.4]:25, delay=1, delays=0/0/0/1, dsn=2.0.0, status=sent (250 ok)",
	)
	g := ingester(e)
	if err := g.Pass(e.ctx); err != nil {
		t.Fatal(err)
	}
	if os.Getenv("PHASE4_DEBUG") != "" {
		cur, _, _ := e.st.GetCursor(e.ctx)
		raw, _ := os.ReadFile(e.obs + "/log/postfix.log")
		t.Logf("cursor %+v qids %v\n%s", cur, qids, raw)
	}
	types := func(m string) string {
		rows, _ := owner.Query(e.ctx, `SELECT event_type FROM message_events WHERE message_id = $1 AND event_source = 'postfix_log' ORDER BY source_event_key`, m)
		defer rows.Close()
		var out []string
		for rows.Next() {
			var s string
			_ = rows.Scan(&s)
			out = append(out, s)
		}
		return strings.Join(out, ",")
	}
	got := types(ms[0])
	for _, want := range []string{"postfix_queued", "connection_failure", "delivery_attempt", "remote_accepted", "connection_failure"} {
		if !strings.Contains(got, want) {
			t.Errorf("events %s lack %s", got, want)
		}
	}
	// the late deferral did not regress the projection
	if st, _ := e.st.MessageStatus(e.ctx, ms[0]); st != "remote_accepted" {
		t.Fatalf("status %s", st)
	}
	if e.count(`SELECT count(*) FROM message_events WHERE metadata_json->>'postfix_queue_id' = 'UNRELATED001'`) != 0 {
		t.Fatal("unrelated Postfix activity created events")
	}
	before := e.count(`SELECT count(*) FROM message_events`)
	// replay: reset the cursor to 0 and ingest again: no duplicates
	e.exec(`UPDATE delivery_ingest_cursors SET position = 0`)
	_ = g.Pass(e.ctx)
	if after := e.count(`SELECT count(*) FROM message_events`); after != before {
		t.Fatalf("replay added %d events", after-before)
	}
	// partial record is not consumed; rotation (gzip) keeps the cursor exact
	path := e.obs + "/log/postfix.log"
	e.writeLog(ts + " pf postfix/smtp[3]: " + q(1) + ": to=<x@rcpt.test>, relay=mailpit[10.0.0.1]:1025, delay=1, delays=0/0/0/1, dsn=2.0.0, status=sent (250 ok)")
	appendRaw(t, path, ts+" pf postfix/smtp[3]: "+q(2)+": to=<x@rcpt.test>, relay=mailpit[10.0.0.1]:1025, delay=1, del")
	_ = g.Pass(e.ctx)
	if st, _ := e.st.MessageStatus(e.ctx, ms[2]); st != "submitted" {
		t.Fatal("a partial trailing record was consumed")
	}
	appendRaw(t, path, "ays=0/0/0/1, dsn=2.0.0, status=sent (250 ok)\n")
	rotateGzip(t, path, now)
	e.writeLog(ts + " pf postfix/postlog: reopening new generation")
	if err := g.Pass(e.ctx); err != nil {
		t.Fatal(err)
	}
	for _, m := range ms {
		if st, _ := e.st.MessageStatus(e.ctx, m); st != "remote_accepted" {
			t.Fatalf("after rotation %s is %s", m, st)
		}
	}
	if e.jobStatus(job) != "completed" || e.count(`SELECT count(*) FROM webhook_events WHERE subject_id = $1 AND event_type = 'send.completed'`, job) != 1 {
		t.Fatalf("job %s / send.completed outbox", e.jobStatus(job))
	}
	cur, _, _ := e.st.GetCursor(e.ctx)
	if cur.Position == 0 {
		t.Fatal("cursor not on the new generation's end")
	}
	e.summaryMatches(job)
}

func TestHoldRuleWaitsForInFlightSubmission(t *testing.T) {
	e := newEnv(t)
	e.exec(`DELETE FROM delivery_ingest_cursors`)
	job := e.newJob(jobOpts{}, e.rcpts(1))
	w := e.worker("hold")
	j := e.claim(w)
	e.smtp.RefuseAll.Store(true)
	ctx, cancel := context.WithTimeout(e.ctx, time.Second)
	w.ProcessJob(ctx, j) // expands; submission refused: queued without queue id
	cancel()
	mid := e.str(`SELECT id::text FROM messages WHERE send_job_id = $1`, job)
	ts := logTime(time.Now())
	first := ts + " pf postfix/qmgr[2]: OTHER000001: removed"
	e.writeLog(first,
		ts+" pf postfix/cleanup[1]: 4HOLD0000001: message-id=<"+mid+"@"+bounceDomain+">",
		ts+" pf postfix/qmgr[2]: 4HOLD0000001: from=<b@x>, size=1, nrcpt=1 (queue active)")
	_ = ingester(e).Pass(e.ctx)
	cur, _, _ := e.st.GetCursor(e.ctx)
	if cur.Position != int64(len(first)+1) {
		t.Fatalf("cursor %d, want %d: ingestion must stop exactly before the in-flight submission's cleanup record", cur.Position, len(first)+1)
	}
	// Once the hold window has passed, ingestion continues past it.
	e.exec(`UPDATE messages SET postfix_queue_id = '4HOLD0000001', current_status = 'submitted' WHERE id = $1`, mid)
	_ = ingester(e).Pass(e.ctx)
	if c2, _, _ := e.st.GetCursor(e.ctx); c2.Position <= cur.Position {
		t.Fatal("ingestion did not resume once the queue id was recorded")
	}
}

func TestReconciliationConcludesOnlyFromFreshAbsence(t *testing.T) {
	e := newEnv(t)
	job, qids := e.submittedJob(4)
	var ms []string
	for m := range qids {
		ms = append(ms, m)
	}
	// ms[0] still queued; ms[1] absent, no outcome anywhere; ms[2] absent but the
	// log has status=sent; ms[3] absent from only one fresh snapshot.
	// Snapshots must postdate the last event (+ grace 0): wait for the clock.
	time.Sleep(3 * time.Second)
	now := time.Now()
	r := &reconcile.Reconciler{Store: e.st, Log: logx.New("error", "json"), ObservabilityDir: e.obs, BounceDomain: bounceDomain,
		Interval: time.Second, Grace: 0, MinSnapshots: 2, SnapshotInterval: time.Minute}
	// stale snapshots only: no conclusion at all
	e.snapshot(now.Add(-time.Hour), qids[ms[0]])
	res, err := r.Pass(e.ctx)
	if err != nil || !res.Stale || res.OutcomeUnknown != 0 {
		t.Fatalf("stale: %+v %v", res, err)
	}
	e.writeLog(logTime(now) + " pf postfix/smtp[3]: " + qids[ms[2]] + ": to=<x@rcpt.test>, relay=mx[1.2.3.4]:25, delay=1, delays=0/0/0/1, dsn=2.0.0, status=sent (250 ok)")
	e.snapshot(now.Add(-2*time.Second), qids[ms[0]], qids[ms[3]])
	e.snapshot(now.Add(-1*time.Second), qids[ms[0]])
	e.snapshot(now, qids[ms[0]])
	// one absent snapshot for ms[3] is not enough; two for ms[1]/ms[2] are
	res, err = r.Pass(e.ctx)
	if err != nil {
		t.Fatal(err)
	}
	st := func(i int) string { s, _ := e.st.MessageStatus(e.ctx, ms[i]); return s }
	if st(0) != "submitted" || st(1) != "outcome_unknown" || st(2) != "remote_accepted" || st(3) != "submitted" {
		t.Fatalf("statuses %s %s %s %s (%+v)", st(0), st(1), st(2), st(3), res)
	}
	if e.count(`SELECT count(*) FROM message_events WHERE message_id = $1 AND event_type = 'transport_outcome_unknown'
  AND event_source = 'queue_reconciliation' AND source_event_key = $1::text || ':' || $2`, ms[1], qids[ms[1]]) != 1 {
		t.Fatal("transport_outcome_unknown event")
	}
	// a second pass adds nothing; a later authoritative event supersedes outcome_unknown
	_, _ = r.Pass(e.ctx)
	if e.count(`SELECT count(*) FROM message_events WHERE event_type = 'transport_outcome_unknown' AND message_id = $1`, ms[1]) != 1 {
		t.Fatal("duplicate outcome_unknown")
	}
	e.writeLog(logTime(now) + " pf postfix/smtp[3]: " + qids[ms[1]] + ": to=<x@rcpt.test>, relay=mx[1.2.3.4]:25, delay=1, delays=0/0/0/1, dsn=5.1.1, status=bounced (host mx said: 550 5.1.1 unknown)")
	e.exec(`DELETE FROM delivery_ingest_cursors`)
	_ = ingester(e).Pass(e.ctx)
	if st(1) != "hard_bounced" || e.count(`SELECT count(*) FROM webhook_events WHERE subject_id = $1 AND event_type = 'message.hard_bounced'`, ms[1]) != 1 {
		t.Fatalf("authoritative bounce must supersede outcome_unknown (status %s)", st(1))
	}
	if e.jobStatus(job) == "completed" {
		t.Fatal("job completed while messages are still in Postfix")
	}
	e.summaryMatches(job)
}
