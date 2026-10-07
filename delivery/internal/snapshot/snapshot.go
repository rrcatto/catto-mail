// Package snapshot reads the atomic Postfix queue snapshots
// (postfix-integration.md §4, V-3): queue/snapshot-<UTC %Y%m%dT%H%M%SZ>.jsonl,
// JSON Lines from `postqueue -j`, written by rename, so a file is never partial
// and an empty file means an empty queue.
package snapshot

import (
	"bufio"
	"encoding/json"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"time"
)

// Snapshot is one snapshot file.
type Snapshot struct {
	Path string
	Time time.Time // from the file name (UTC)
}

// List returns the snapshots in dir, oldest first.
func List(dir string) ([]Snapshot, error) {
	entries, err := os.ReadDir(dir)
	if err != nil {
		return nil, err
	}
	var out []Snapshot
	for _, e := range entries {
		n := e.Name()
		if !strings.HasPrefix(n, "snapshot-") || !strings.HasSuffix(n, ".jsonl") {
			continue
		}
		t, err := time.Parse("20060102T150405Z", strings.TrimSuffix(strings.TrimPrefix(n, "snapshot-"), ".jsonl"))
		if err != nil {
			continue
		}
		out = append(out, Snapshot{Path: filepath.Join(dir, n), Time: t.UTC()})
	}
	sort.Slice(out, func(i, j int) bool { return out[i].Time.Before(out[j].Time) })
	return out, nil
}

// Entry is one queued message of a snapshot.
type Entry struct {
	QueueName string `json:"queue_name"`
	QueueID   string `json:"queue_id"`
}

// Load returns the queue ids of a snapshot.
func Load(path string) (map[string]string, error) {
	f, err := os.Open(path)
	if err != nil {
		return nil, err
	}
	defer f.Close()
	ids := map[string]string{}
	sc := bufio.NewScanner(f)
	sc.Buffer(make([]byte, 64*1024), 16*1024*1024)
	for sc.Scan() {
		line := strings.TrimSpace(sc.Text())
		if line == "" {
			continue
		}
		var e Entry
		if err := json.Unmarshal([]byte(line), &e); err != nil {
			return nil, err
		}
		if e.QueueID != "" {
			ids[e.QueueID] = e.QueueName
		}
	}
	return ids, sc.Err()
}

// Fresh reports whether the newest snapshot is at most three intervals old
// (§4: older snapshots suspend reconciliation conclusions).
func Fresh(snaps []Snapshot, interval time.Duration, now time.Time) bool {
	return len(snaps) > 0 && now.Sub(snaps[len(snaps)-1].Time) <= 3*interval
}

// ConsecutiveFresh returns the newest run of snapshots in which no gap between
// neighbours exceeds three intervals, newest last; empty if the newest is stale.
func ConsecutiveFresh(snaps []Snapshot, interval time.Duration, now time.Time) []Snapshot {
	if !Fresh(snaps, interval, now) {
		return nil
	}
	i := len(snaps) - 1
	for i > 0 && snaps[i].Time.Sub(snaps[i-1].Time) <= 3*interval {
		i--
	}
	return snaps[i:]
}

// Depth is the Postfix queue depth of the newest snapshot (Phase 8: the durable
// queue signal for the operator dashboard). ok is false without any snapshot.
func Depth(dir string) (at time.Time, counts map[string]int, ok bool, err error) {
	snaps, err := List(dir)
	if err != nil || len(snaps) == 0 {
		return time.Time{}, nil, false, err
	}
	newest := snaps[len(snaps)-1]
	ids, err := Load(newest.Path)
	if err != nil {
		return time.Time{}, nil, false, err
	}
	counts = map[string]int{"active": 0, "deferred": 0, "hold": 0, "incoming": 0}
	for _, queue := range ids {
		switch queue {
		case "active", "deferred", "hold":
			counts[queue]++
		default: // incoming, maildrop
			counts["incoming"]++
		}
	}
	return newest.Time, counts, true, nil
}
