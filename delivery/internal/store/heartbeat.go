package store

import (
	"context"
	"errors"
	"time"

	"github.com/jackc/pgx/v5"
)

// Heartbeat is one delivery daemon's durable status row (delivery_heartbeats,
// Phase 8 / specification 2.9). The operator dashboard reads it instead of
// running Postfix commands: the daemon's delivery state, its warm-up ceiling and
// the Postfix queue depth from the newest queue snapshot.
type Heartbeat struct {
	WorkerID, Version string
	StartedAt         time.Time
	LiveDelivery      bool
	SendWorkHeld      bool // production before live activation
	OutboundPaused    bool // the operator's emergency pause
	GlobalRatePerMin  int
	// Queue: nil when there is no snapshot yet.
	QueueSnapshotAt              *time.Time
	QueueActive, QueueDeferred   *int
	QueueHold, QueueIncoming     *int
	Submitted, TemporaryFailures int64
	DSNsProcessed                int64
}

// Beat inserts or refreshes the daemon's row.
func (s *Store) Beat(ctx context.Context, h Heartbeat) error {
	_, err := s.Pool.Exec(ctx, `
INSERT INTO delivery_heartbeats (worker_id, version, started_at, last_seen_at, live_delivery, send_work_held, outbound_paused,
       global_rate_per_minute, queue_snapshot_at, queue_active, queue_deferred, queue_hold, queue_incoming,
       submitted, temporary_failures, dsns_processed)
VALUES ($1, $2, $3, now(), $4, $5, $6, $7, $8, $9, $10, $11, $12, $13, $14, $15)
ON CONFLICT (worker_id) DO UPDATE SET last_seen_at = now(), stopped_at = NULL, live_delivery = EXCLUDED.live_delivery,
       send_work_held = EXCLUDED.send_work_held, outbound_paused = EXCLUDED.outbound_paused,
       global_rate_per_minute = EXCLUDED.global_rate_per_minute, queue_snapshot_at = EXCLUDED.queue_snapshot_at,
       queue_active = EXCLUDED.queue_active, queue_deferred = EXCLUDED.queue_deferred, queue_hold = EXCLUDED.queue_hold,
       queue_incoming = EXCLUDED.queue_incoming, submitted = EXCLUDED.submitted,
       temporary_failures = EXCLUDED.temporary_failures, dsns_processed = EXCLUDED.dsns_processed`,
		h.WorkerID, h.Version, h.StartedAt, h.LiveDelivery, h.SendWorkHeld, h.OutboundPaused, h.GlobalRatePerMin,
		h.QueueSnapshotAt, h.QueueActive, h.QueueDeferred, h.QueueHold, h.QueueIncoming,
		h.Submitted, h.TemporaryFailures, h.DSNsProcessed)
	return err
}

// Stopped marks the daemon's row as stopped (graceful shutdown).
func (s *Store) Stopped(ctx context.Context, workerID string) error {
	_, err := s.Pool.Exec(ctx, `UPDATE delivery_heartbeats SET stopped_at = now(), last_seen_at = now() WHERE worker_id = $1`, workerID)
	return err
}

// EmergencyStop reports the installation-wide emergency stop set from the web
// application (delivery_controls, specification 2.11). No row means not stopped.
func (s *Store) EmergencyStop(ctx context.Context) (bool, error) {
	var stopped bool
	err := s.Pool.QueryRow(ctx, `SELECT emergency_stop FROM delivery_controls WHERE id = 1`).Scan(&stopped)
	if errors.Is(err, pgx.ErrNoRows) {
		return false, nil
	}
	return stopped, err
}
