// Package ingest follows the Postfix log in the shared observability volume
// (read-only; postfix-integration.md §3) with the persistent cursor in
// delivery_ingest_cursors (generation fingerprint + byte position), and turns
// Smarthost messages' records into append-only message events.
//
// Rotation: the cursor names a generation by its first-record fingerprint, so
// after `postfix logrotate` the rest of the previous generation is read from its
// .gz and ingestion then moves to the new active file at position 0. If the
// cursor's generation is no longer retained, the gap is logged (an operator
// alert) and ingestion restarts from the oldest retained generation; re-reading
// is harmless because postfix_log event keys are unique.
package ingest

import (
	"context"
	"errors"
	"time"

	"smarthost.local/delivery/internal/logx"
	"smarthost.local/delivery/internal/postfixlog"
	"smarthost.local/delivery/internal/store"
)

// BatchLines is the maximum number of records per transaction.
const BatchLines = 2000

// Ingester follows the log.
type Ingester struct {
	Store        *store.Store
	Log          *logx.Logger
	Dir          string // <observability>/log
	BounceDomain string
	Interval     time.Duration
	Now          func() time.Time
}

// Run ingests every Interval until ctx ends.
func (g *Ingester) Run(ctx context.Context) {
	t := time.NewTicker(g.Interval)
	defer t.Stop()
	for {
		if err := g.Pass(ctx); err != nil && ctx.Err() == nil && !errors.Is(err, store.ErrBusy) {
			g.Log.Warning("log ingestion pass failed", "error", err)
		}
		select {
		case <-ctx.Done():
			return
		case <-t.C:
		}
	}
}

// Pass ingests everything currently available (bounded number of batches).
func (g *Ingester) Pass(ctx context.Context) error {
	now := g.Now
	if now == nil {
		now = time.Now
	}
	for i := 0; i < 500 && ctx.Err() == nil; i++ {
		gens, err := postfixlog.List(g.Dir)
		if err != nil || len(gens) == 0 {
			return err
		}
		cur, ok, err := g.Store.GetCursor(ctx)
		if err != nil {
			return err
		}
		idx, pos := 0, int64(0)
		if ok {
			idx = -1
			for j, gen := range gens {
				if gen.ID == cur.GenerationID {
					idx, pos = j, cur.Position
				}
			}
			if idx < 0 {
				g.Log.Error("Postfix log gap: the cursor's generation is no longer retained; re-reading the retained log",
					"generation_id", cur.GenerationID, "position", cur.Position)
				idx, pos = 0, 0
			}
		}
		gen := gens[idx]
		rd, err := postfixlog.Open(gen, pos)
		if errors.Is(err, postfixlog.ErrRotated) {
			continue
		}
		if err != nil {
			return err
		}
		var recs []postfixlog.Located
		lines := 0
		ts := now()
		for lines < BatchLines {
			l, more, err := rd.Next()
			if err != nil {
				rd.Close()
				return err
			}
			if !more {
				break
			}
			lines++
			if r, ok := postfixlog.Parse(l.Text, ts); ok {
				recs = append(recs, postfixlog.Located{Record: r, GenerationID: gen.ID, Pos: l.Pos})
			}
		}
		next := rd.Pos()
		rd.Close()
		if lines == 0 {
			if !gen.Active && idx+1 < len(gens) { // finished a rotated generation
				if _, err := g.Store.ApplyLogBatch(ctx, nil, gens[idx+1].ID, 0, true, g.BounceDomain, ts); err != nil {
					return err
				}
				continue
			}
			if !ok || cur.GenerationID != gen.ID || cur.Position != pos {
				_, err := g.Store.ApplyLogBatch(ctx, nil, gen.ID, pos, true, g.BounceDomain, ts)
				return err
			}
			return nil
		}
		res, err := g.Store.ApplyLogBatch(ctx, recs, gen.ID, next, true, g.BounceDomain, ts)
		if err != nil {
			return err
		}
		if res.Applied > 0 || res.Completed > 0 {
			g.Log.Debug("ingested Postfix log records", "events", res.Applied, "jobs_completed", res.Completed, "generation_id", gen.ID)
		}
		if res.Held || (gen.Active && lines < BatchLines) {
			return nil
		}
	}
	return nil
}
