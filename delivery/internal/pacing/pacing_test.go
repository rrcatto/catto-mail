package pacing

import (
	"fmt"
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

// Phase 8: the installation-wide ceiling holds across every domain and client,
// so warm-up volume cannot exceed it however the recipients are spread.
func TestGlobalRateIsAnInstallationWideCeiling(t *testing.T) {
	now := time.Date(2026, 1, 1, 0, 0, 0, 0, time.UTC)
	l := New(100, 100, 0)
	l.SetGlobalRate(6) // one start every 10 s for the whole daemon
	l.SetClock(func() time.Time { return now })
	started := 0
	for s := 0; s < 60; s++ { // one minute, a different domain every attempt
		for i := 0; i < 50; i++ {
			if r, ok, _ := l.TryAcquireFor(fmt.Sprintf("d%d-%d.example", s, i), "client", false); ok {
				started++
				r()
			}
		}
		now = now.Add(time.Second)
	}
	if started != 6 {
		t.Fatalf("started %d submissions in a minute with a ceiling of 6", started)
	}
	l.SetGlobalRate(0)
	if r, ok, _ := l.TryAcquire("new.example"); !ok {
		t.Fatal("ceiling 0 means none")
	} else {
		r()
	}
}

func TestThrottledClientRateAndHold(t *testing.T) {
	now := time.Date(2026, 1, 1, 0, 0, 0, 0, time.UTC)
	l := New(100, 100, 0)
	l.SetThrottledClientRate(2) // one start every 30 s per throttled client
	l.SetClock(func() time.Time { return now })
	r, ok, _ := l.TryAcquireFor("a.example", "slow", true)
	if !ok {
		t.Fatal("first throttled start")
	}
	r()
	if _, ok, wait := l.TryAcquireFor("b.example", "slow", true); ok || wait != 30*time.Second {
		t.Fatalf("throttled client not limited across domains (wait %v)", wait)
	}
	if r, ok, _ := l.TryAcquireFor("b.example", "other", false); !ok {
		t.Fatal("a throttled client must not slow an active one")
	} else {
		r()
	}
	if r, ok, _ := l.TryAcquireFor("c.example", "slow2", true); !ok {
		t.Fatal("the limit is per client")
	} else {
		r()
	}
	l.SetHeld(true)
	if _, ok, wait := l.TryAcquireFor("d.example", "other", false); ok || wait != time.Second || !l.Held() {
		t.Fatal("hold admits nothing")
	}
	l.SetHeld(false)
	if r, ok, _ := l.TryAcquireFor("d.example", "other", false); !ok {
		t.Fatal("released hold")
	} else {
		r()
	}
}
