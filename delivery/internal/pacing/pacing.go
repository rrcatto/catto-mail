// Package pacing enforces the delivery pacing controls (spec sending.pacing):
// a global concurrency limit, a per-recipient-domain concurrency limit, a
// per-domain start rate (DELIVERY_PER_DOMAIN_RATE_PER_MINUTE, as a minimum
// spacing between submissions to one domain), per-domain back-off after
// repeated provider deferrals (DELIVERY_DEFERRAL_BACKOFF_SECONDS) and a global
// pause while Postfix itself refuses submissions. A domain that is throttled
// never blocks other domains: callers try the next message instead of waiting.
//
// Phase 8 (specification 2.9) adds the warm-up and emergency controls:
//   - an installation-wide start rate (DELIVERY_GLOBAL_RATE_PER_MINUTE, 0 = none),
//     a hard ceiling on submissions per minute for the whole daemon, whatever the
//     recipient domains or clients;
//   - a per-client start rate for clients whose status is `throttled`
//     (DELIVERY_THROTTLED_CLIENT_RATE_PER_MINUTE);
//   - a hold (emergency pause, or production before live activation) that
//     admits nothing until it is lifted.
package pacing

import (
	"sync"
	"time"
)

// Limiter is safe for concurrent use.
type Limiter struct {
	mu            sync.Mutex
	global        int
	perDomain     int
	spacing       time.Duration
	globalSpacing time.Duration
	clientSpacing time.Duration
	globalNext    time.Time
	inflight      int
	domains       map[string]*domain
	clientNext    map[string]time.Time
	pausedUntil   time.Time
	held          bool
	now           func() time.Time
	// observed maxima (tests, reports)
	MaxGlobal, MaxDomain int
}

type domain struct {
	inflight     int
	nextStart    time.Time
	backoffUntil time.Time
}

// heldRetry is how soon a held caller looks again (the hold is lifted by the
// operator, not by time).
const heldRetry = time.Second

func spacingOf(perMinute int) time.Duration {
	if perMinute <= 0 {
		return 0
	}
	return time.Minute / time.Duration(perMinute)
}

// New returns a limiter. ratePerMinute <= 0 disables the per-domain rate limit.
func New(global, perDomain, ratePerMinute int) *Limiter {
	return &Limiter{global: global, perDomain: perDomain, spacing: spacingOf(ratePerMinute),
		domains: map[string]*domain{}, clientNext: map[string]time.Time{}, now: time.Now}
}

// SetGlobalRate sets the installation-wide start rate (<= 0: none).
func (l *Limiter) SetGlobalRate(perMinute int) {
	l.mu.Lock()
	defer l.mu.Unlock()
	l.globalSpacing = spacingOf(perMinute)
}

// SetThrottledClientRate sets the start rate per throttled client.
func (l *Limiter) SetThrottledClientRate(perMinute int) {
	l.mu.Lock()
	defer l.mu.Unlock()
	l.clientSpacing = spacingOf(perMinute)
}

// SetHeld holds (true) or releases (false) all new submissions. In-flight
// submissions finish; nothing new starts while held.
func (l *Limiter) SetHeld(held bool) {
	l.mu.Lock()
	defer l.mu.Unlock()
	l.held = held
}

// Held reports whether new submissions are held.
func (l *Limiter) Held() bool {
	l.mu.Lock()
	defer l.mu.Unlock()
	return l.held
}

// SetClock replaces the clock (tests).
func (l *Limiter) SetClock(now func() time.Time) { l.now = now }

// TryAcquire takes a slot for one submission to domain (no client limit).
func (l *Limiter) TryAcquire(d string) (release func(), ok bool, wait time.Duration) {
	return l.TryAcquireFor(d, "", false)
}

// TryAcquireFor takes a slot for one submission to domain d for client c,
// applying the throttled-client rate when throttled. When it cannot, it returns
// how long until the submission could be eligible (0 = when a slot frees).
func (l *Limiter) TryAcquireFor(d, c string, throttled bool) (release func(), ok bool, wait time.Duration) {
	l.mu.Lock()
	defer l.mu.Unlock()
	now := l.now()
	if l.held {
		return nil, false, heldRetry
	}
	if now.Before(l.pausedUntil) {
		return nil, false, l.pausedUntil.Sub(now)
	}
	st := l.domains[d]
	if st == nil {
		st = &domain{}
		l.domains[d] = st
	}
	throttled = throttled && l.clientSpacing > 0
	switch {
	case now.Before(st.backoffUntil):
		return nil, false, st.backoffUntil.Sub(now)
	case st.inflight >= l.perDomain || l.inflight >= l.global:
		return nil, false, 0
	case now.Before(st.nextStart):
		return nil, false, st.nextStart.Sub(now)
	case now.Before(l.globalNext):
		return nil, false, l.globalNext.Sub(now)
	case throttled && now.Before(l.clientNext[c]):
		return nil, false, l.clientNext[c].Sub(now)
	}
	st.inflight++
	l.inflight++
	st.nextStart = now.Add(l.spacing)
	if l.globalSpacing > 0 {
		l.globalNext = now.Add(l.globalSpacing)
	}
	if throttled {
		l.clientNext[c] = now.Add(l.clientSpacing)
	}
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
