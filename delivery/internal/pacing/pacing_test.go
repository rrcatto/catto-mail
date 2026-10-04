package pacing

import (
	"testing"
	"time"
)

func TestConcurrencyLimits(t *testing.T) {
	l := New(3, 2, 0)
	var rel []func()
	for i := 0; i < 2; i++ {
		r, ok, _ := l.TryAcquire("big.example")
		if !ok {
			t.Fatal("slot refused")
		}
		rel = append(rel, r)
	}
	if _, ok, _ := l.TryAcquire("big.example"); ok {
		t.Fatal("per-domain limit exceeded")
	}
	r, ok, _ := l.TryAcquire("other.example") // a saturated domain does not block others
	if !ok {
		t.Fatal("other domain blocked")
	}
	if _, ok, _ := l.TryAcquire("third.example"); ok {
		t.Fatal("global limit exceeded")
	}
	r()
	r() // release is idempotent
	if l.Inflight() != 2 {
		t.Fatalf("inflight %d", l.Inflight())
	}
	for _, f := range rel {
		f()
	}
	if l.MaxGlobal != 3 || l.MaxDomain != 2 {
		t.Fatalf("max %d %d", l.MaxGlobal, l.MaxDomain)
	}
}

func TestRateSpacingBackoffAndPause(t *testing.T) {
	now := time.Date(2026, 1, 1, 0, 0, 0, 0, time.UTC)
	l := New(10, 10, 60) // one start per second per domain
	l.SetClock(func() time.Time { return now })
	r, ok, _ := l.TryAcquire("d.example")
	if !ok {
		t.Fatal()
	}
	r()
	if _, ok, wait := l.TryAcquire("d.example"); ok || wait != time.Second {
		t.Fatalf("rate not enforced (wait %v)", wait)
	}
	now = now.Add(time.Second)
	if r, ok, _ := l.TryAcquire("d.example"); !ok {
		t.Fatal("rate slot not freed")
	} else {
		r()
	}
	l.BackOff("d.example", time.Minute)
	if _, ok, wait := l.TryAcquire("d.example"); ok || wait != time.Minute {
		t.Fatalf("domain backoff: %v", wait)
	}
	if r, ok, _ := l.TryAcquire("e.example"); !ok {
		t.Fatal("backoff leaked to other domains")
	} else {
		r()
	}
	l.PauseAll(30 * time.Second)
	if _, ok, wait := l.TryAcquire("e.example"); ok || wait <= 0 {
		t.Fatal("global pause")
	}
}

func TestBackoff(t *testing.T) {
	for n, want := range map[int]time.Duration{1: 5 * time.Second, 2: 10 * time.Second, 3: 20 * time.Second, 10: time.Minute} {
		if got := Backoff(n, 5*time.Second, time.Minute); got != want {
			t.Errorf("Backoff(%d) = %v", n, got)
		}
	}
}
