package snapshot

import (
	"os"
	"path/filepath"
	"testing"
	"time"
)

func write(t *testing.T, dir string, ts time.Time, body string) {
	t.Helper()
	if err := os.WriteFile(filepath.Join(dir, "snapshot-"+ts.UTC().Format("20060102T150405Z")+".jsonl"), []byte(body), 0o640); err != nil {
		t.Fatal(err)
	}
}

func TestListLoadFreshness(t *testing.T) {
	dir := t.TempDir()
	now := time.Date(2026, 10, 4, 12, 0, 0, 0, time.UTC)
	write(t, dir, now.Add(-10*time.Minute), "")
	write(t, dir, now.Add(-2*time.Minute), `{"queue_name":"deferred","queue_id":"QA","recipients":[{"address":"a@x"}]}`+"\n")
	write(t, dir, now.Add(-1*time.Minute), "")
	_ = os.WriteFile(filepath.Join(dir, ".tmp-123"), []byte("partial"), 0o640)
	s, err := List(dir)
	if err != nil || len(s) != 3 || !s[0].Time.Before(s[2].Time) {
		t.Fatalf("%v %v", s, err)
	}
	ids, err := Load(s[1].Path)
	if err != nil || ids["QA"] != "deferred" || len(ids) != 1 {
		t.Fatalf("%v %v", ids, err)
	}
	if empty, _ := Load(s[2].Path); len(empty) != 0 {
		t.Fatal("empty snapshot must mean an empty queue")
	}
	if !Fresh(s, time.Minute, now) || Fresh(s, time.Minute, now.Add(5*time.Minute)) {
		t.Fatal("freshness")
	}
	// The 8-minute gap breaks the run: only the two recent snapshots are consecutive.
	if run := ConsecutiveFresh(s, time.Minute, now); len(run) != 2 {
		t.Fatalf("run %v", run)
	}
	if run := ConsecutiveFresh(s, time.Minute, now.Add(time.Hour)); run != nil {
		t.Fatal("stale snapshots must give no run")
	}
}
