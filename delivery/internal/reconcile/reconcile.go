// Package reconcile implements D-27 (postfix-integration.md §6): messages that
// left Postfix's queue without a recorded outcome are never assumed delivered.
//
// A candidate (submitted/deferred, with a queue id, no event for the grace
// interval) is only examined against fresh queue snapshots. If its queue id is
// still queued, nothing happens. If it is absent from at least
// DELIVERY_RECONCILE_MIN_SNAPSHOTS consecutive fresh snapshots all taken after
// the grace interval, the retained log is re-scanned for an authoritative
// outcome (ingested normally). Only if none is found (and no unmatched DSN may
// hold it) does the message get transport_outcome_unknown -> outcome_unknown,
// which is not a success and is superseded by any later authoritative event.
// Stale snapshots suspend all conclusions. One instance at a time
// (advisory lock).
package reconcile

import (
	"context"
	"path/filepath"
	"time"

	"smarthost.local/delivery/internal/logx"
	"smarthost.local/delivery/internal/postfixlog"
	"smarthost.local/delivery/internal/snapshot"
	"smarthost.local/delivery/internal/store"
)

// Reconciler runs reconciliation passes.
type Reconciler struct {
	Store            *store.Store
	Log              *logx.Logger
	ObservabilityDir string
	BounceDomain     string
	Interval         time.Duration
	Grace            time.Duration
	MinSnapshots     int
	SnapshotInterval time.Duration
	Now              func() time.Time
}

// Result of one pass (tests and logs).
type Result struct {
	Stale, Locked             bool
	Candidates, StillQueued   int
	Waiting, Rescanned        int
	Recovered, OutcomeUnknown int
	HeldByDSN, JobsCompleted  int
}

// Run reconciles every Interval until ctx ends.
func (r *Reconciler) Run(ctx context.Context) {
	t := time.NewTicker(r.Interval)
	defer t.Stop()
	for {
		res, err := r.Pass(ctx)
		switch {
		case err != nil && ctx.Err() == nil:
			r.Log.Warning("reconciliation pass failed", "error", err)
		case res.OutcomeUnknown > 0 || res.Recovered > 0:
			r.Log.Info("reconciliation", "outcome_unknown", res.OutcomeUnknown, "recovered", res.Recovered, "candidates", res.Candidates)
		}
		select {
		case <-ctx.Done():
			return
		case <-t.C:
		}
	}
}

// Pass runs one reconciliation pass.
func (r *Reconciler) Pass(ctx context.Context) (Result, error) {
	var res Result
	now := time.Now
	if r.Now != nil {
		now = r.Now
	}
	release, ok, err := r.Store.TryReconcileLock(ctx)
	if err != nil || !ok {
		res.Locked = !ok
		return res, err
	}
	defer release()
	if n, err := r.Store.SweepCompletion(ctx, 100); err == nil {
		res.JobsCompleted += n
	}
	snaps, err := snapshot.List(filepath.Join(r.ObservabilityDir, "queue"))
	if err != nil {
		return res, err
	}
	t := now()
	if !snapshot.Fresh(snaps, r.SnapshotInterval, t) {
		res.Stale = true
		age := "none"
		if len(snaps) > 0 {
			age = t.Sub(snaps[len(snaps)-1].Time).Round(time.Second).String()
		}
		r.Log.Warning("Postfix queue snapshots are stale: no reconciliation conclusions", "postfix_queue_snapshot_age", age)
		return res, nil
	}
	run := snapshot.ConsecutiveFresh(snaps, r.SnapshotInterval, t)
	cache := map[string]map[string]string{}
	load := func(s snapshot.Snapshot) (map[string]string, error) {
		if m, ok := cache[s.Path]; ok {
			return m, nil
		}
		m, err := snapshot.Load(s.Path)
		cache[s.Path] = m
		return m, err
	}
	newest, err := load(run[len(run)-1])
	if err != nil {
		return res, err
	}
	cands, err := r.Store.Candidates(ctx, r.Grace, 1000)
	if err != nil {
		return res, err
	}
	res.Candidates = len(cands)
	var absent []store.Candidate
	oldest := t
	for _, c := range cands {
		if _, queued := newest[c.QueueID]; queued {
			res.StillQueued++
			continue
		}
		missing := 0
		for i := len(run) - 1; i >= 0; i-- {
			s := run[i]
			if s.Time.Before(c.LastEvent.Add(r.Grace)) {
				break
			}
			ids, err := load(s)
			if err != nil {
				return res, err
			}
			if _, q := ids[c.QueueID]; q {
				missing = -1
				break
			}
			missing++
		}
		if missing < r.MinSnapshots {
			res.Waiting++
			continue
		}
		absent = append(absent, c)
		if c.LastEvent.Before(oldest) {
			oldest = c.LastEvent
		}
	}
	if len(absent) == 0 {
		return res, nil
	}
	// Re-scan the retained log for an authoritative outcome of those queue ids.
	qids := map[string]bool{}
	for _, c := range absent {
		qids[c.QueueID] = true
	}
	recs, err := postfixlog.FindQueueIDs(filepath.Join(r.ObservabilityDir, "log"), oldest.Add(-24*time.Hour), qids)
	if err != nil {
		return res, err
	}
	res.Rescanned = len(recs)
	byGen := map[string][]postfixlog.Located{}
	var order []string
	for _, l := range recs {
		if _, ok := byGen[l.GenerationID]; !ok {
			order = append(order, l.GenerationID)
		}
		byGen[l.GenerationID] = append(byGen[l.GenerationID], l)
	}
	for _, g := range order {
		if _, err := r.Store.ApplyLogBatch(ctx, byGen[g], g, 0, false, r.BounceDomain, t); err != nil {
			return res, err
		}
	}
	for _, c := range absent {
		st, err := r.Store.MessageStatus(ctx, c.MessageID)
		if err != nil {
			return res, err
		}
		if st != "submitted" && st != "deferred" {
			res.Recovered++ // the re-scan found the authoritative outcome
			continue
		}
		if held, err := r.Store.PendingDSN(ctx, c.VERPToken, c.QueueID); err != nil {
			return res, err
		} else if held {
			res.HeldByDSN++
			continue
		}
		ok, err := r.Store.MarkOutcomeUnknown(ctx, c, map[string]any{
			"postfix_queue_id": c.QueueID, "absent_from_snapshots": r.MinSnapshots,
			"grace_seconds": int(r.Grace.Seconds()), "last_event_at": c.LastEvent.Local().Format(time.RFC3339)})
		if err != nil {
			return res, err
		}
		if ok {
			res.OutcomeUnknown++
			r.Log.Warning("transport outcome unknown: queue id left Postfix without a recoverable outcome",
				"message_id", c.MessageID, "job_id", c.JobID, "postfix_queue_id", c.QueueID)
		}
	}
	return res, nil
}
