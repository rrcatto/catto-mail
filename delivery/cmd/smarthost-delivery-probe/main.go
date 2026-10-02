// Command smarthost-delivery-probe is the Phase 1 infrastructure probe for the
// Go delivery container. It proves the unprivileged identity, database
// credentials and shared-volume permissions that the Phase 4/5 delivery daemon
// will rely on. It implements NO send-job, log-ingestion or DSN processing.
//
//	serve                  heartbeat loop (the unit's main process)
//	identity               print uid/gid/supplementary groups
//	check-db               connect as DELIVERY_DB_USER; must NOT be able to create tables
//	check-observability    read the Postfix log and newest queue snapshot; prove the
//	                       observability volume is NOT writable (read-only mount)
//	check-spool [--claim]  read DSN files in inbound/new; with --claim, atomically
//	                       rename each to processing/ then done/ and prove a second
//	                       claim of the same file fails (exactly-one-winner semantics)
package main

import (
	"bufio"
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"io/fs"
	"os"
	"os/signal"
	"path/filepath"
	"sort"
	"strings"
	"syscall"
	"time"

	_ "github.com/jackc/pgx/v5/stdlib"
)

func env(name string) string {
	if f := os.Getenv(name + "_FILE"); f != "" {
		b, err := os.ReadFile(f)
		if err != nil {
			fail("read %s_FILE: %v", name, err)
		}
		return strings.TrimSpace(string(b))
	}
	v := os.Getenv(name)
	if v == "" {
		fail("missing %s", name)
	}
	return v
}

func fail(format string, args ...any) {
	fmt.Fprintf(os.Stderr, "FAIL "+format+"\n", args...)
	os.Exit(1)
}

func logJSON(fields map[string]any) {
	fields["service"] = "delivery"
	b, _ := json.Marshal(fields)
	fmt.Println(string(b))
}

func identity() string {
	groups, _ := os.Getgroups()
	return fmt.Sprintf("uid=%d gid=%d groups=%v", os.Getuid(), os.Getgid(), groups)
}

func checkDB() (bool, string) {
	dsn := fmt.Sprintf("host=%s port=%s dbname=%s sslmode=%s user=%s password=%s connect_timeout=5",
		env("SMARTHOST_DB_HOST"), env("SMARTHOST_DB_PORT"), env("SMARTHOST_DB_NAME"),
		env("SMARTHOST_DB_SSLMODE"), env("DELIVERY_DB_USER"), env("DELIVERY_DB_PASSWORD"))
	db, err := sql.Open("pgx", dsn)
	if err != nil {
		return false, err.Error()
	}
	defer db.Close()
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	var user string
	if err := db.QueryRowContext(ctx, "SELECT current_user").Scan(&user); err != nil {
		return false, err.Error()
	}
	tx, err := db.BeginTx(ctx, nil)
	if err != nil {
		return false, err.Error()
	}
	_, createErr := tx.ExecContext(ctx, "CREATE TABLE smarthost_phase1_privilege_probe (id int)")
	_ = tx.Rollback()
	canCreate := createErr == nil
	ok := !canCreate
	return ok, fmt.Sprintf("role=delivery connected_as=%s %s can_create_table=%v => %s", user, identity(), canCreate, map[bool]string{true: "OK", false: "VIOLATION"}[ok])
}

func checkObservability() error {
	dir := env("SMARTHOST_POSTFIX_OBSERVABILITY_DIR")
	logPath := filepath.Join(dir, "log", "postfix.log")
	f, err := os.Open(logPath)
	if err != nil {
		return fmt.Errorf("cannot read %s: %w", logPath, err)
	}
	lines := 0
	sc := bufio.NewScanner(f)
	for sc.Scan() {
		lines++
	}
	f.Close()
	st, _ := os.Stat(logPath)
	sys := st.Sys().(*syscall.Stat_t)
	fmt.Printf("OK read %s: %d lines, owner=%d:%d mode=%v\n", logPath, lines, sys.Uid, sys.Gid, st.Mode().Perm())

	snaps, _ := filepath.Glob(filepath.Join(dir, "queue", "snapshot-*.jsonl"))
	sort.Strings(snaps)
	if len(snaps) == 0 {
		return errors.New("no queue snapshot found")
	}
	newest := snaps[len(snaps)-1]
	sf, err := os.Open(newest)
	if err != nil {
		return fmt.Errorf("cannot read snapshot %s: %w", newest, err)
	}
	defer sf.Close()
	records := 0
	sc = bufio.NewScanner(sf)
	sc.Buffer(make([]byte, 1024*1024), 16*1024*1024)
	for sc.Scan() {
		var rec map[string]any
		if err := json.Unmarshal(sc.Bytes(), &rec); err != nil {
			return fmt.Errorf("snapshot %s line %d is not JSON: %w", newest, records+1, err)
		}
		if _, ok := rec["queue_id"]; !ok {
			return fmt.Errorf("snapshot record without queue_id: %s", sc.Text())
		}
		records++
	}
	fmt.Printf("OK read newest snapshot %s (%d snapshots present): %d queued message record(s), all valid JSON with queue_id\n", filepath.Base(newest), len(snaps), records)

	probe := filepath.Join(dir, "queue", ".delivery-write-probe")
	if wf, err := os.Create(probe); err == nil {
		wf.Close()
		os.Remove(probe)
		return errors.New("observability volume is WRITABLE by delivery (must be read-only)")
	} else {
		fmt.Printf("OK observability volume not writable by delivery: %v\n", err)
	}
	return nil
}

func checkSpool(claim bool) error {
	dir := env("SMARTHOST_DSN_SPOOL_DIR")
	newDir := filepath.Join(dir, "inbound", "new")
	entries, err := os.ReadDir(newDir)
	if err != nil {
		return fmt.Errorf("cannot list %s: %w", newDir, err)
	}
	fmt.Printf("OK listed %s: %d file(s)\n", newDir, len(entries))
	for _, e := range entries {
		src := filepath.Join(newDir, e.Name())
		info, _ := e.Info()
		sys := info.Sys().(*syscall.Stat_t)
		b, err := os.ReadFile(src)
		if err != nil {
			return fmt.Errorf("cannot read %s: %w", src, err)
		}
		fmt.Printf("OK read %s (%d bytes, owner=%d:%d mode=%v)\n", e.Name(), len(b), sys.Uid, sys.Gid, info.Mode().Perm())
		if !claim {
			continue
		}
		key := strings.SplitN(e.Name(), ":", 2)[0] // Maildir unique name = ingestion key
		processing := filepath.Join(dir, "processing", key)
		if err := os.Rename(src, processing); err != nil {
			return fmt.Errorf("claim rename failed: %w", err)
		}
		if err := os.Rename(src, processing+".second"); !errors.Is(err, fs.ErrNotExist) {
			return fmt.Errorf("second claim of the same file did not fail with ENOENT: %v", err)
		}
		if err := os.Rename(processing, filepath.Join(dir, "done", key)); err != nil {
			return fmt.Errorf("move to done failed: %w", err)
		}
		fmt.Printf("OK claimed %s -> processing/%s -> done/%s; second claim got ENOENT\n", e.Name(), key, key)
	}
	return nil
}

func serve() {
	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGTERM, syscall.SIGINT)
	defer stop()
	logJSON(map[string]any{"msg": "phase 1 probe started (no delivery logic)", "identity": identity()})
	for {
		ok, detail := checkDB()
		logJSON(map[string]any{"msg": "db check", "ok": ok, "detail": detail})
		select {
		case <-ctx.Done():
			logJSON(map[string]any{"msg": "stopping"})
			return
		case <-time.After(60 * time.Second):
		}
	}
}

func main() {
	cmd := "serve"
	if len(os.Args) > 1 {
		cmd = os.Args[1]
	}
	switch cmd {
	case "serve":
		serve()
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
