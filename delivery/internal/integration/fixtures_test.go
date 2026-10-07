//go:build integration

// Package integration tests the delivery daemon against PostgreSQL with the
// real migrations and grants (infra/tests/phase4-test.sh runs it in the
// throwaway, network-less test pod). Fixtures are written as the schema owner;
// everything under test connects as smarthost_delivery. Submissions go to the
// in-process test SMTP server; Postfix logs and queue snapshots are fixture
// files in a temporary observability directory.
package integration

import (
	"context"
	"crypto/sha256"
	"crypto/tls"
	"encoding/hex"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/jackc/pgx/v5/pgxpool"

	"smarthost.local/delivery/internal/config"
	"smarthost.local/delivery/internal/ids"
	"smarthost.local/delivery/internal/logx"
	"smarthost.local/delivery/internal/store"
	"smarthost.local/delivery/internal/testsmtp"
	"smarthost.local/delivery/internal/worker"
)

const bounceDomain = "bounce.smarthost.test"

func dsn(user, password string) string {
	return fmt.Sprintf("host=%s port=%s dbname=%s sslmode=disable user=%s password=%s",
		os.Getenv("SMARTHOST_DB_HOST"), os.Getenv("SMARTHOST_DB_PORT"), os.Getenv("SMARTHOST_DB_NAME"), user, password)
}

var owner *pgxpool.Pool

func TestMain(m *testing.M) {
	if os.Getenv("SMARTHOST_DB_OWNER_PASSWORD") == "" {
		fmt.Println("integration: database environment missing; run infra/tests/phase4-test.sh")
		os.Exit(1)
	}
	var err error
	owner, err = pgxpool.New(context.Background(), dsn(os.Getenv("SMARTHOST_DB_OWNER_USER"), os.Getenv("SMARTHOST_DB_OWNER_PASSWORD")))
	if err != nil {
		panic(err)
	}
	code := m.Run()
	owner.Close()
	os.Exit(code)
}

// env is one test's world: a delivery store, a test submission server and an
// observability directory.
type env struct {
	t      *testing.T
	ctx    context.Context
	st     *store.Store
	smtp   *testsmtp.Server
	obs    string
	cfg    *config.Config
	client string
	domain string
	jobs   []string
}

func newEnv(t *testing.T) *env {
	t.Helper()
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Minute)
	t.Cleanup(cancel)
	st, err := store.New(ctx, dsn(os.Getenv("DELIVERY_DB_USER"), os.Getenv("DELIVERY_DB_PASSWORD")), 30)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(st.Close)
	srv, err := testsmtp.Start("submit", "secret")
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(srv.Close)
	obs := t.TempDir()
	_ = os.MkdirAll(filepath.Join(obs, "log"), 0o750)
	_ = os.MkdirAll(filepath.Join(obs, "queue"), 0o750)
	host, port, _ := strings.Cut(srv.Addr, ":")
	var p int
	fmt.Sscan(port, &p)
	cfg := &config.Config{Env: "test", PublicBaseURL: "https://smarthost.test", LogLevel: "warning", LogFormat: "json",
		BounceDomain: bounceDomain, VERPLocalPart: "bounce", VERPDelimiter: "+", SubmissionUser: "submit", SubmissionPassword: "secret",
		SubmissionHost: host, SubmissionPort: p, ObservabilityDir: obs, WorkerID: "it", PollInterval: time.Second,
		Lease: 20 * time.Second, GlobalConcurrency: 6, DomainConcurrency: 2, DomainRatePerMin: 6000, DeferralBackoff: 30 * time.Second,
		DSNNotify: "FAILURE,DELAY", DSNRet: "HDRS", FilePollInterval: time.Second, ReconcileInterval: time.Second,
		ReconcileGrace: 0, ReconcileMinSnaps: 2, SnapshotInterval: time.Minute}
	e := &env{t: t, ctx: ctx, st: st, smtp: srv, obs: obs, cfg: cfg}
	e.client = e.newClient("active")
	e.domain = e.newDomain(e.client, "verified")
	t.Cleanup(e.cleanup)
	return e
}

// worker returns a worker wired to the test server (TLS without
// verification, like the pod-internal hop) with fast ambiguity handling.
func (e *env) worker(id string) *worker.Worker {
	cfg := *e.cfg
	cfg.WorkerID = id
	level := "error"
	if os.Getenv("PHASE4_DEBUG") != "" {
		level = "debug"
	}
	log := logx.New(level, "json")
	w := worker.New(&cfg, e.st, log)
	w.SMTP.TLS = &tls.Config{InsecureSkipVerify: true} //nolint:gosec
	w.SettleDelay, w.AmbiguityDeadline = 200*time.Millisecond, 3*time.Second
	return w
}

func (e *env) exec(sql string, args ...any) {
	e.t.Helper()
	if _, err := owner.Exec(e.ctx, sql, args...); err != nil {
		e.t.Fatalf("%s: %v", sql, err)
	}
}

func (e *env) count(sql string, args ...any) int {
	e.t.Helper()
	var n int
	if err := owner.QueryRow(e.ctx, sql, args...).Scan(&n); err != nil {
		e.t.Fatalf("%s: %v", sql, err)
	}
	return n
}

func (e *env) str(sql string, args ...any) string {
	e.t.Helper()
	var s *string
	if err := owner.QueryRow(e.ctx, sql, args...).Scan(&s); err != nil {
		e.t.Fatalf("%s: %v", sql, err)
	}
	if s == nil {
		return ""
	}
	return *s
}

func (e *env) newClient(status string) string {
	id := ids.UUIDv7()
	e.exec(`INSERT INTO clients (id, company_name, contact_email, status, plan) VALUES ($1, 'Test', 'ops@client.test', $2, 'test')`, id, status)
	return id
}

func (e *env) newDomain(client, status string) string {
	id := ids.UUIDv7()
	dom := "send-" + id[24:] + ".test"
	var verified any
	if status == "verified" {
		verified = time.Now()
	}
	e.exec(`INSERT INTO sending_domains (id, client_id, domain, status, verification_token, verified_at, dkim_selector, dkim_status)
VALUES ($1, $2, $3, $4, 'abcdefghijklmnopqrstuvwxyz012345', $5, 'sel', 'active')`, id, client, dom, status, verified)
	return id
}

// recipient of a fixture job; content defaults are filled in.
type rcpt struct {
	addr, subject, html, text, unsub string
}

type jobOpts struct {
	client, domain string
	subscription   bool
	opens, clicks  bool
	replyTo        string
}

// newJob creates a sealed (queued) job with its recipients, as Symfony would.
func (e *env) newJob(o jobOpts, rs []rcpt) string {
	e.t.Helper()
	if o.client == "" {
		o.client, o.domain = e.client, e.domain
	}
	dom := e.str(`SELECT domain FROM sending_domains WHERE id = $1`, o.domain)
	id := ids.UUIDv7()
	class, listID := "transactional", any(nil)
	if o.subscription {
		class, listID = "subscription", "Weekly <weekly.client.test>"
	}
	var reply any
	if o.replyTo != "" {
		reply = o.replyTo
	}
	e.exec(`INSERT INTO send_jobs (id, client_id, external_reference, idempotency_key, request_hash, message_class, list_id,
  sending_domain_id, sender_email, sender_name, reply_to_email, track_opens, track_clicks, status, queued_at, total_recipients)
VALUES ($1, $2, 'ref', $11, 'h', $3, $4, $5, $6, 'Sender Name', $7, $8, $9, 'queued', now(), $10)`,
		id, o.client, class, listID, o.domain, "news@"+dom, reply, o.opens, o.clicks, len(rs), "key-"+id)
	for start := 0; start < len(rs); start += 500 {
		end := min(start+500, len(rs))
		batch := ids.UUIDv7()
		e.exec(`INSERT INTO send_job_recipient_batches (id, send_job_id, idempotency_key, request_hash, recipient_count) VALUES ($1, $2, $4, 'h', $3)`,
			batch, id, end-start, "key-"+batch)
		var rids, refs, addrs, norms, subjs, htmls, texts, unsubs, shas []string
		var bytes []int32
		for i, r := range rs[start:end] {
			if r.subject == "" {
				r.subject = fmt.Sprintf("Subject %d", start+i)
			}
			if r.html == "" && r.text == "" {
				r.text = fmt.Sprintf("Hello %d", start+i)
			}
			if o.subscription && r.unsub == "" {
				r.unsub = "https://client.test/unsubscribe/" + ids.TrackingToken()
			}
			at := strings.LastIndexByte(r.addr, '@')
			norm := r.addr[:at] + "@" + strings.ToLower(r.addr[at+1:])
			sum := sha256.Sum256([]byte(r.subject + r.html + r.text))
			rids, refs, addrs, norms = append(rids, ids.UUIDv7()), append(refs, fmt.Sprint("r", start+i)), append(addrs, r.addr), append(norms, norm)
			subjs, htmls, texts, unsubs = append(subjs, r.subject), append(htmls, r.html), append(texts, r.text), append(unsubs, r.unsub)
			shas, bytes = append(shas, hex.EncodeToString(sum[:])), append(bytes, int32(len(r.subject+r.html+r.text)))
		}
		e.exec(`INSERT INTO send_job_recipients (id, send_job_id, batch_id, external_recipient_reference, email_address, normalized_address,
  subject, html_body, text_body, unsubscribe_url, content_bytes, content_sha256)
SELECT u.id::uuid, $1, $2, u.ref, u.addr, u.norm, u.subj, NULLIF(u.html, ''), NULLIF(u.txt, ''), NULLIF(u.unsub, ''), u.b, u.sha
  FROM unnest($3::text[], $4::text[], $5::text[], $6::text[], $7::text[], $8::text[], $9::text[], $10::text[], $11::int[], $12::text[])
       AS u(id, ref, addr, norm, subj, html, txt, unsub, b, sha)`, id, batch, rids, refs, addrs, norms, subjs, htmls, texts, unsubs, bytes, shas)
	}
	e.jobs = append(e.jobs, id)
	return id
}

func (e *env) rcpts(n int, domains ...string) []rcpt {
	if len(domains) == 0 {
		domains = []string{"rcpt.test"}
	}
	out := make([]rcpt, n)
	for i := range out {
		out[i] = rcpt{addr: fmt.Sprintf("user%05d@%s", i, domains[i%len(domains)])}
	}
	return out
}

// cleanup cancels this test's unfinished jobs so later tests never claim them.
func (e *env) cleanup() {
	_, _ = owner.Exec(context.Background(), `UPDATE send_jobs SET status = 'cancelled', lease_expires_at = NULL
 WHERE id = ANY($1::text[]::uuid[]) AND status IN ('queued', 'processing')`, e.jobs)
}

// claim claims and returns this test's job (other tests' jobs are cancelled).
func (e *env) claim(w *worker.Worker) *store.Job {
	e.t.Helper()
	j, err := e.st.Claim(e.ctx, w.Me, w.Cfg.Lease)
	if err != nil || j == nil {
		e.t.Fatalf("claim: %v %v", j, err)
	}
	return j
}

func (e *env) jobStatus(id string) string {
	return e.str(`SELECT status FROM send_jobs WHERE id = $1`, id)
}

// summaryMatches checks summary_counts_json against the real statuses.
func (e *env) summaryMatches(jobID string) {
	e.t.Helper()
	rows, err := owner.Query(e.ctx, `
SELECT s.key, s.value::int, COALESCE((SELECT count(*) FROM messages m WHERE m.send_job_id = $1 AND m.current_status = s.key), 0)::int
  FROM send_jobs j, jsonb_each_text(j.summary_counts_json) s WHERE j.id = $1`, jobID)
	if err != nil {
		e.t.Fatal(err)
	}
	defer rows.Close()
	total := 0
	for rows.Next() {
		var k string
		var stored, real int
		_ = rows.Scan(&k, &stored, &real)
		if stored != real {
			e.t.Errorf("summary_counts[%s] = %d, real %d", k, stored, real)
		}
		total += stored
	}
	if n := e.count(`SELECT count(*) FROM messages WHERE send_job_id = $1`, jobID); total != n {
		e.t.Errorf("summary total %d != %d messages", total, n)
	}
}

// writeLog appends Postfix log records (syslog format, UTC) to the active log.
func (e *env) writeLog(lines ...string) {
	f, err := os.OpenFile(filepath.Join(e.obs, "log", "postfix.log"), os.O_CREATE|os.O_APPEND|os.O_WRONLY, 0o640)
	if err != nil {
		e.t.Fatal(err)
	}
	defer f.Close()
	for _, l := range lines {
		fmt.Fprintln(f, l)
	}
}

func logTime(t time.Time) string { return t.UTC().Format("Jan 02 15:04:05") }

func (e *env) snapshot(at time.Time, qids ...string) {
	var b strings.Builder
	for _, q := range qids {
		fmt.Fprintf(&b, `{"queue_name":"deferred","queue_id":%q,"recipients":[{"address":"x@y"}]}`+"\n", q)
	}
	name := filepath.Join(e.obs, "queue", "snapshot-"+at.UTC().Format("20060102T150405Z")+".jsonl")
	if err := os.WriteFile(name, []byte(b.String()), 0o640); err != nil {
		e.t.Fatal(err)
	}
}
