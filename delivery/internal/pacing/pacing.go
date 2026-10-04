// Package pacing enforces the delivery pacing controls (spec sending.pacing):
// a global concurrency limit, a per-recipient-domain concurrency limit, a
// per-domain start rate (DELIVERY_PER_DOMAIN_RATE_PER_MINUTE, as a minimum
// spacing between submissions to one domain), per-domain back-off after
// repeated provider deferrals (DELIVERY_DEFERRAL_BACKOFF_SECONDS) and a global
// pause while Postfix itself refuses submissions. A domain that is throttled
// never blocks other domains: callers try the next message instead of waiting.
package pacing

import (
	"sync"
	"time"
)

// Limiter is safe for concurrent use.
type Limiter struct {
	mu          sync.Mutex
	global      int
	perDomain   int
	spacing     time.Duration
	inflight    int
	domains     map[string]*domain
	pausedUntil time.Time
	now         func() time.Time
	// observed maxima (tests, reports)
	MaxGlobal, MaxDomain int
}

type domain struct {
	inflight     int
	nextStart    time.Time
	backoffUntil time.Time
}

// New returns a limiter. ratePerMinute <= 0 disables the rate limit.
func New(global, perDomain, ratePerMinute int) *Limiter {
	l := &Limiter{global: global, perDomain: perDomain, domains: map[string]*domain{}, now: time.Now}
	if ratePerMinute > 0 {
		l.spacing = time.Minute / time.Duration(ratePerMinute)
	}
	return l
}

// SetClock replaces the clock (tests).
func (l *Limiter) SetClock(now func() time.Time) { l.now = now }

// TryAcquire takes a slot for one submission to domain. When it cannot, it
// returns how long until the domain could be eligible (0 = when a slot frees).
func (l *Limiter) TryAcquire(d string) (release func(), ok bool, wait time.Duration) {
	l.mu.Lock()
	defer l.mu.Unlock()
	now := l.now()
	if now.Before(l.pausedUntil) {
		return nil, false, l.pausedUntil.Sub(now)
	}
	st := l.domains[d]
	if st == nil {
		st = &domain{}
		l.domains[d] = st
	}
	switch {
	case now.Before(st.backoffUntil):
		return nil, false, st.backoffUntil.Sub(now)
	case st.inflight >= l.perDomain || l.inflight >= l.global:
		return nil, false, 0
	case now.Before(st.nextStart):
		return nil, false, st.nextStart.Sub(now)
	}
	st.inflight++
	l.inflight++
	st.nextStart = now.Add(l.spacing)
	l.MaxGlobal = max(l.MaxGlobal, l.inflight)
	l.MaxDomain = max(l.MaxDomain, st.inflight)
	var once sync.Once
	return func() {
		once.Do(func() {
			l.mu.Lock()
			st.inflight--
			l.inflight--
			l.mu.Unlock()
		})
	}, true, 0
}

// PauseAll stops new submissions until now+d (Postfix refused submissions).
func (l *Limiter) PauseAll(d time.Duration) {
	l.mu.Lock()
	defer l.mu.Unlock()
	if until := l.now().Add(d); until.After(l.pausedUntil) {
		l.pausedUntil = until
	}
}

// BackOff stops new submissions to a domain until now+d.
func (l *Limiter) BackOff(d string, dur time.Duration) {
	l.mu.Lock()
	defer l.mu.Unlock()
	st := l.domains[d]
	if st == nil {
		st = &domain{}
		l.domains[d] = st
	}
	if until := l.now().Add(dur); until.After(st.backoffUntil) {
		st.backoffUntil = until
	}
}

// Inflight is the current number of submissions.
func (l *Limiter) Inflight() int {
	l.mu.Lock()
	defer l.mu.Unlock()
	return l.inflight
}

// Backoff returns the bounded exponential retry delay for attempt n (1-based):
// base * 2^(n-1), capped at max.
func Backoff(n int, base, max time.Duration) time.Duration {
	d := base
	for i := 1; i < n && d < max; i++ {
		d *= 2
	}
	if d > max {
		d = max
	}
	return d
}
