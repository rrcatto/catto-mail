// Package store holds every SQL statement of the delivery daemon. It connects
// only as smarthost_delivery (docs/schema/schema.md §6) and runs no DDL.
//
// Concurrency rules:
//   - send jobs are leased (claimed_by, lease_expires_at, attempt_count;
//     D-03). Every write that depends on job ownership runs in a transaction
//     that first locks the job row and checks the fence: claimed_by = me,
//     lease_expires_at > now(), status = processing and the client active or
//     throttled (D-31). A lost lease writes nothing.
//   - transactions lock send_jobs rows before messages rows (in id order) so
//     workers, log ingestion and reconciliation cannot deadlock;
//   - message_events are append-only; (event_source, source_event_key) is the
//     de-duplication guarantee (D-06); messages.current_status changes only to a
//     higher-ranked status (status.Next);
//   - usage_records and webhook_events (INSERT-only for this role) are written
//     exactly once by first winning the state transition they belong to.
package store

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"sort"
	"strings"
	"sync/atomic"
	"time"

	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgxpool"

	"smarthost.local/delivery/internal/ids"
	"smarthost.local/delivery/internal/status"
)

// ErrLeaseLost means the fence failed: the job is no longer ours to write.
var ErrLeaseLost = errors.New("send-job lease lost (expired, reclaimed, cancelled or client suspended)")

// Store wraps the connection pool.
type Store struct {
	Pool *pgxpool.Pool
	// Policy configures the global suppression policy (D-30) applied to every
	// authoritative event this store appends.
	Policy PolicyConfig
}

// New opens a pool for dsn.
func New(ctx context.Context, dsn string, maxConns int32) (*Store, error) {
	cfg, err := pgxpool.ParseConfig(dsn)
	if err != nil {
		return nil, err
	}
	cfg.MaxConns = maxConns
	pool, err := pgxpool.NewWithConfig(ctx, cfg)
	if err != nil {
		return nil, err
	}
	return &Store{Pool: pool, Policy: DefaultPolicy}, nil
}

// Close closes the pool.
func (s *Store) Close() { s.Pool.Close() }

// Job is a claimed send job with what delivery needs of its sending domain.
type Job struct {
	ID, ClientID, MessageClass string
	ListID                     string
	SenderEmail, SenderName    string
	ReplyToEmail, ReplyToName  string
	TrackOpens, TrackClicks    bool
	AttemptCount               int
	StartedAt                  time.Time
	DomainName, DomainStatus   string
	DomainDKIM                 string
	// Throttled is the client's `throttled` status (Phase 8): its submissions are
	// paced by DELIVERY_THROTTLED_CLIENT_RATE_PER_MINUTE. Refreshed at every
	// lease renewal, so an operator's change applies to running jobs.
	Throttled atomic.Bool
}

// Claim leases the oldest claimable job: queued, or processing with an expired
// lease, of an active or throttled client. nil when there is none.
func (s *Store) Claim(ctx context.Context, me string, lease time.Duration) (*Job, error) {
	var id string
	err := s.Pool.QueryRow(ctx, `
WITH c AS (
  SELECT j.id FROM send_jobs j JOIN clients cl ON cl.id = j.client_id
   WHERE j.status IN ('queued', 'processing') AND cl.status IN ('active', 'throttled')
     AND (j.lease_expires_at IS NULL OR j.lease_expires_at < now())
   ORDER BY j.queued_at, j.id
   LIMIT 1
   FOR UPDATE OF j SKIP LOCKED)
UPDATE send_jobs j
   SET status = 'processing', claimed_by = $1, lease_expires_at = now() + make_interval(secs => $2),
       attempt_count = j.attempt_count + 1, started_at = COALESCE(j.started_at, now())
  FROM c WHERE j.id = c.id
RETURNING j.id::text`, me, lease.Seconds()).Scan(&id)
	if errors.Is(err, pgx.ErrNoRows) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	return s.LoadJob(ctx, id)
}

// LoadJob reads a job and its sending domain.
func (s *Store) LoadJob(ctx context.Context, id string) (*Job, error) {
	j := &Job{ID: id}
	var listID, senderName, replyEmail, replyName, dkim *string
	var clientStatus string
	err := s.Pool.QueryRow(ctx, `
SELECT j.client_id::text, j.message_class, j.list_id, j.sender_email, j.sender_name, j.reply_to_email,
       j.reply_to_name, j.track_opens, j.track_clicks, j.attempt_count, COALESCE(j.started_at, now()),
       d.domain, d.status, d.dkim_status, c.status
  FROM send_jobs j JOIN sending_domains d ON d.id = j.sending_domain_id JOIN clients c ON c.id = j.client_id
 WHERE j.id = $1`, id).Scan(&j.ClientID, &j.MessageClass, &listID, &j.SenderEmail, &senderName, &replyEmail,
		&replyName, &j.TrackOpens, &j.TrackClicks, &j.AttemptCount, &j.StartedAt, &j.DomainName, &j.DomainStatus, &dkim, &clientStatus)
	if err != nil {
		return nil, err
	}
	j.Throttled.Store(clientStatus == "throttled")
	j.ListID, j.SenderName, j.ReplyToEmail, j.ReplyToName, j.DomainDKIM = deref(listID), deref(senderName), deref(replyEmail), deref(replyName), deref(dkim)
	return j, nil
}

func deref(p *string) string {
	if p == nil {
		return ""
	}
	return *p
}

// Renew extends the lease; false when it was lost.
func (s *Store) Renew(ctx context.Context, jobID, me string, lease time.Duration) (time.Time, bool, error) {
	until, _, ok, err := s.RenewStatus(ctx, jobID, me, lease)
	return until, ok, err
}

// RenewStatus extends the lease and returns the client's current status
// (`active` or `throttled`; a suspended or closed client loses the lease).
func (s *Store) RenewStatus(ctx context.Context, jobID, me string, lease time.Duration) (time.Time, string, bool, error) {
	var until time.Time
	var clientStatus string
	err := s.Pool.QueryRow(ctx, `
UPDATE send_jobs j SET lease_expires_at = now() + make_interval(secs => $3)
  FROM clients c
 WHERE j.id = $1 AND j.claimed_by = $2 AND j.lease_expires_at > now() AND j.status = 'processing'
   AND c.id = j.client_id AND c.status IN ('active', 'throttled')
RETURNING j.lease_expires_at, c.status`, jobID, me, lease.Seconds()).Scan(&until, &clientStatus)
	if errors.Is(err, pgx.ErrNoRows) {
		return time.Time{}, "", false, nil
	}
	return until, clientStatus, err == nil, err
}

// ---------------------------------------------------------------------------
// Transactions

// tx is one transaction with the job rows it has locked, the per-job
// summary-count deltas to write before commit, and the suppression-policy work
// (D-30) of the authoritative events appended in it.
type tx struct {
	pgx.Tx
	locked map[string]map[string]int // job id -> summary counts (as locked)
	deltas map[string]map[string]int
	policy []policyItem
	cfg    PolicyConfig
	// Suppressions lists the suppressions created at commit (tests, logs).
	suppressions []CreatedSuppression
}

func (s *Store) begin(ctx context.Context) (*tx, error) {
	t, err := s.Pool.Begin(ctx)
	if err != nil {
		return nil, err
	}
	return &tx{Tx: t, locked: map[string]map[string]int{}, deltas: map[string]map[string]int{}, cfg: s.Policy}, nil
}

// fence locks the job row and checks that the lease is still ours.
func (t *tx) fence(ctx context.Context, jobID, me string) error {
	var raw []byte
	err := t.QueryRow(ctx, `
SELECT j.summary_counts_json FROM send_jobs j JOIN clients c ON c.id = j.client_id
 WHERE j.id = $1 AND j.claimed_by = $2 AND j.lease_expires_at > now() AND j.status = 'processing'
   AND c.status IN ('active', 'throttled')
   FOR NO KEY UPDATE OF j`, jobID, me).Scan(&raw)
	if errors.Is(err, pgx.ErrNoRows) {
		return ErrLeaseLost
	}
	if err != nil {
		return err
	}
	return t.remember(jobID, raw)
}

// lockJobs locks job rows (sorted, to keep one global lock order) without a
// lease check: log ingestion and reconciliation update jobs they do not own.
func (t *tx) lockJobs(ctx context.Context, jobIDs []string) error {
	var todo []string
	for _, id := range jobIDs {
		if _, ok := t.locked[id]; !ok {
			todo = append(todo, id)
		}
	}
	sort.Strings(todo)
	for _, id := range todo {
		var raw []byte
		if err := t.QueryRow(ctx, `SELECT summary_counts_json FROM send_jobs WHERE id = $1 FOR NO KEY UPDATE`, id).Scan(&raw); err != nil {
			return err
		}
		if err := t.remember(id, raw); err != nil {
			return err
		}
	}
	return nil
}

func (t *tx) remember(jobID string, raw []byte) error {
	counts := map[string]int{}
	if len(raw) > 0 {
		if err := json.Unmarshal(raw, &counts); err != nil {
			return fmt.Errorf("summary_counts_json: %w", err)
		}
	}
	t.locked[jobID] = counts
	return nil
}

func (t *tx) count(jobID, from, to string) {
	d := t.deltas[jobID]
	if d == nil {
		d = map[string]int{}
		t.deltas[jobID] = d
	}
	if from != "" {
		d[from]--
	}
	if to != "" {
		d[to]++
	}
}

// commit applies the suppression policy of the events appended in this
// transaction (D-30, in address order so concurrent transactions cannot
// deadlock on the per-address locks), writes the summary-count deltas (messages
// per current_status, the OpenAPI MessageStatusCounts) to the locked job rows
// and commits.
func (t *tx) commit(ctx context.Context) error {
	if err := t.applyPolicy(ctx); err != nil {
		return err
	}
	for jobID, d := range t.deltas {
		counts, ok := t.locked[jobID]
		if !ok {
			return fmt.Errorf("summary counts of job %s changed without its row lock", jobID)
		}
		changed := false
		for k, v := range d {
			if v != 0 {
				counts[k] += v
				changed = true
			}
		}
		if !changed {
			continue
		}
		raw, _ := json.Marshal(counts)
		if _, err := t.Exec(ctx, `UPDATE send_jobs SET summary_counts_json = $2::jsonb WHERE id = $1`, jobID, string(raw)); err != nil {
			return err
		}
	}
	return t.Commit(ctx)
}

// ---------------------------------------------------------------------------
// Events and projection

// Event is a message event to append.
type Event struct {
	MessageID    string
	Type         string
	Source       string
	Key          string
	FailureScope string
	SMTPCode     int
	Enhanced     string
	RemoteHost   string
	Diagnostic   string
	Metadata     map[string]any
	OccurredAt   time.Time
}

// msgState is the projection input of one message.
type msgState struct {
	ID, JobID, ClientID, Status string
}

// appendEvent inserts ev (de-duplicated by source key) and projects it onto the
// message. The caller holds the job row lock. It returns whether the event was
// new.
func (t *tx) appendEvent(ctx context.Context, m *msgState, ev Event) (bool, error) {
	_, inserted, err := t.appendEventID(ctx, m, ev)
	return inserted, err
}

// appendEventID is appendEvent that also returns the new event's id. A new
// authoritative bounce or complaint is queued for the suppression policy, so
// the policy is the same whatever the evidence source (Postfix log, DSN spool,
// unmatched-DSN resolution) and a replayed event (same source key) changes
// nothing.
func (t *tx) appendEventID(ctx context.Context, m *msgState, ev Event) (string, bool, error) {
	meta := ev.Metadata
	if meta == nil {
		meta = map[string]any{}
	}
	rawMeta, _ := json.Marshal(meta)
	var id string
	err := t.QueryRow(ctx, `
INSERT INTO message_events (id, message_id, event_type, event_source, source_event_key, failure_scope, smtp_code,
                            enhanced_status_code, remote_host, diagnostic, metadata_json, occurred_at)
VALUES ($1, $2, $3, $4, $5, NULLIF($6, ''), NULLIF($7, 0), NULLIF($8, ''), NULLIF($9, ''), NULLIF($10, ''), $11::jsonb, $12)
ON CONFLICT (event_source, source_event_key) WHERE source_event_key IS NOT NULL DO NOTHING
RETURNING id::text`, ids.UUIDv7(), ev.MessageID, ev.Type, ev.Source, ev.Key, ev.FailureScope, ev.SMTPCode,
		ev.Enhanced, ev.RemoteHost, truncate(ev.Diagnostic, 2000), string(rawMeta), ev.OccurredAt).Scan(&id)
	if errors.Is(err, pgx.ErrNoRows) {
		return "", false, nil // already recorded (replay)
	}
	if err != nil {
		return "", false, err
	}
	switch ev.Type {
	case "hard_bounce", "soft_bounce", "complaint":
		t.policy = append(t.policy, policyItem{messageID: m.ID, eventID: id, eventType: ev.Type, scope: ev.FailureScope})
	}
	next, changed := status.Next(m.Status, status.SetsStatus[ev.Type])
	if !changed {
		return id, true, nil
	}
	if _, err := t.Exec(ctx, `
UPDATE messages SET current_status = $2,
       resolved_at = CASE WHEN $3 THEN COALESCE(resolved_at, $4) ELSE resolved_at END
 WHERE id = $1`, m.ID, next, status.Terminal[next], ev.OccurredAt); err != nil {
		return id, true, err
	}
	t.count(m.JobID, m.Status, next)
	// First (and only) entry into hard_bounced / complained: outbox once, in this
	// transaction (D-22). Ranks make each entry happen at most once.
	switch next {
	case "hard_bounced":
		if err := t.outbox(ctx, m.ClientID, "message.hard_bounced", "message", m.ID); err != nil {
			return id, true, err
		}
	case "complained":
		if err := t.outbox(ctx, m.ClientID, "message.complained", "message", m.ID); err != nil {
			return id, true, err
		}
	}
	m.Status = next
	return id, true, nil
}

func (t *tx) outbox(ctx context.Context, clientID, eventType, subjectType, subjectID string) error {
	_, err := t.Exec(ctx, `INSERT INTO webhook_events (id, client_id, event_type, subject_type, subject_id) VALUES ($1, $2, $3, $4, $5)`,
		ids.UUIDv7(), clientID, eventType, subjectType, subjectID)
	return err
}

func truncate(s string, n int) string {
	if len(s) <= n {
		return s
	}
	return s[:n]
}

// maybeComplete completes a dispatched job when none of its messages is still
// unresolved, and writes send.completed in the same transaction.
func (t *tx) maybeComplete(ctx context.Context, jobID string) (bool, error) {
	var clientID string
	err := t.QueryRow(ctx, `
UPDATE send_jobs SET status = 'completed', completed_at = now()
 WHERE id = $1 AND status = 'dispatched'
   AND NOT EXISTS (SELECT 1 FROM messages WHERE send_job_id = $1 AND current_status = ANY($2))
RETURNING client_id::text`, jobID, status.Unresolved).Scan(&clientID)
	if errors.Is(err, pgx.ErrNoRows) {
		return false, nil
	}
	if err != nil {
		return false, err
	}
	return true, t.outbox(ctx, clientID, "send.completed", "send_job", jobID)
}

// ---------------------------------------------------------------------------
// Message creation

// Recipient is a staged recipient being expanded.
type Recipient struct {
	ID, ExternalRef, Email, Normalized string
}

// NewMessage is the decision for one recipient (made by the worker).
type NewMessage struct {
	Recipient     Recipient
	ID            string
	Address       string // RCPT TO (D-32 normalised)
	VERPToken     string
	ReturnPath    string
	TrackingToken string // "" when tracking is off
	// Outcome: "queued", or "suppressed" (SuppressionID/Reason set), or
	// "failed" (Diagnostic set, e.g. a normalisation disagreement).
	Outcome       string
	SuppressionID string
	Reason        string
	Diagnostic    string
}

// UnexpandedRecipients returns up to limit sealed recipients without a message.
func (s *Store) UnexpandedRecipients(ctx context.Context, jobID string, limit int) ([]Recipient, error) {
	rows, err := s.Pool.Query(ctx, `
SELECT r.id::text, r.external_recipient_reference, r.email_address, r.normalized_address
  FROM send_job_recipients r
 WHERE r.send_job_id = $1 AND NOT EXISTS (SELECT 1 FROM messages m WHERE m.send_job_recipient_id = r.id)
 ORDER BY r.id LIMIT $2`, jobID, limit)
	if err != nil {
		return nil, err
	}
	return pgx.CollectRows(rows, func(r pgx.CollectableRow) (Recipient, error) {
		var x Recipient
		return x, r.Scan(&x.ID, &x.ExternalRef, &x.Email, &x.Normalized)
	})
}

// Suppression is an active suppression that matches an address or domain.
type Suppression struct{ ID, Value, Scope, Reason string }

// ActiveSuppressions returns the active suppressions (global or of the client)
// matching any of the normalised addresses or their domains: not lifted and not
// expired. Global rows (client_id NULL, D-30) apply to every client.
func (s *Store) ActiveSuppressions(ctx context.Context, clientID string, addresses, domains []string) ([]Suppression, error) {
	rows, err := s.Pool.Query(ctx, `
SELECT id::text, address_or_domain, scope_type, reason FROM suppressions
 WHERE lifted_at IS NULL AND (expires_at IS NULL OR expires_at > now())
   AND (client_id IS NULL OR client_id = $1)
   AND ((scope_type = 'address' AND address_or_domain = ANY($2)) OR (scope_type = 'domain' AND address_or_domain = ANY($3)))
 ORDER BY created_at`, clientID, addresses, domains)
	if err != nil {
		return nil, err
	}
	return pgx.CollectRows(rows, func(r pgx.CollectableRow) (Suppression, error) {
		var x Suppression
		return x, r.Scan(&x.ID, &x.Value, &x.Scope, &x.Reason)
	})
}

// CreateMessages inserts one message per decided recipient, retry-safe: the
// unique send_job_recipient_id makes a second expansion of the same recipient
// a no-op, and its lifecycle events are keyed per message. It returns the
// number of messages created.
func (s *Store) CreateMessages(ctx context.Context, job *Job, me string, msgs []NewMessage) (int, error) {
	if len(msgs) == 0 {
		return 0, nil
	}
	t, err := s.begin(ctx)
	if err != nil {
		return 0, err
	}
	defer t.Rollback(ctx)
	if err := t.fence(ctx, job.ID, me); err != nil {
		return 0, err
	}
	now := time.Now()
	var mids, rids, refs, addrs, toks, rps, tts, sts []string
	for _, m := range msgs {
		mids, rids, refs = append(mids, m.ID), append(rids, m.Recipient.ID), append(refs, m.Recipient.ExternalRef)
		addrs, toks, rps = append(addrs, m.Address), append(toks, m.VERPToken), append(rps, m.ReturnPath)
		tts, sts = append(tts, m.TrackingToken), append(sts, m.Outcome)
	}
	rows, err := t.Query(ctx, `
INSERT INTO messages (id, send_job_id, send_job_recipient_id, external_recipient_reference, recipient_address,
                      verp_token, return_path, tracking_token, current_status, created_at, resolved_at)
SELECT u.id::uuid, $1, u.rid::uuid, u.ref, u.addr, u.tok, u.rp, NULLIF(u.tt, ''), u.st, $9::timestamptz,
       CASE WHEN u.st IN ('suppressed', 'failed') THEN $9::timestamptz END
  FROM unnest($2::text[], $3::text[], $4::text[], $5::text[], $6::text[], $7::text[], $8::text[], $10::text[])
       AS u(id, rid, ref, addr, tok, rp, tt, st)
ON CONFLICT (send_job_recipient_id) DO NOTHING
RETURNING id::text`, job.ID, mids, rids, refs, addrs, toks, rps, tts, now, sts)
	if err != nil {
		return 0, err
	}
	created := map[string]bool{}
	for rows.Next() {
		var id string
		if err := rows.Scan(&id); err != nil {
			rows.Close()
			return 0, err
		}
		created[id] = true
	}
	rows.Close()
	if err := rows.Err(); err != nil {
		return 0, err
	}
	for _, m := range msgs {
		if !created[m.ID] {
			continue
		}
		t.count(job.ID, "", m.Outcome)
		evs := []Event{{Type: "message_created", Metadata: map[string]any{"send_job_recipient_id": m.Recipient.ID}}}
		switch m.Outcome {
		case "queued":
			evs = append(evs, Event{Type: "message_queued"})
		case "suppressed":
			evs = append(evs, Event{Type: "message_suppressed", Metadata: map[string]any{"suppression_id": m.SuppressionID, "reason": m.Reason}})
		case "failed":
			evs = append(evs, Event{Type: "submission_failed", Diagnostic: m.Diagnostic})
		}
		for _, ev := range evs {
			ev.MessageID, ev.Source, ev.Key, ev.OccurredAt = m.ID, "delivery_daemon", ev.Type+":"+m.ID, now
			// The row already carries its initial status; these events record its history.
			if _, err := t.appendEvent(ctx, &msgState{ID: m.ID, JobID: job.ID, ClientID: job.ClientID, Status: m.Outcome}, ev); err != nil {
				return 0, err
			}
		}
	}
	return len(created), t.commit(ctx)
}

// ---------------------------------------------------------------------------
// Submission

// Pending is a queued message without a queue id (work for the submitter).
type Pending struct{ ID, Address, Domain string }

// PendingMessages pages through a job's queued messages in id order.
func (s *Store) PendingMessages(ctx context.Context, jobID, afterID string, limit int) ([]Pending, error) {
	rows, err := s.Pool.Query(ctx, `
SELECT id::text, recipient_address FROM messages
 WHERE send_job_id = $1 AND current_status = 'queued' AND postfix_queue_id IS NULL AND id > $2::uuid
 ORDER BY id LIMIT $3`, jobID, afterID, limit)
	if err != nil {
		return nil, err
	}
	return pgx.CollectRows(rows, func(r pgx.CollectableRow) (Pending, error) {
		var p Pending
		err := r.Scan(&p.ID, &p.Address)
		p.Domain = strings.ToLower(p.Address[strings.LastIndexByte(p.Address, '@')+1:])
		return p, err
	})
}

// QueuedWithoutQueueID returns the ids of a job's queued messages that have no
// queue id (candidates for an ambiguous earlier submission, §6.3).
func (s *Store) QueuedWithoutQueueID(ctx context.Context, jobID string) ([]string, error) {
	rows, err := s.Pool.Query(ctx, `SELECT id::text FROM messages WHERE send_job_id = $1 AND current_status = 'queued' AND postfix_queue_id IS NULL`, jobID)
	if err != nil {
		return nil, err
	}
	return pgx.CollectRows(rows, pgx.RowTo[string])
}

// Content is everything needed to build one message.
type Content struct {
	Subject, HTML, Text, UnsubscribeURL string
	Purged                              bool
	Address, ReturnPath, TrackingToken  string
	Status, QueueID                     string
}

// LoadContent reads one message's rendered content (only one message's
// content is held in memory per submission slot).
func (s *Store) LoadContent(ctx context.Context, messageID string) (*Content, error) {
	c := &Content{}
	var subj, html, text, unsub, tt, qid *string
	var purged *time.Time
	err := s.Pool.QueryRow(ctx, `
SELECT r.subject, r.html_body, r.text_body, r.unsubscribe_url, r.content_purged_at,
       m.recipient_address, m.return_path, m.tracking_token, m.current_status, m.postfix_queue_id
  FROM messages m JOIN send_job_recipients r ON r.id = m.send_job_recipient_id
 WHERE m.id = $1`, messageID).Scan(&subj, &html, &text, &unsub, &purged, &c.Address, &c.ReturnPath, &tt, &c.Status, &qid)
	if err != nil {
		return nil, err
	}
	c.Subject, c.HTML, c.Text, c.UnsubscribeURL = deref(subj), deref(html), deref(text), deref(unsub)
	c.TrackingToken, c.QueueID, c.Purged = deref(tt), deref(qid), purged != nil
	return c, nil
}

// Link is a click-tracking mapping row.
type Link struct {
	Index  int
	Target string
}

// RecordLinks stores the server-side click mapping before submission
// (idempotent: a rebuild of the same content yields the same rows).
func (s *Store) RecordLinks(ctx context.Context, job *Job, me, messageID string, links []Link) error {
	if len(links) == 0 {
		return nil
	}
	t, err := s.begin(ctx)
	if err != nil {
		return err
	}
	defer t.Rollback(ctx)
	if err := t.fence(ctx, job.ID, me); err != nil {
		return err
	}
	var lids, targets []string
	var idx []int32
	for _, l := range links {
		lids, idx, targets = append(lids, ids.UUIDv7()), append(idx, int32(l.Index)), append(targets, l.Target)
	}
	if _, err := t.Exec(ctx, `
INSERT INTO message_links (id, message_id, link_index, target_url)
SELECT u.id::uuid, $1, u.idx, u.target FROM unnest($2::text[], $3::int[], $4::text[]) AS u(id, idx, target)
ON CONFLICT (message_id, link_index) DO NOTHING`, messageID, lids, idx, targets); err != nil {
		return err
	}
	return t.commit(ctx)
}

// Acceptance is a confirmed Postfix queueing of a message.
type Acceptance struct {
	MessageID  string
	QueueID    string
	Source     string // postfix_submission (SMTP reply) or postfix_log (recovered, §6.3)
	Key        string
	OccurredAt time.Time
	SMTPCode   int
	Enhanced   string
	Diagnostic string
}

// RecordAccepted is the one transaction that makes a Postfix acceptance
// durable (postfix-integration §1, D-14): it stores the queue id, appends
// submitted_to_postfix, projects `submitted`, purges the rendered content and
// meters one message_submitted unit. The queue-id update wins only once
// (postfix_queue_id IS NULL), so a replay can neither purge nor meter twice.
// It returns false when the message was already recorded.
func (s *Store) RecordAccepted(ctx context.Context, job *Job, me string, a Acceptance) (bool, error) {
	t, err := s.begin(ctx)
	if err != nil {
		return false, err
	}
	defer t.Rollback(ctx)
	if err := t.fence(ctx, job.ID, me); err != nil {
		return false, err
	}
	var recipientID string
	err = t.QueryRow(ctx, `
UPDATE messages SET postfix_queue_id = $2
 WHERE id = $1 AND postfix_queue_id IS NULL AND current_status = 'queued'
RETURNING send_job_recipient_id::text`, a.MessageID, a.QueueID).Scan(&recipientID)
	if errors.Is(err, pgx.ErrNoRows) {
		return false, nil
	}
	if err != nil {
		return false, err
	}
	m := &msgState{ID: a.MessageID, JobID: job.ID, ClientID: job.ClientID, Status: "queued"}
	if _, err := t.appendEvent(ctx, m, Event{MessageID: a.MessageID, Type: "submitted_to_postfix", Source: a.Source, Key: a.Key,
		SMTPCode: a.SMTPCode, Enhanced: a.Enhanced, Diagnostic: a.Diagnostic, OccurredAt: a.OccurredAt,
		Metadata: map[string]any{"postfix_queue_id": a.QueueID}}); err != nil {
		return false, err
	}
	tag, err := t.Exec(ctx, `
UPDATE send_job_recipients SET subject = NULL, html_body = NULL, text_body = NULL, content_purged_at = now()
 WHERE id = $1 AND content_purged_at IS NULL`, recipientID)
	if err != nil {
		return false, err
	}
	if tag.RowsAffected() != 1 {
		return false, fmt.Errorf("message %s: rendered content already purged before its acceptance was recorded", a.MessageID)
	}
	if _, err := t.Exec(ctx, `
INSERT INTO usage_records (id, client_id, usage_type, quantity, reference_type, reference_id, occurred_at)
VALUES ($1, $2, 'message_submitted', 1, 'message', $3, $4)`, ids.UUIDv7(), job.ClientID, a.MessageID, a.OccurredAt); err != nil {
		return false, err
	}
	return true, t.commit(ctx)
}

// RecordSubmissionFailed records an authoritative permanent refusal before
// Postfix queued the message: submission_failed, status failed. The content
// stays until APP_RETENTION_STAGED_CONTENT_DAYS (Symfony's cleanup).
func (s *Store) RecordSubmissionFailed(ctx context.Context, job *Job, me, messageID string, code int, enhanced, diagnostic, stage string) (bool, error) {
	t, err := s.begin(ctx)
	if err != nil {
		return false, err
	}
	defer t.Rollback(ctx)
	if err := t.fence(ctx, job.ID, me); err != nil {
		return false, err
	}
	var cur string
	if err := t.QueryRow(ctx, `SELECT current_status FROM messages WHERE id = $1 AND postfix_queue_id IS NULL FOR UPDATE`, messageID).Scan(&cur); err != nil {
		if errors.Is(err, pgx.ErrNoRows) {
			return false, nil
		}
		return false, err
	}
	m := &msgState{ID: messageID, JobID: job.ID, ClientID: job.ClientID, Status: cur}
	// Without a queue id the postfix_submission key rule (<message_id>:<qid>)
	// cannot apply, so the refusal is keyed as a delivery_daemon lifecycle event.
	inserted, err := t.appendEvent(ctx, m, Event{MessageID: messageID, Type: "submission_failed", Source: "delivery_daemon", Key: "submission_failed:" + messageID,
		SMTPCode: code, Enhanced: enhanced, Diagnostic: diagnostic, OccurredAt: time.Now(), Metadata: map[string]any{"stage": stage}})
	if err != nil {
		return false, err
	}
	return inserted, t.commit(ctx)
}

// FinishDispatch marks the job dispatched when every recipient has a message
// and none is still created/queued, releases the lease, and completes the job
// at once when nothing is unresolved.
func (s *Store) FinishDispatch(ctx context.Context, job *Job, me string) (dispatched, completed bool, err error) {
	t, err := s.begin(ctx)
	if err != nil {
		return false, false, err
	}
	defer t.Rollback(ctx)
	if err := t.fence(ctx, job.ID, me); err != nil {
		return false, false, err
	}
	tag, err := t.Exec(ctx, `
UPDATE send_jobs SET status = 'dispatched', dispatch_completed_at = now(), lease_expires_at = NULL
 WHERE id = $1
   AND NOT EXISTS (SELECT 1 FROM send_job_recipients r WHERE r.send_job_id = $1
                     AND NOT EXISTS (SELECT 1 FROM messages m WHERE m.send_job_recipient_id = r.id))
   AND NOT EXISTS (SELECT 1 FROM messages WHERE send_job_id = $1 AND current_status IN ('created', 'queued'))`, job.ID)
	if err != nil || tag.RowsAffected() == 0 {
		return false, false, err
	}
	completed, err = t.maybeComplete(ctx, job.ID)
	if err != nil {
		return false, false, err
	}
	return true, completed, t.commit(ctx)
}

// FailJob fails the whole job (e.g. its sending domain may not be used),
// writing send.failed and an audit record in the same transaction.
func (s *Store) FailJob(ctx context.Context, job *Job, me, reason string) error {
	t, err := s.begin(ctx)
	if err != nil {
		return err
	}
	defer t.Rollback(ctx)
	if err := t.fence(ctx, job.ID, me); err != nil {
		return err
	}
	if _, err := t.Exec(ctx, `UPDATE send_jobs SET status = 'failed', last_error = $2, lease_expires_at = NULL WHERE id = $1`, job.ID, truncate(reason, 1000)); err != nil {
		return err
	}
	if err := t.outbox(ctx, job.ClientID, "send.failed", "send_job", job.ID); err != nil {
		return err
	}
	detail, _ := json.Marshal(map[string]any{"reason": reason, "worker": me})
	if _, err := t.Exec(ctx, `INSERT INTO audit_log (id, actor_type, actor_id, action, target_type, target_id, detail_json)
VALUES ($1, 'system', $2, 'send_job.failed', 'send_job', $3, $4::jsonb)`, ids.UUIDv7(), "delivery:"+me, job.ID, string(detail)); err != nil {
		return err
	}
	return t.commit(ctx)
}

// SetLastError notes a transient job-level problem (no fence needed beyond
// ownership; best effort).
func (s *Store) SetLastError(ctx context.Context, jobID, me, msg string) {
	_, _ = s.Pool.Exec(ctx, `UPDATE send_jobs SET last_error = $3 WHERE id = $1 AND claimed_by = $2 AND status = 'processing'`, jobID, me, truncate(msg, 1000))
}

// DeferredDomains returns recipient domains with at least min messages
// currently deferred by Postfix (provider pressure: back off, D-18 pacing).
func (s *Store) DeferredDomains(ctx context.Context, min int) ([]string, error) {
	rows, err := s.Pool.Query(ctx, `
SELECT lower(split_part(recipient_address, '@', 2)) FROM messages
 WHERE current_status = 'deferred' AND created_at > now() - interval '7 days'
 GROUP BY 1 HAVING count(*) >= $1`, min)
	if err != nil {
		return nil, err
	}
	return pgx.CollectRows(rows, pgx.RowTo[string])
}
