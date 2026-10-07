//go:build integration

package integration

import (
	"context"
	"os"
	"path/filepath"
	"testing"
	"time"

	"smarthost.local/delivery/internal/snapshot"
	"smarthost.local/delivery/internal/store"
	"smarthost.local/delivery/internal/worker"
)

// onlyThisJobIsClaimable cancels every other claimable job of the shared test
// database: these tests run the real claim loop, which takes the oldest claimable
// job of any test (the other tests claim their own jobs directly).
func (e *env) onlyThisJobIsClaimable(job string) {
	e.exec(`UPDATE send_jobs SET status = 'cancelled', lease_expires_at = NULL
             WHERE status IN ('queued', 'processing') AND id <> $1`, job)
}

// run starts the worker loop for d and returns when it has stopped.
func runFor(w *worker.Worker, parent context.Context, d time.Duration) {
	ctx, cancel := context.WithTimeout(parent, d)
	defer cancel()
	w.Run(ctx)
}

// Phase 8: production before live activation claims nothing; capture mode
// (development/test) and live production do.
func TestProductionWithoutLiveDeliveryHoldsSendWork(t *testing.T) {
	e := newEnv(t)
	job := e.newJob(jobOpts{}, e.rcpts(2))
	e.onlyThisJobIsClaimable(job)
	e.cfg.Env = "production"
	e.cfg.LiveDelivery = false
	w := e.worker("held")
	if !w.Holding() || !w.Lim.Held() {
		t.Fatal("production without live delivery must hold send work")
	}
	runFor(w, e.ctx, 2500*time.Millisecond)
	if st := e.jobStatus(job); st != "queued" {
		t.Fatalf("held production claimed the job (%s)", st)
	}
	if e.count(`SELECT count(*) FROM messages m JOIN send_jobs j ON j.id = m.send_job_id WHERE j.id = $1`, job) != 0 {
		t.Fatal("held production created messages")
	}
}

// The operator's emergency pause (a flag in the observability volume, written by
// Postfix's smarthost-postfix-control) stops claims and new submissions within a
// poll interval, and lifting it resumes them.
func TestEmergencyPauseFlagStopsAndResumes(t *testing.T) {
	e := newEnv(t)
	flag := filepath.Join(e.obs, "control", "outbound-paused")
	if err := os.MkdirAll(filepath.Dir(flag), 0o750); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(flag, []byte("2026-10-07T00:00:00Z test\n"), 0o640); err != nil {
		t.Fatal(err)
	}
	// Its own recipient domain: the claim loop's deferral feedback would otherwise back
	// off the shared fixture domain after the deferrals of earlier tests.
	job := e.newJob(jobOpts{}, e.rcpts(3, "pause-resume.test"))
	e.onlyThisJobIsClaimable(job)
	w := e.worker("paused")
	runFor(w, e.ctx, 2500*time.Millisecond)
	if !w.Paused() || e.jobStatus(job) != "queued" {
		t.Fatalf("paused worker: paused=%v job=%s", w.Paused(), e.jobStatus(job))
	}
	if err := os.Remove(flag); err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithTimeout(e.ctx, 60*time.Second)
	defer cancel()
	done := make(chan struct{})
	go func() { w.Run(ctx); close(done) }()
	for e.jobStatus(job) != "dispatched" && ctx.Err() == nil {
		time.Sleep(200 * time.Millisecond)
	}
	cancel()
	<-done
	if w.Paused() || e.jobStatus(job) != "dispatched" {
		t.Fatalf("after the pause was lifted: paused=%v job=%s", w.Paused(), e.jobStatus(job))
	}
}

// The web emergency stop (delivery_controls, specification 2.11) holds send work like
// the pause flag, read by the delivery role, and lifting it resumes submissions.
func TestWebEmergencyStopHoldsAndResumes(t *testing.T) {
	e := newEnv(t)
	e.exec(`INSERT INTO delivery_controls (id, emergency_stop, changed_at) VALUES (1, true, now())
	        ON CONFLICT (id) DO UPDATE SET emergency_stop = true, changed_at = now()`)
	t.Cleanup(func() { e.exec(`UPDATE delivery_controls SET emergency_stop = false, changed_at = now() WHERE id = 1`) })
	job := e.newJob(jobOpts{}, e.rcpts(2, "web-stop.test"))
	e.onlyThisJobIsClaimable(job)
	w := e.worker("web-stop")
	runFor(w, e.ctx, 2500*time.Millisecond)
	if !w.Paused() || e.jobStatus(job) != "queued" {
		t.Fatalf("stopped worker: paused=%v job=%s", w.Paused(), e.jobStatus(job))
	}
	e.exec(`UPDATE delivery_controls SET emergency_stop = false, changed_at = now() WHERE id = 1`)
	ctx, cancel := context.WithTimeout(e.ctx, 60*time.Second)
	defer cancel()
	done := make(chan struct{})
	go func() { w.Run(ctx); close(done) }()
	for e.jobStatus(job) != "dispatched" && ctx.Err() == nil {
		time.Sleep(200 * time.Millisecond)
	}
	cancel()
	<-done
	if w.Paused() || e.jobStatus(job) != "dispatched" {
		t.Fatalf("after the stop was lifted: paused=%v job=%s", w.Paused(), e.jobStatus(job))
	}
}

// A throttled client is paced by DELIVERY_THROTTLED_CLIENT_RATE_PER_MINUTE across
// all its recipients and domains; the installation-wide ceiling applies to all.
func TestThrottledClientAndGlobalCeiling(t *testing.T) {
	e := newEnv(t)
	slow := e.newClient("throttled")
	job := e.newJob(jobOpts{client: slow, domain: e.newDomain(slow, "verified")}, e.rcpts(3, "a.test", "b.test", "c.test"))
	e.cfg.ThrottledClientRate = 60 // one per second
	w := e.worker("throttle")
	j := e.claim(w)
	if !j.Throttled.Load() {
		t.Fatal("the throttled status was not loaded with the job")
	}
	start := time.Now()
	w.ProcessJob(e.ctx, j)
	if took := time.Since(start); took < 1900*time.Millisecond || e.jobStatus(job) != "dispatched" {
		t.Fatalf("3 submissions of a throttled client took %v (want >= 2 s); job %s", took, e.jobStatus(job))
	}

	job2 := e.newJob(jobOpts{}, e.rcpts(5, "d.test", "e.test", "f.test", "g.test", "h.test"))
	e.cfg.ThrottledClientRate = 10
	e.cfg.GlobalRatePerMin = 120 // one every 0.5 s for the whole daemon
	w2 := e.worker("ceiling")
	j2 := e.claim(w2)
	start = time.Now()
	w2.ProcessJob(e.ctx, j2)
	if took := time.Since(start); took < 1900*time.Millisecond || e.jobStatus(job2) != "dispatched" {
		t.Fatalf("5 submissions under a 120/min ceiling took %v (want >= 2 s); job %s", took, e.jobStatus(job2))
	}
}

// delivery_heartbeats: the durable status the dashboard reads (queue depth from the
// newest snapshot, delivery state, warm-up ceiling).
func TestDeliveryHeartbeatRecordsStateAndQueueDepth(t *testing.T) {
	e := newEnv(t)
	now := time.Now().UTC().Truncate(time.Second)
	e.snapshot(now, "Q1", "Q2", "Q3")
	at, counts, ok, err := snapshot.Depth(filepath.Join(e.obs, "queue"))
	if err != nil || !ok || counts["deferred"] != 3 {
		t.Fatalf("depth %v %v %v", counts, ok, err)
	}
	active, deferred, hold, incoming := counts["active"], counts["deferred"], counts["hold"], counts["incoming"]
	id := "it-heartbeat-" + now.Format("150405.000")
	t.Cleanup(func() { e.exec(`DELETE FROM delivery_heartbeats WHERE worker_id = $1`, id) })
	h := store.Heartbeat{WorkerID: id, Version: "test", StartedAt: now, LiveDelivery: false, SendWorkHeld: true, OutboundPaused: false,
		GlobalRatePerMin: 5, QueueSnapshotAt: &at, QueueActive: &active, QueueDeferred: &deferred, QueueHold: &hold, QueueIncoming: &incoming,
		Submitted: 7}
	if err := e.st.Beat(e.ctx, h); err != nil {
		t.Fatal(err)
	}
	h.OutboundPaused, h.Submitted = true, 8
	if err := e.st.Beat(e.ctx, h); err != nil {
		t.Fatal(err)
	}
	if e.count(`SELECT count(*) FROM delivery_heartbeats WHERE worker_id = $1 AND outbound_paused AND send_work_held
                 AND queue_deferred = 3 AND queue_active = 0 AND submitted = 8 AND global_rate_per_minute = 5 AND stopped_at IS NULL`, id) != 1 {
		t.Fatal("heartbeat row not upserted with the latest state")
	}
	if err := e.st.Stopped(e.ctx, id); err != nil || e.count(`SELECT count(*) FROM delivery_heartbeats WHERE worker_id = $1 AND stopped_at IS NOT NULL`, id) != 1 {
		t.Fatalf("stopped not recorded: %v", err)
	}
	// The queue columns are all set or all empty (no snapshot yet).
	h.WorkerID, h.QueueDeferred = id+"-partial", nil
	if err := e.st.Beat(e.ctx, h); err == nil {
		e.exec(`DELETE FROM delivery_heartbeats WHERE worker_id = $1`, h.WorkerID)
		t.Fatal("a partial queue depth was accepted")
	}
}
