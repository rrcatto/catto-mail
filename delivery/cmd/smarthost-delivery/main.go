// Command smarthost-delivery is the Go delivery/control daemon (Phase 4).
//
//	run [--stats-file PATH]   the daemon: send jobs, Postfix log ingestion, reconciliation
//	health                    container health check (heartbeat age)
//	normalize ADDRESS         print the D-32 normalised form
//	identity                  uid/gid/groups (Phase 1 verification)
//	check-db                  the delivery role connects and cannot create tables
//	check-observability       reads the log and newest snapshot; the volume is read-only
//	check-spool [--claim]     reads (and atomically claims) DSN spool files
//
// The daemon has no public API and no inbound SMTP listener (spec go_delivery).
package main

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"os/signal"
	"strconv"
	"strings"
	"syscall"
	"time"

	"smarthost.local/delivery/internal/address"
	"smarthost.local/delivery/internal/config"
	"smarthost.local/delivery/internal/ingest"
	"smarthost.local/delivery/internal/logx"
	"smarthost.local/delivery/internal/reconcile"
	"smarthost.local/delivery/internal/store"
	"smarthost.local/delivery/internal/worker"
)

// HeartbeatFile is touched while the daemon can reach PostgreSQL.
const HeartbeatFile = "/tmp/smarthost-delivery-heartbeat"

func main() {
	cmd := "run"
	if len(os.Args) > 1 {
		cmd = os.Args[1]
	}
	switch cmd {
	case "run":
		os.Exit(run(os.Args[2:]))
	case "health":
		st, err := os.Stat(HeartbeatFile)
		if err != nil || time.Since(st.ModTime()) > 90*time.Second {
			fmt.Println("unhealthy: no recent heartbeat")
			os.Exit(1)
		}
		fmt.Println("ok")
	case "normalize":
		if len(os.Args) < 3 {
			fail("usage: normalize ADDRESS")
		}
		n, ok := address.Normalize(os.Args[2])
		out, _ := json.Marshal(map[string]any{"input": os.Args[2], "normalized": map[bool]any{true: n, false: nil}[ok]})
		fmt.Println(string(out))
	case "identity":
		fmt.Println(identity())
	case "check-db":
		ok, detail := checkDB()
		fmt.Println(detail)
		if !ok {
			os.Exit(1)
		}
	case "check-observability":
		if err := checkObservability(); err != nil {
			fail("%v", err)
		}
	case "check-spool":
		if err := checkSpool(len(os.Args) > 2 && os.Args[2] == "--claim"); err != nil {
			fail("%v", err)
		}
	default:
		fail("unknown command %q", cmd)
	}
}

func fail(format string, args ...any) {
	fmt.Fprintf(os.Stderr, "FAIL "+format+"\n", args...)
	os.Exit(1)
}

func run(args []string) int {
	statsFile := ""
	for i := 0; i < len(args); i++ {
		if args[i] == "--stats-file" && i+1 < len(args) {
			statsFile = args[i+1]
			i++
		}
	}
	cfg, err := config.FromEnv()
	if err != nil {
		fmt.Fprintln(os.Stderr, err)
		return 64
	}
	log := logx.New(cfg.LogLevel, cfg.LogFormat)
	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGTERM, syscall.SIGINT)
	defer stop()
	st, err := store.New(ctx, cfg.DSN(), int32(cfg.GlobalConcurrency+12))
	if err != nil {
		log.Error("database pool", "error", err)
		return 1
	}
	defer st.Close()
	w := worker.New(cfg, st, log)
	log.Info("delivery daemon started", "worker_id", w.Me, "global_concurrency", cfg.GlobalConcurrency,
		"per_domain_concurrency", cfg.DomainConcurrency, "per_domain_rate_per_minute", cfg.DomainRatePerMin,
		"lease_seconds", int(cfg.Lease.Seconds()), "identity", identity())
	ing := &ingest.Ingester{Store: st, Log: log, Dir: cfg.ObservabilityDir + "/log", BounceDomain: cfg.BounceDomain, Interval: cfg.FilePollInterval}
	rec := &reconcile.Reconciler{Store: st, Log: log, ObservabilityDir: cfg.ObservabilityDir, BounceDomain: cfg.BounceDomain,
		Interval: cfg.ReconcileInterval, Grace: cfg.ReconcileGrace, MinSnapshots: cfg.ReconcileMinSnaps, SnapshotInterval: cfg.SnapshotInterval}
	done := make(chan struct{}, 3)
	go func() { w.Run(ctx); done <- struct{}{} }()
	go func() { ing.Run(ctx); done <- struct{}{} }()
	go func() { rec.Run(ctx); done <- struct{}{} }()
	go heartbeat(ctx, st, w, log)
	for i := 0; i < 3; i++ {
		<-done
	}
	stats := statsMap(w)
	log.Info("delivery daemon stopped", "stats", stats)
	if statsFile != "" {
		b, _ := json.MarshalIndent(stats, "", "  ")
		_ = os.WriteFile(statsFile, b, 0o644)
	}
	return 0
}

func heartbeat(ctx context.Context, st *store.Store, w *worker.Worker, log *logx.Logger) {
	t := time.NewTicker(15 * time.Second)
	defer t.Stop()
	n := 0
	for {
		pctx, cancel := context.WithTimeout(ctx, 5*time.Second)
		if err := st.Pool.Ping(pctx); err == nil {
			now := time.Now()
			if f, err := os.Create(HeartbeatFile); err == nil {
				f.Close()
				_ = os.Chtimes(HeartbeatFile, now, now)
			}
		}
		cancel()
		if n++; n%8 == 0 { // every two minutes
			log.Info("delivery stats", "stats", statsMap(w))
		}
		select {
		case <-ctx.Done():
			return
		case <-t.C:
		}
	}
}

func statsMap(w *worker.Worker) map[string]any {
	s := &w.Stats
	return map[string]any{
		"jobs_claimed": s.JobsClaimed.Load(), "jobs_dispatched": s.JobsDispatched.Load(), "jobs_failed": s.JobsFailed.Load(),
		"messages_created": s.MessagesCreated.Load(), "suppressed": s.Suppressed.Load(), "submitted": s.Submitted.Load(),
		"submission_failed": s.Failed.Load(), "temporary_failures": s.TempFailures.Load(), "ambiguous": s.Ambiguous.Load(),
		"recovered_from_log": s.Recovered.Load(), "lease_lost": s.LeaseLost.Load(),
		"accepted_but_unrecorded": s.AcceptedButUnrecorded.Load(),
		"max_inflight_global":     w.Lim.MaxGlobal, "max_inflight_domain": w.Lim.MaxDomain,
		"peak_rss_kib": peakRSS(),
	}
}

// peakRSS reads VmHWM (peak resident set) from /proc/self/status.
func peakRSS() int64 {
	b, err := os.ReadFile("/proc/self/status")
	if err != nil {
		return 0
	}
	for _, line := range strings.Split(string(b), "\n") {
		if strings.HasPrefix(line, "VmHWM:") {
			f := strings.Fields(line)
			if len(f) >= 2 {
				n, _ := strconv.ParseInt(f[1], 10, 64)
				return n
			}
		}
	}
	return 0
}
