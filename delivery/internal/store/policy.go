package store

import (
	"context"
	"errors"
	"sort"
	"time"

	"github.com/jackc/pgx/v5"

	"smarthost.local/delivery/internal/ids"
)

// PolicyConfig is the global suppression policy configuration (D-18, D-30).
type PolicyConfig struct {
	SoftBounceThreshold int           // DELIVERY_SOFT_BOUNCE_SUPPRESSION_THRESHOLD
	SoftBounceWindow    time.Duration // DELIVERY_SOFT_BOUNCE_SUPPRESSION_WINDOW_DAYS
}

// DefaultPolicy is the contract default (3 within 30 days).
var DefaultPolicy = PolicyConfig{SoftBounceThreshold: 3, SoftBounceWindow: 30 * 24 * time.Hour}

// CreatedSuppression is a suppression the policy created.
type CreatedSuppression struct {
	ID, Address, Reason, MessageID, EventID string
}

type policyItem struct {
	messageID, eventID, eventType, scope string
	address                              string
}

// applyPolicy turns the authoritative events appended in this transaction into
// global address suppressions (spec suppression_and_reputation.global_suppression_policy):
//
//   - hard_bounce with failure_scope recipient   -> hard_bounce (indefinite)
//   - complaint (correlated to an exact message) -> complaint (indefinite)
//   - soft_bounce with failure_scope recipient   -> repeated_soft_bounce when the
//     address now has DELIVERY_SOFT_BOUNCE_SUPPRESSION_THRESHOLD consecutive
//     recipient soft bounces (distinct messages, all clients) within the window,
//     expiring after the window (a temporary condition never becomes permanent)
//
// Every other scope (domain, provider policy, connection, DNS, infrastructure,
// unknown) creates nothing. Suppressions are global (client_id NULL) and carry
// source_message_id and source_event_id.
//
// Concurrency: a transaction-scoped advisory lock per address serialises the
// check-and-insert, so concurrent evidence (several workers, a log record and a
// DSN about the same failure) creates at most one active row per address and
// reason; the partial unique index suppressions_global_address_reason_active_uq
// is the final guarantee for hard_bounce and complaint. Items are processed in
// address order, so two transactions never wait on each other's address locks
// in opposite orders.
func (t *tx) applyPolicy(ctx context.Context) error {
	if len(t.policy) == 0 {
		return nil
	}
	items := t.policy
	t.policy = nil
	var mids []string
	for _, it := range items {
		mids = append(mids, it.messageID)
	}
	rows, err := t.Query(ctx, `SELECT id::text, recipient_address FROM messages WHERE id = ANY($1::text[]::uuid[])`, mids)
	if err != nil {
		return err
	}
	addr := map[string]string{}
	for rows.Next() {
		var id, a string
		if err := rows.Scan(&id, &a); err != nil {
			rows.Close()
			return err
		}
		addr[id] = a
	}
	rows.Close()
	if err := rows.Err(); err != nil {
		return err
	}
	for i := range items {
		items[i].address = addr[items[i].messageID]
	}
	sort.SliceStable(items, func(i, j int) bool { return items[i].address < items[j].address })
	locked := map[string]bool{}
	for _, it := range items {
		var reason string
		var expires *time.Time
		switch {
		case it.eventType == "hard_bounce" && it.scope == "recipient":
			reason = "hard_bounce"
		case it.eventType == "complaint":
			reason = "complaint"
		case it.eventType == "soft_bounce" && it.scope == "recipient":
			reason = "repeated_soft_bounce"
		default:
			continue // not attributable to the recipient: never suppresses
		}
		if it.address == "" {
			continue
		}
		if !locked[it.address] {
			if _, err := t.Exec(ctx, `SELECT pg_advisory_xact_lock(hashtextextended('smarthost-suppression|' || $1, 0))`, it.address); err != nil {
				return err
			}
			locked[it.address] = true
		}
		if reason == "repeated_soft_bounce" {
			n, err := t.consecutiveSoftBounces(ctx, it.address)
			if err != nil {
				return err
			}
			if n < t.cfg.SoftBounceThreshold {
				continue
			}
			until := time.Now().Add(t.cfg.SoftBounceWindow)
			expires = &until
		}
		var active bool
		if err := t.QueryRow(ctx, `
SELECT EXISTS (SELECT 1 FROM suppressions
                WHERE client_id IS NULL AND scope_type = 'address' AND address_or_domain = $1 AND reason = $2
                  AND lifted_at IS NULL AND (expires_at IS NULL OR expires_at > now()))`, it.address, reason).Scan(&active); err != nil {
			return err
		}
		if active {
			continue // already suppressed for this reason: repeated evidence adds nothing
		}
		var id string
		err := t.QueryRow(ctx, `
INSERT INTO suppressions (id, client_id, address_or_domain, scope_type, reason, source_message_id, source_event_id, expires_at)
VALUES ($1, NULL, $2, 'address', $3, $4, $5, $6)
ON CONFLICT DO NOTHING
RETURNING id::text`, ids.UUIDv7(), it.address, reason, it.messageID, it.eventID, expires).Scan(&id)
		if errors.Is(err, pgx.ErrNoRows) {
			continue
		}
		if err != nil {
			return err
		}
		t.suppressions = append(t.suppressions, CreatedSuppression{ID: id, Address: it.address, Reason: reason, MessageID: it.messageID, EventID: it.eventID})
	}
	return nil
}

// consecutiveSoftBounces counts the distinct messages to the address (any
// client, D-30) with a recipient-scope soft bounce inside the rolling window
// and after the address's latest acceptance (which resets the sequence, D-18).
// An acceptance counts as a reset only when the same message was not
// soft-bounced afterwards: a relay may accept a message whose final mailbox
// then returns a DSN, and that message is a failure, not a success. Soft
// bounces of other scopes neither count nor reset. A Postfix log bounce and the
// DSN about the same message are one occurrence.
func (t *tx) consecutiveSoftBounces(ctx context.Context, address string) (int, error) {
	var n int
	err := t.QueryRow(ctx, `
WITH ev AS (
  SELECT e.message_id, e.event_type, e.failure_scope, e.occurred_at
    FROM messages m JOIN message_events e ON e.message_id = m.id
   WHERE m.recipient_address = $1
     AND e.event_type IN ('soft_bounce', 'remote_accepted')
     AND e.occurred_at > now() - make_interval(secs => $2))
SELECT count(DISTINCT message_id) FROM ev
 WHERE event_type = 'soft_bounce' AND failure_scope = 'recipient'
   AND occurred_at > COALESCE((SELECT max(r.occurred_at) FROM ev r
                                WHERE r.event_type = 'remote_accepted'
                                  AND NOT EXISTS (SELECT 1 FROM ev f WHERE f.message_id = r.message_id
                                                    AND f.event_type = 'soft_bounce' AND f.occurred_at >= r.occurred_at)),
                               '-infinity'::timestamptz)`,
		address, t.cfg.SoftBounceWindow.Seconds()).Scan(&n)
	return n, err
}

// RecordSuppressed marks a queued message suppressed immediately before
// submission because a suppression became active after the message was created
// (D-30: a suppression prevents every future submission, whichever client caused
// it). Fenced like every worker write; false when the message is no longer
// queued without a queue id.
func (s *Store) RecordSuppressed(ctx context.Context, job *Job, me, messageID string, sup Suppression) (bool, error) {
	t, err := s.begin(ctx)
	if err != nil {
		return false, err
	}
	defer t.Rollback(ctx)
	if err := t.fence(ctx, job.ID, me); err != nil {
		return false, err
	}
	var cur string
	err = t.QueryRow(ctx, `SELECT current_status FROM messages WHERE id = $1 AND postfix_queue_id IS NULL FOR UPDATE`, messageID).Scan(&cur)
	if errors.Is(err, pgx.ErrNoRows) {
		return false, nil
	}
	if err != nil {
		return false, err
	}
	if cur != "queued" {
		return false, nil
	}
	m := &msgState{ID: messageID, JobID: job.ID, ClientID: job.ClientID, Status: cur}
	inserted, err := t.appendEvent(ctx, m, Event{MessageID: messageID, Type: "message_suppressed", Source: "delivery_daemon",
		Key: "message_suppressed:" + messageID, OccurredAt: time.Now(),
		Metadata: map[string]any{"suppression_id": sup.ID, "reason": sup.Reason, "checked": "before_submission"}})
	if err != nil {
		return false, err
	}
	return inserted, t.commit(ctx)
}
