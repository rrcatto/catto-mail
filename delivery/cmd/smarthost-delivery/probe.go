package main

// Infrastructure probes kept from the Phase 1 delivery probe; the Phase 1
// verification suite uses them to prove the identity, database privileges and
// shared-volume permissions the daemon relies on.

import (
	"bufio"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io/fs"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"syscall"
	"time"

	"github.com/jackc/pgx/v5"

	"smarthost.local/delivery/internal/config"
)

func identity() string {
	groups, _ := os.Getgroups()
	return fmt.Sprintf("uid=%d gid=%d groups=%v", os.Getuid(), os.Getgid(), groups)
}

func checkDB() (bool, string) {
	cfg, err := config.FromEnv()
	if err != nil {
		return false, err.Error()
	}
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	conn, err := pgx.Connect(ctx, cfg.DSN())
	if err != nil {
		return false, err.Error()
	}
	defer conn.Close(ctx)
	var user string
	if err := conn.QueryRow(ctx, "SELECT current_user").Scan(&user); err != nil {
		return false, err.Error()
	}
	tx, err := conn.Begin(ctx)
	if err != nil {
		return false, err.Error()
	}
	_, createErr := tx.Exec(ctx, "CREATE TABLE smarthost_phase1_privilege_probe (id int)")
	_ = tx.Rollback(ctx)
	canCreate := createErr == nil
	ok := !canCreate
	return ok, fmt.Sprintf("role=delivery connected_as=%s %s can_create_table=%v => %s", user, identity(), canCreate,
		map[bool]string{true: "OK", false: "VIOLATION"}[ok])
}

func checkObservability() error {
	dir := os.Getenv("SMARTHOST_POSTFIX_OBSERVABILITY_DIR")
	logPath := filepath.Join(dir, "log", "postfix.log")
	f, err := os.Open(logPath)
	if err != nil {
		return fmt.Errorf("cannot read %s: %w", logPath, err)
	}
	lines := 0
	sc := bufio.NewScanner(f)
	sc.Buffer(make([]byte, 1024*1024), 16*1024*1024)
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
	fmt.Printf("OK read newest snapshot %s (%d snapshots present): %d queued message record(s), all valid JSON with queue_id\n",
		filepath.Base(newest), len(snaps), records)
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

// checkSpool reads DSN spool files and, with claim, renames each to processing/
// then done/ and proves a second claim fails (exactly-one-winner). It is the
// Phase 1 permission probe; the running daemon (Phase 5) claims spool files
// itself, so the verification suite runs this probe while the daemon is paused.
func checkSpool(claim bool) error {
	dir := os.Getenv("SMARTHOST_DSN_SPOOL_DIR")
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
		key := strings.SplitN(e.Name(), ":", 2)[0]
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
