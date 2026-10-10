package store

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/jackc/pgx/v5"

	"smarthost.local/delivery/internal/dsn"
	"smarthost.local/delivery/internal/ids"
)

// Inbound DSN and complaint ingestion (postfix-integration §5, D-05, D-06, D-30).

// maxStoredRaw bounds unmatched_dsns.raw_message; the spool file keeps the
// byte-exact original until DELIVERY_DSN_RETENTION_DAYS (RET=HDRS keeps real
// DSNs far smaller).
const maxStoredRaw = 512 << 10

// recipientWindow bounds the recipient-based candidate list of an unmatched DSN.
const recipientWindow = 7 * 24 * time.Hour

// DSNInput is one claimed spool file.
type DSNInput struct {
	Key        string // Maildir unique file name (the D-06 source key)
	Raw        []byte
	SHA256     string // diagnostic only, never a de-duplication key
	ReceivedAt time.Time
	Report     *dsn.Report
	Evidence   dsn.Evidence
}

// DSN ingestion results.
const (
	DSNAlready       = "already_processed" // the key is recorded (retry, restart, duplicate notification)
	DSNEvent         = "event"             // a transport event was appended to the correlated message
	DSNPartial       = "partial"           // correlated, but not reconcilable: dsn_unmatched on the message
	DSNInformational = "informational"     // correlated, reports no failure (e.g. Action relayed); nothing recorded
	DSNUnmatched     = "unmatched"         // no confident correlation: an unmatched_dsns row
)

// DSNResult reports one ingestion.
type DSNResult struct {
	Result       string
	MessageID    string
	EventType    string
	Correlation  string
	Reason       string
	Candidates   int // recipient-based operator hints stored with an unmatched DSN
	Suppressions []CreatedSuppression
}

// DSNMessage is a correlated message.
type DSNMessage struct {
	ID, JobID, ClientID, Status, Recipient, QueueID, SenderEmail string
	CreatedAt                                                    time.Time
}

func scanDSNMessage(row pgx.CollectableRow) (DSNMessage, error) {
	var m DSNMessage
	return m, row.Scan(&m.ID, &m.JobID, &m.ClientID, &m.Status, &m.Recipient, &m.QueueID, &m.SenderEmail, &m.CreatedAt)
}

const dsnMessageSelect = `
SELECT m.id::text, m.send_job_id::text, j.client_id::text, m.current_status, m.recipient_address,
       COALESCE(m.postfix_queue_id, ''), j.sender_email, m.created_at
  FROM messages m JOIN send_jobs j ON j.id = m.send_job_id `

func (t *tx) messagesWhere(ctx context.Context, where string, args ...any) ([]DSNMessage, error) {
	rows, err := t.Query(ctx, dsnMessageSelect+where+` LIMIT 3`, args...)
	if err != nil {
		return nil, err
	}
	return pgx.CollectRows(rows, scanDSNMessage)
}

// DSNCandidate is a message an uncorrelated DSN might concern, found only by its
// recipient address. It is diagnostic information for the operator (stored in
// unmatched_dsns.detail_json.candidates), never correlation evidence (D-36).
type DSNCandidate struct {
	MessageID     string `json:"message_id"`
	SenderMatches bool   `json:"sender_matches_returned_from"`
	CreatedAt     string `json:"created_at"`
}

// correlate resolves the evidence to exactly one message using only strong,
// Smarthost-issued identifiers, strongest first: VERP token, envelope id,
// Smarthost Message-ID, Postfix queue id. An identifier naming no message is no
// evidence; identifiers naming different messages are a conflict (never
// guessed). The recipient address (even with a matching returned From) is never
// enough (D-36): suppressions are global, so a forged DSN must not be able to
// suppress an address. Without a strong identifier the DSN stays unmatched for
// the operator, with recipient-based candidates as diagnostic information.
func (t *tx) correlate(ctx context.Context, e dsn.Evidence, received time.Time) (*DSNMessage, string, string, []DSNCandidate, error) {
	type hit struct {
		method string
		msg    DSNMessage
	}
	var hits []hit
	try := func(method, where string, args ...any) error {
		ms, err := t.messagesWhere(ctx, where, args...)
		if err != nil {
			return err
		}
		switch len(ms) {
		case 0: // names no message: not evidence
		case 1:
			hits = append(hits, hit{method, ms[0]})
		default:
			hits = append(hits, hit{method + "_ambiguous", DSNMessage{}})
		}
		return nil
	}
	if e.VERPToken != "" {
		if err := try("verp", `WHERE m.verp_token = $1`, e.VERPToken); err != nil {
			return nil, "", "", nil, err
		}
	}
	if e.EnvelopeID != "" {
		if err := try("envelope_id", `WHERE m.id = $1::uuid`, e.EnvelopeID); err != nil {
			return nil, "", "", nil, err
		}
	}
	for _, id := range e.MessageIDs {
		if err := try("message_id", `WHERE m.id = $1::uuid`, id); err != nil {
			return nil, "", "", nil, err
		}
	}
	if e.QueueID != "" {
		if err := try("queue_id", `WHERE m.postfix_queue_id = $1 AND m.created_at <= $2::timestamptz + interval '1 hour'`, e.QueueID, received); err != nil {
			return nil, "", "", nil, err
		}
	}
	var found *hit
	for i := range hits {
		h := &hits[i]
		if strings.HasSuffix(h.method, "_ambiguous") {
			return nil, "", "ambiguous " + strings.TrimSuffix(h.method, "_ambiguous") + " correlation", nil, nil
		}
		if found == nil {
			found = h
		} else if found.msg.ID != h.msg.ID {
			return nil, "", fmt.Sprintf("conflicting correlation evidence (%s and %s name different messages)", found.method, h.method), nil, nil
		}
	}
	if found != nil {
		return &found.msg, found.method, "", nil, nil
	}
	if len(hits) == 0 && e.VERPToken == "" && e.EnvelopeID == "" && len(e.MessageIDs) == 0 && e.QueueID == "" {
		cands, err := t.candidates(ctx, e, received)
		return nil, "", "no Smarthost identifier (VERP token, envelope id, Message-ID or queue id)", cands, err
	}
	cands, err := t.candidates(ctx, e, received)
	return nil, "", "the Smarthost identifiers in the DSN name no message", cands, err
}

// candidates lists, for the operator only, recent messages to the recipient
// the report names (at most 5, within recipientWindow, with a queue id).
func (t *tx) candidates(ctx context.Context, e dsn.Evidence, received time.Time) ([]DSNCandidate, error) {
	if len(e.Recipients) != 1 {
		return nil, nil
	}
	rows, err := t.Query(ctx, dsnMessageSelect+`
 WHERE m.recipient_address = $1 AND m.postfix_queue_id IS NOT NULL
   AND m.created_at BETWEEN $2::timestamptz - make_interval(secs => $3) AND $2::timestamptz + interval '1 hour'
 ORDER BY m.created_at DESC LIMIT 5`, e.Recipients[0], received, recipientWindow.Seconds())
	if err != nil {
		return nil, err
	}
	ms, err := pgx.CollectRows(rows, scanDSNMessage)
	if err != nil {
		return nil, err
	}
	out := make([]DSNCandidate, 0, len(ms))
	for _, m := range ms {
		out = append(out, DSNCandidate{MessageID: m.ID, CreatedAt: m.CreatedAt.Local().Format(time.RFC3339),
			SenderMatches: e.ReturnedFrom != "" && strings.EqualFold(m.SenderEmail, e.ReturnedFrom)})
	}
	return out, nil
}

// IngestDSN records one spool file exactly once (key = Maildir unique name):
// a correlated report becomes a message event (dsn_spool) with projection,
// outbox and suppression policy in one transaction; anything else an
// unmatched_dsns row. Replays of the same key change nothing.
func (s *Store) IngestDSN(ctx context.Context, in DSNInput) (DSNResult, error) {
	var res DSNResult
	t, err := s.begin(ctx)
	if err != nil {
		return res, err
	}
	defer t.Rollback(ctx)
	var done bool
	if err := t.QueryRow(ctx, `
SELECT EXISTS (SELECT 1 FROM message_events WHERE event_source = 'dsn_spool' AND source_event_key = $1)
    OR EXISTS (SELECT 1 FROM unmatched_dsns WHERE spool_ingest_key = $1)`, in.Key).Scan(&done); err != nil {
		return res, err
	}
	if done {
		res.Result = DSNAlready
		return res, nil
	}
	msg, method, why, cands, err := t.correlate(ctx, in.Evidence, in.ReceivedAt)
	if err != nil {
		return res, err
	}
	if msg == nil {
		res.Result, res.Reason, res.Candidates = DSNUnmatched, why, len(cands)
		if err := t.insertUnmatched(ctx, in, why, cands); err != nil {
			return res, err
		}
		return res, t.commit(ctx)
	}
	res.MessageID, res.Correlation = msg.ID, method
	if err := t.lockJobs(ctx, []string{msg.JobID}); err != nil {
		return res, err
	}
	if err := t.QueryRow(ctx, `SELECT current_status FROM messages WHERE id = $1 FOR UPDATE`, msg.ID).Scan(&msg.Status); err != nil {
		return res, err
	}
	out := dsn.Interpret(in.Report, msg.Recipient)
	ev := dsnEvent(in.Report, out, msg, in.ReceivedAt)
	ev.Source, ev.Key = "dsn_spool", in.Key
	ev.Metadata["correlation"] = method
	ev.Metadata["spool_key"] = in.Key
	switch {
	case out.Event != "":
		res.Result, res.EventType = DSNEvent, out.Event
	case out.Informational:
		res.Result, res.Reason = DSNInformational, out.Reason
		return res, nil // nothing to record; the file stays in done/ until retention
	default:
		res.Result, res.EventType, res.Reason = DSNPartial, "dsn_unmatched", out.Reason
	}
	m := &msgState{ID: msg.ID, JobID: msg.JobID, ClientID: msg.ClientID, Status: msg.Status}
	if _, err := t.appendEvent(ctx, m, ev); err != nil {
		return res, err
	}
	if _, err := t.maybeComplete(ctx, msg.JobID); err != nil {
		return res, err
	}
	if err := t.commit(ctx); err != nil {
		return res, err
	}
	res.Suppressions = t.suppressions
	return res, nil
}

// dsnEvent builds the message event of an interpreted report.
func dsnEvent(r *dsn.Report, out dsn.Outcome, msg *DSNMessage, received time.Time) Event {
	ev := Event{MessageID: msg.ID, Metadata: map[string]any{"dsn_kind": string(r.Kind)}}
	if out.Event != "" {
		ev.Type, ev.Enhanced, ev.SMTPCode, ev.RemoteHost, ev.Diagnostic = out.Event, out.Enhanced, out.SMTPCode, out.RemoteHost, out.Diagnostic
		if out.Event != "complaint" {
			ev.FailureScope = out.FailureScope
		}
	} else {
		ev.Type, ev.Diagnostic = "dsn_unmatched", out.Reason
	}
	if out.Action != "" {
		ev.Metadata["action"] = out.Action
	}
	if out.FeedbackType != "" {
		ev.Metadata["feedback_type"] = out.FeedbackType
	}
	if r.ReportingMTA != "" {
		ev.Metadata["reporting_mta"] = r.ReportingMTA
	}
	if out.Recipient != nil && out.Recipient.FinalRecipient != "" && !strings.EqualFold(out.Recipient.FinalRecipient, msg.Recipient) {
		ev.Metadata["final_recipient"] = out.Recipient.FinalRecipient
	}
	if len(r.Problems) > 0 {
		ev.Metadata["problems"] = r.Problems
	}
	ev.OccurredAt = occurredAt(r, out, received, msg.CreatedAt)
	return ev
}

// occurredAt is when the reported event happened: the per-recipient
// Last-Attempt-Date, else the report's Date, else when the spool received it.
// A reported time before the message existed or more than 5 minutes after
// receipt is implausible (forged or skewed clocks); the receipt time is used.
func occurredAt(r *dsn.Report, out dsn.Outcome, received, created time.Time) time.Time {
	t := received
	switch {
	case out.Recipient != nil && !out.Recipient.LastAttempt.IsZero():
		t = out.Recipient.LastAttempt
	case !r.Date.IsZero():
		t = r.Date
	}
	if t.Before(created) || t.After(received.Add(5*time.Minute)) {
		t = received
	}
	return t.Local()
}

// insertUnmatched keeps an uncorrelated report for the operator (D-05).
func (t *tx) insertUnmatched(ctx context.Context, in DSNInput, why string, cands []DSNCandidate) error {
	r := in.Report
	out := dsn.Interpret(r, "")
	raw, lossy, truncated := storableRaw(in.Raw)
	detail := map[string]any{"kind": string(r.Kind), "reason": why, "subject": r.Subject}
	if len(r.Problems) > 0 {
		detail["problems"] = r.Problems
	}
	if out.Reason != "" {
		detail["interpretation"] = out.Reason
	}
	if in.Evidence.EnvelopeID != "" {
		detail["envelope_id"] = in.Evidence.EnvelopeID
	}
	if len(in.Evidence.MessageIDs) > 0 {
		detail["message_ids"] = in.Evidence.MessageIDs
	}
	if in.Evidence.ReturnedFrom != "" {
		detail["returned_from"] = in.Evidence.ReturnedFrom
	}
	if r.DeliveredTo != "" {
		detail["delivered_to"] = r.DeliveredTo
	}
	if out.Action != "" {
		detail["action"] = out.Action
	}
	if out.Diagnostic != "" {
		detail["diagnostic"] = out.Diagnostic
	}
	if r.Feedback != nil {
		detail["feedback_type"] = r.Feedback.Type
		detail["source_ip"] = r.Feedback.SourceIP
	}
	if len(r.Recipients) > 1 {
		detail["recipient_blocks"] = len(r.Recipients)
	}
	if len(cands) > 0 { // operator hints only (D-36): never applied automatically
		detail["candidates"] = cands
	}
	if lossy {
		detail["raw_lossy_utf8"] = true
	}
	if truncated {
		detail["raw_truncated"] = true
	}
	var original, final string
	if out.Recipient != nil {
		original, final = out.Recipient.OriginalRecipient, out.Recipient.FinalRecipient
	} else if r.Feedback != nil {
		final = r.Feedback.OriginalRcptTo
	}
	enhanced := out.Enhanced
	reporting := r.ReportingMTA
	if reporting == "" && r.Feedback != nil {
		reporting = r.Feedback.ReportingMTA
	}
	rawDetail, _ := json.Marshal(detail)
	_, err := t.Exec(ctx, `
INSERT INTO unmatched_dsns (id, received_at, spool_ingest_key, content_sha256, classification, status, verp_token,
                            original_recipient, final_recipient, postfix_queue_id, reporting_mta, enhanced_status_code,
                            raw_message, detail_json)
VALUES ($1, $2, $3, $4, $5, 'open', NULLIF($6, ''), NULLIF($7, ''), NULLIF($8, ''), NULLIF($9, ''), NULLIF($10, ''), NULLIF($11, ''), $12, $13::jsonb)
ON CONFLICT (spool_ingest_key) DO NOTHING`, ids.UUIDv7(), in.ReceivedAt, in.Key, in.SHA256, out.Classification(),
		in.Evidence.VERPToken, cleanText(original, 320), cleanText(final, 320), in.Evidence.QueueID, cleanText(reporting, 255),
		enhanced, raw, string(rawDetail))
	return err
}

// storableRaw bounds the raw message and makes it valid PostgreSQL text
// (UTF-8 without NUL); the spool file remains the exact original.
func storableRaw(b []byte) (string, bool, bool) {
	truncated := false
	if len(b) > maxStoredRaw {
		b, truncated = b[:maxStoredRaw], true
	}
	s := string(b)
	lossy := !utf8.ValidString(s) || strings.ContainsRune(s, 0)
	if lossy {
		s = strings.ReplaceAll(strings.ToValidUTF8(s, "�"), "\x00", "")
	}
	return s, lossy, truncated
}

func cleanText(s string, n int) string {
	s = strings.ReplaceAll(strings.ToValidUTF8(s, "�"), "\x00", "")
	if len(s) > n {
		s = strings.ToValidUTF8(s[:n], "")
	}
	return s
}

// ---------------------------------------------------------------------------
// Operator-requested resolution (D-05)

// ResolutionResult reports one processed match request.
type ResolutionResult struct {
	UnmatchedID, MessageID, EventType, Reason string
	Matched                                   bool
	Suppressions                              []CreatedSuppression
}

// ResolveMatchRequest claims one match_requested row (SKIP LOCKED, one
// transaction, no lease), re-interprets its retained DSN for the operator's
// message and appends the transport event (source unmatched_dsn_resolution,
// key = row id) with projection, outbox and suppression policy, then marks the
// row matched with the resolution event. A DSN that reports no failure for that
// message returns the row to open with the reason in detail_json. nil when
// there is no request.
func (s *Store) ResolveMatchRequest(ctx context.Context) (*ResolutionResult, error) {
	t, err := s.begin(ctx)
	if err != nil {
		return nil, err
	}
	defer t.Rollback(ctx)
	res := &ResolutionResult{}
	var raw string
	var received time.Time
	err = t.QueryRow(ctx, `
SELECT id::text, matched_message_id::text, raw_message, received_at FROM unmatched_dsns
 WHERE status = 'match_requested'
 ORDER BY resolution_requested_at, id LIMIT 1
   FOR UPDATE SKIP LOCKED`).Scan(&res.UnmatchedID, &res.MessageID, &raw, &received)
	if errors.Is(err, pgx.ErrNoRows) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	ms, err := t.messagesWhere(ctx, `WHERE m.id = $1::uuid`, res.MessageID)
	if err != nil {
		return nil, err
	}
	if len(ms) != 1 {
		return res, t.reopen(ctx, res, "the requested message does not exist")
	}
	msg := ms[0]
	if err := t.lockJobs(ctx, []string{msg.JobID}); err != nil {
		return nil, err
	}
	if err := t.QueryRow(ctx, `SELECT current_status FROM messages WHERE id = $1 FOR UPDATE`, msg.ID).Scan(&msg.Status); err != nil {
		return nil, err
	}
	report := dsn.Parse([]byte(raw))
	out := dsn.Interpret(report, msg.Recipient)
	if out.Event == "" {
		return res, t.reopen(ctx, res, "the DSN reports no failure for this message: "+out.Reason)
	}
	ev := dsnEvent(report, out, &msg, received)
	ev.Source, ev.Key = "unmatched_dsn_resolution", res.UnmatchedID
	ev.Metadata["correlation"] = "operator"
	ev.Metadata["unmatched_dsn_id"] = res.UnmatchedID
	m := &msgState{ID: msg.ID, JobID: msg.JobID, ClientID: msg.ClientID, Status: msg.Status}
	eventID, inserted, err := t.appendEventID(ctx, m, ev)
	if err != nil {
		return nil, err
	}
	if !inserted { // the event and the row update share one transaction, so this is defensive only
		if err := t.QueryRow(ctx, `SELECT id::text FROM message_events WHERE event_source = 'unmatched_dsn_resolution' AND source_event_key = $1`,
			res.UnmatchedID).Scan(&eventID); err != nil {
			return nil, err
		}
	}
	if _, err := t.Exec(ctx, `UPDATE unmatched_dsns SET status = 'matched', resolution_event_id = $2, resolved_at = now() WHERE id = $1`,
		res.UnmatchedID, eventID); err != nil {
		return nil, err
	}
	if _, err := t.maybeComplete(ctx, msg.JobID); err != nil {
		return nil, err
	}
	if err := t.commit(ctx); err != nil {
		return nil, err
	}
	res.Matched, res.EventType, res.Suppressions = true, out.Event, t.suppressions
	return res, nil
}

// reopen returns a match request to open, keeping the reason in detail_json.
func (t *tx) reopen(ctx context.Context, res *ResolutionResult, reason string) error {
	res.Reason = reason
	failure, _ := json.Marshal(map[string]any{"at": time.Now().Format(time.RFC3339), "message_id": res.MessageID, "reason": reason})
	if _, err := t.Exec(ctx, `
UPDATE unmatched_dsns
   SET status = 'open',
       detail_json = jsonb_set(detail_json, '{resolution_failures}',
                               COALESCE(detail_json -> 'resolution_failures', '[]'::jsonb) || jsonb_build_array($2::jsonb))
 WHERE id = $1`, res.UnmatchedID, string(failure)); err != nil {
		return err
	}
	return t.commit(ctx)
}
