package store

import (
	"context"
	"errors"
	"time"

	"github.com/jackc/pgx/v5"

	"smarthost.local/delivery/internal/ids"
	"smarthost.local/delivery/internal/postfixlog"
)

// Advisory-lock keys: only one Go instance ingests the log or reconciles at a
// time (postfix-integration §7).
const (
	ingestLockKey    int64 = 0x534d48_4c4f47 // "SMH" "LOG"
	reconcileLockKey int64 = 0x534d48_52434e // "SMH" "RCN"
)

// ErrBusy means another instance holds the advisory lock.
var ErrBusy = errors.New("another delivery instance holds the lock")

// LogSource is the delivery_ingest_cursors.source of the Postfix log.
const LogSource = "postfix_log"

// Cursor is the persistent ingestion position (generation + byte position).
type Cursor struct {
	GenerationID string
	Position     int64
}

// GetCursor reads the Postfix log cursor; ok is false before the first batch.
func (s *Store) GetCursor(ctx context.Context) (Cursor, bool, error) {
	var c Cursor
	err := s.Pool.QueryRow(ctx, `SELECT generation_id, position FROM delivery_ingest_cursors WHERE source = $1`, LogSource).Scan(&c.GenerationID, &c.Position)
	if errors.Is(err, pgx.ErrNoRows) {
		return c, false, nil
	}
	return c, err == nil, err
}

// HoldWindow: a cleanup record for a Smarthost message whose queue id is not
// recorded yet belongs to a submission whose SMTP reply is being processed.
// Ingestion waits (up to this long after the record) so that the message's
// later records can be correlated by queue id.
const HoldWindow = 60 * time.Second

// BatchResult reports one ingestion batch.
type BatchResult struct {
	Applied   int  // events appended
	Consumed  int  // records consumed (cursor advanced past them)
	Held      bool // stopped at a record of an in-flight submission
	Completed int  // send jobs completed
}

// ApplyLogBatch appends the events of a batch of records from one generation
// and moves the cursor to (generation, next) in the same transaction, so a
// crash either keeps both or neither (§3). When advance is false (re-scans
// for reconciliation) no cursor is written. Re-applying records is harmless:
// postfix_log keys are "<generation>:<position>".
func (s *Store) ApplyLogBatch(ctx context.Context, recs []postfixlog.Located, generationID string, next int64, advance bool, bounceDomain string, now time.Time) (BatchResult, error) {
	var res BatchResult
	t, err := s.begin(ctx)
	if err != nil {
		return res, err
	}
	defer t.Rollback(ctx)
	lockKey := ingestLockKey
	if !advance {
		lockKey = reconcileLockKey + 1 // re-scans do not contend with routine ingestion
	}
	var got bool
	if err := t.QueryRow(ctx, `SELECT pg_try_advisory_xact_lock($1)`, lockKey).Scan(&got); err != nil {
		return res, err
	}
	if !got {
		return res, ErrBusy
	}

	// Correlate queue ids (and, for the hold rule, Smarthost Message-IDs).
	qids := map[string]bool{}
	var uuids []string
	for _, r := range recs {
		if r.Kind == postfixlog.QueueActive || r.Kind == postfixlog.Delivery {
			qids[r.QueueID] = true
		}
		if r.Kind == postfixlog.MessageID {
			if id, ok := ids.ParseMessageIDHeader("<"+r.MessageID+">", bounceDomain); ok {
				uuids = append(uuids, id)
			}
		}
	}
	// Order matters: first the Smarthost messages still waiting for their queue
	// id (an SMTP reply being recorded), then the messages by queue id. A
	// worker's acceptance committing between the two statements is then seen
	// by at least one of them (READ COMMITTED: each statement sees the latest
	// commits), so no record can slip past both the hold rule and correlation.
	waiting := map[string]bool{} // Smarthost messages queued in Go without a queue id yet
	if len(uuids) > 0 {
		rows, err := t.Query(ctx, `SELECT id::text FROM messages WHERE id = ANY($1::text[]::uuid[]) AND postfix_queue_id IS NULL AND current_status = 'queued'`, uuids)
		if err != nil {
			return res, err
		}
		for rows.Next() {
			var id string
			if err := rows.Scan(&id); err != nil {
				rows.Close()
				return res, err
			}
			waiting[id] = true
		}
		rows.Close()
	}

	type msgRow struct {
		msgState
		qid     string
		created time.Time
	}
	// Queue ids are unique in practice (long ids); should two messages ever
	// share one, a record belongs to the newest message created before it
	// (correlation window, postfix-integration §3).
	byQID := map[string][]*msgRow{}
	if len(qids) > 0 {
		keys := make([]string, 0, len(qids))
		for q := range qids {
			keys = append(keys, q)
		}
		rows, err := t.Query(ctx, `
SELECT m.id::text, m.send_job_id::text, j.client_id::text, m.current_status, m.postfix_queue_id, m.created_at
  FROM messages m JOIN send_jobs j ON j.id = m.send_job_id
 WHERE m.postfix_queue_id = ANY($1)`, keys)
		if err != nil {
			return res, err
		}
		for rows.Next() {
			m := &msgRow{}
			if err := rows.Scan(&m.ID, &m.JobID, &m.ClientID, &m.Status, &m.qid, &m.created); err != nil {
				rows.Close()
				return res, err
			}
			byQID[m.qid] = append(byQID[m.qid], m)
		}
		rows.Close()
		if err := rows.Err(); err != nil {
			return res, err
		}
	}
	pick := func(r postfixlog.Located) *msgRow {
		var best *msgRow
		for _, m := range byQID[r.QueueID] {
			if r.Time.Before(m.created.Add(-10 * time.Minute)) {
				continue // created after the record: not this message
			}
			if best == nil || m.created.After(best.created) {
				best = m
			}
		}
		return best
	}

	// Hold rule: stop before the cleanup record of an in-flight submission.
	cut := len(recs)
	if advance {
		for i, r := range recs {
			if r.Kind != postfixlog.MessageID {
				continue
			}
			if id, ok := ids.ParseMessageIDHeader("<"+r.MessageID+">", bounceDomain); ok && waiting[id] && now.Sub(r.Time) < HoldWindow {
				cut, res.Held = i, true
				next = r.Pos
				break
			}
		}
	}
	recs = recs[:cut]
	res.Consumed = cut

	// Lock the affected jobs first (global lock order), then project.
	var jobIDs []string
	seen := map[string]bool{}
	for _, r := range recs {
		if m := pick(r); m != nil && !seen[m.JobID] {
			seen[m.JobID] = true
			jobIDs = append(jobIDs, m.JobID)
		}
	}
	if err := t.lockJobs(ctx, jobIDs); err != nil {
		return res, err
	}
	// Re-read the current status under the job locks (a worker may have moved it).
	for _, ms := range byQID {
		for _, m := range ms {
			if err := t.QueryRow(ctx, `SELECT current_status FROM messages WHERE id = $1`, m.ID).Scan(&m.Status); err != nil {
				return res, err
			}
		}
	}
	for _, r := range recs {
		m := pick(r)
		if m == nil {
			continue // not a Smarthost message (or outside its correlation window)
		}
		var ev Event
		switch r.Kind {
		case postfixlog.QueueActive:
			ev.Type = "postfix_queued"
			if m.Status == "deferred" {
				ev.Type = "delivery_attempt" // the queue manager re-activated a deferred message
			}
		case postfixlog.Delivery:
			c, ok := postfixlog.Classify(r.Record)
			if !ok {
				continue
			}
			ev = Event{Type: c.Type, FailureScope: c.FailureScope, SMTPCode: c.SMTPCode, Enhanced: c.Enhanced,
				RemoteHost: c.RemoteHost, Diagnostic: c.Diagnostic}
		default:
			continue
		}
		ev.MessageID, ev.Source, ev.Key, ev.OccurredAt = m.ID, "postfix_log", r.Key(), r.Time
		ev.Metadata = map[string]any{"postfix_queue_id": r.QueueID, "process": r.Process}
		if r.Status != "" {
			ev.Metadata["postfix_status"] = r.Status
		}
		inserted, err := t.appendEvent(ctx, &m.msgState, ev)
		if err != nil {
			return res, err
		}
		if inserted {
			res.Applied++
		}
	}
	for _, j := range jobIDs {
		done, err := t.maybeComplete(ctx, j)
		if err != nil {
			return res, err
		}
		if done {
			res.Completed++
		}
	}
	if advance {
		if _, err := t.Exec(ctx, `
INSERT INTO delivery_ingest_cursors (source, generation_id, position, updated_at) VALUES ($1, $2, $3, now())
ON CONFLICT (source) DO UPDATE SET generation_id = EXCLUDED.generation_id, position = EXCLUDED.position, updated_at = now()`,
			LogSource, generationID, next); err != nil {
			return res, err
		}
	}
	return res, t.commit(ctx)
}

// ---------------------------------------------------------------------------
// Reconciliation (D-27, postfix-integration §6)

// TryReconcileLock takes the session advisory lock for one reconciliation
// pass on a dedicated connection; release returns it.
func (s *Store) TryReconcileLock(ctx context.Context) (release func(), ok bool, err error) {
	conn, err := s.Pool.Acquire(ctx)
	if err != nil {
		return nil, false, err
	}
	if err := conn.QueryRow(ctx, `SELECT pg_try_advisory_lock($1)`, reconcileLockKey).Scan(&ok); err != nil || !ok {
		conn.Release()
		return nil, false, err
	}
	return func() {
		_, _ = conn.Exec(context.Background(), `SELECT pg_advisory_unlock($1)`, reconcileLockKey)
		conn.Release()
	}, true, nil
}

// Candidate is an unresolved message in Postfix.
type Candidate struct {
	MessageID, JobID, QueueID, VERPToken string
	LastEvent                            time.Time
}

// Candidates returns submitted/deferred messages with a queue id whose latest
// event is older than the grace interval (§6.1).
func (s *Store) Candidates(ctx context.Context, grace time.Duration, limit int) ([]Candidate, error) {
	rows, err := s.Pool.Query(ctx, `
SELECT m.id::text, m.send_job_id::text, m.postfix_queue_id, m.verp_token, e.last
  FROM messages m
  CROSS JOIN LATERAL (SELECT max(occurred_at) AS last FROM message_events WHERE message_id = m.id) e
 WHERE m.current_status IN ('submitted', 'deferred') AND m.postfix_queue_id IS NOT NULL
   AND e.last < now() - make_interval(secs => $1)
 ORDER BY m.created_at LIMIT $2`, grace.Seconds(), limit)
	if err != nil {
		return nil, err
	}
	return pgx.CollectRows(rows, func(r pgx.CollectableRow) (Candidate, error) {
		var c Candidate
		return c, r.Scan(&c.MessageID, &c.JobID, &c.QueueID, &c.VERPToken, &c.LastEvent)
	})
}

// PendingDSN reports an unresolved unmatched DSN that may concern the
// message (by VERP token or queue id): an authoritative outcome exists but
// awaits operator resolution (D-05), so no outcome_unknown is concluded.
func (s *Store) PendingDSN(ctx context.Context, verp, qid string) (bool, error) {
	var ok bool
	err := s.Pool.QueryRow(ctx, `SELECT EXISTS (SELECT 1 FROM unmatched_dsns WHERE status IN ('open', 'match_requested')
  AND (verp_token = $1 OR postfix_queue_id = $2))`, verp, qid).Scan(&ok)
	return ok, err
}

// MarkOutcomeUnknown appends transport_outcome_unknown (queue_reconciliation,
// key "<message_id>:<queue_id>") when the message is still submitted or
// deferred, projects outcome_unknown (never a success) and completes the job
// when nothing else is unresolved.
func (s *Store) MarkOutcomeUnknown(ctx context.Context, c Candidate, detail map[string]any) (bool, error) {
	t, err := s.begin(ctx)
	if err != nil {
		return false, err
	}
	defer t.Rollback(ctx)
	if err := t.lockJobs(ctx, []string{c.JobID}); err != nil {
		return false, err
	}
	m := &msgState{ID: c.MessageID, JobID: c.JobID}
	err = t.QueryRow(ctx, `SELECT m.current_status, j.client_id::text FROM messages m JOIN send_jobs j ON j.id = m.send_job_id WHERE m.id = $1 FOR UPDATE OF m`,
		c.MessageID).Scan(&m.Status, &m.ClientID)
	if err != nil {
		return false, err
	}
	if m.Status != "submitted" && m.Status != "deferred" {
		return false, nil // resolved meanwhile
	}
	inserted, err := t.appendEvent(ctx, m, Event{MessageID: c.MessageID, Type: "transport_outcome_unknown", Source: "queue_reconciliation",
		Key: c.MessageID + ":" + c.QueueID, OccurredAt: time.Now().UTC(), Metadata: detail})
	if err != nil {
		return false, err
	}
	if _, err := t.maybeComplete(ctx, c.JobID); err != nil {
		return false, err
	}
	return inserted, t.commit(ctx)
}

// SweepCompletion completes dispatched jobs that have no unresolved message
// (a safety net; completion normally happens in the transaction that resolves
// the last message).
func (s *Store) SweepCompletion(ctx context.Context, limit int) (int, error) {
	rows, err := s.Pool.Query(ctx, `SELECT id::text FROM send_jobs WHERE status = 'dispatched' ORDER BY dispatch_completed_at LIMIT $1`, limit)
	if err != nil {
		return 0, err
	}
	jobs, err := pgx.CollectRows(rows, pgx.RowTo[string])
	if err != nil {
		return 0, err
	}
	n := 0
	for _, j := range jobs {
		t, err := s.begin(ctx)
		if err != nil {
			return n, err
		}
		if err := t.lockJobs(ctx, []string{j}); err != nil {
			t.Rollback(ctx)
			return n, err
		}
		done, err := t.maybeComplete(ctx, j)
		if err != nil {
			t.Rollback(ctx)
			return n, err
		}
		if err := t.commit(ctx); err != nil {
			return n, err
		}
		if done {
			n++
		}
	}
	return n, nil
}

// MessageStatus returns a message's current status (tests, reconciliation).
func (s *Store) MessageStatus(ctx context.Context, id string) (string, error) {
	var st string
	err := s.Pool.QueryRow(ctx, `SELECT current_status FROM messages WHERE id = $1`, id).Scan(&st)
	return st, err
}
