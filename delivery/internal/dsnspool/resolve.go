package dsnspool

import (
	"context"
	"time"

	"smarthost.local/delivery/internal/logx"
	"smarthost.local/delivery/internal/store"
)

// NotifyChannel is the channel Symfony notifies when an operator requests a
// match (UnmatchedDsnAdministration::NOTIFY_CHANNEL).
const NotifyChannel = "smarthost_unmatched_dsn_work"

// Resolver applies operator-requested unmatched-DSN resolutions (D-05): it
// wakes on NOTIFY and polls, and processes every match_requested row.
type Resolver struct {
	Store        *store.Store
	Log          *logx.Logger
	PollInterval time.Duration
}

// Run resolves requests until ctx ends.
func (r *Resolver) Run(ctx context.Context) {
	wake := make(chan struct{}, 1)
	go r.listen(ctx, wake)
	for ctx.Err() == nil {
		if _, err := r.Pass(ctx); err != nil && ctx.Err() == nil {
			r.Log.Warning("unmatched-DSN resolution pass failed", "error", err)
		}
		select {
		case <-ctx.Done():
		case <-wake:
		case <-time.After(r.PollInterval):
		}
	}
}

// Pass processes all pending match requests; it returns how many it handled.
func (r *Resolver) Pass(ctx context.Context) (int, error) {
	n := 0
	for ctx.Err() == nil {
		res, err := r.Store.ResolveMatchRequest(ctx)
		if err != nil || res == nil {
			return n, err
		}
		n++
		if res.Matched {
			r.Log.Info("unmatched DSN resolved", "unmatched_dsn_id", res.UnmatchedID, "message_id", res.MessageID, "event", res.EventType)
			for _, s := range res.Suppressions {
				r.Log.Info("global suppression created", "suppression_id", s.ID, "reason", s.Reason, "message_id", s.MessageID)
			}
		} else {
			r.Log.Warning("unmatched DSN match request could not be applied; returned to open",
				"unmatched_dsn_id", res.UnmatchedID, "message_id", res.MessageID, "reason", res.Reason)
		}
	}
	return n, ctx.Err()
}

func (r *Resolver) listen(ctx context.Context, wake chan<- struct{}) {
	for ctx.Err() == nil {
		err := func() error {
			conn, err := r.Store.Pool.Acquire(ctx)
			if err != nil {
				return err
			}
			defer conn.Release()
			if _, err := conn.Exec(ctx, "LISTEN "+NotifyChannel); err != nil {
				return err
			}
			for {
				if _, err := conn.Conn().WaitForNotification(ctx); err != nil {
					return err
				}
				select {
				case wake <- struct{}{}:
				default:
				}
			}
		}()
		if ctx.Err() != nil {
			return
		}
		r.Log.Warning("unmatched-DSN listener reconnecting", "error", err)
		select {
		case <-ctx.Done():
		case <-time.After(r.PollInterval):
		}
	}
}
