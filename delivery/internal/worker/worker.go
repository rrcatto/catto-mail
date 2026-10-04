// Package worker executes sealed send jobs (spec go_delivery, sending):
// lease a job, create one message per staged recipient (retry-safe), honour
// existing suppressions, build MIME with the job's tracking, submit each
// message to Postfix under the pacing limits, and record every outcome in
// fenced transactions. Memory is bounded by the page size and the number of
// submissions in flight, never by the job size.
package worker

import (
	"context"
	"crypto/rand"
	"crypto/tls"
	"encoding/hex"
	"errors"
	"fmt"
	"strings"
	"sync"
	"sync/atomic"
	"time"

	"github.com/jackc/pgx/v5/pgxpool"

	"smarthost.local/delivery/internal/address"
	"smarthost.local/delivery/internal/config"
	"smarthost.local/delivery/internal/ids"
	"smarthost.local/delivery/internal/logx"
	"smarthost.local/delivery/internal/mimemsg"
	"smarthost.local/delivery/internal/pacing"
	"smarthost.local/delivery/internal/postfixlog"
	"smarthost.local/delivery/internal/smtpsub"
	"smarthost.local/delivery/internal/store"
	"smarthost.local/delivery/internal/tracking"
)

// NotifyChannel is the LISTEN channel Symfony notifies on submit.
const NotifyChannel = "smarthost_send_work"

// Tunables that are implementation constants (no contract variable).
const (
	expandChunk       = 500             // recipients expanded per transaction
	pageSize          = 500             // queued messages held in memory per job
	retryBase         = 5 * time.Second // first retry delay after a temporary failure
	infraPauseMax     = 2 * time.Minute // cap of the global pause while Postfix refuses
	settleDelay       = 3 * time.Second // let postlogd write before searching the log
	ambiguityDeadline = 2 * time.Minute // how long to look for an ambiguous submission
	minSubmitBudget   = 20 * time.Second
	maxJobs           = 4 // jobs processed concurrently by one instance
)

// Stats are cumulative counters of one process.
type Stats struct {
	JobsClaimed, JobsDispatched, JobsFailed, MessagesCreated, Suppressed                    atomic.Int64
	Submitted, Failed, TempFailures, Ambiguous, Recovered, LeaseLost, AcceptedButUnrecorded atomic.Int64
}

// Worker processes send jobs.
type Worker struct {
	Cfg   *config.Config
	Store *store.Store
	Log   *logx.Logger
	Lim   *pacing.Limiter
	SMTP  smtpsub.Config
	VERP  ids.VERP
	Me    string // claimed_by: <DELIVERY_WORKER_ID>/<random instance>
	Stats Stats
	// SettleDelay and AmbiguityDeadline are overridable for tests.
	SettleDelay, AmbiguityDeadline time.Duration
	// Now is the clock (tests).
	Now func() time.Time
}

// New builds a worker from the configuration.
func New(cfg *config.Config, st *store.Store, log *logx.Logger) *Worker {
	inst := make([]byte, 4)
	_, _ = rand.Read(inst)
	host, _, _ := strings.Cut(cfg.SubmissionAddr(), ":")
	return &Worker{
		Cfg: cfg, Store: st, Log: log,
		Lim: pacing.New(cfg.GlobalConcurrency, cfg.DomainConcurrency, cfg.DomainRatePerMin),
		SMTP: smtpsub.Config{
			Addr: cfg.SubmissionAddr(), HeloName: "smarthost-delivery." + cfg.BounceDomain,
			Username: cfg.SubmissionUser, Password: cfg.SubmissionPassword,
			ConnectTimeout: 10 * time.Second, CommandTimeout: 30 * time.Second, DataTimeout: 60 * time.Second,
			// The submission hop never leaves the smarthost pod; Postfix's
			// certificate cannot be verified against a configured CA (no
			// contract variable exists for one), so it is encrypted, not authenticated.
			TLS:               &tls.Config{ServerName: host, InsecureSkipVerify: true, MinVersion: tls.VersionTLS12}, //nolint:gosec
			RequireTLSForAuth: true,
		},
		VERP:              ids.VERP{LocalPart: cfg.VERPLocalPart, Delimiter: cfg.VERPDelimiter, Domain: cfg.BounceDomain},
		Me:                cfg.WorkerID + "/" + hex.EncodeToString(inst),
		SettleDelay:       settleDelay,
		AmbiguityDeadline: ambiguityDeadline,
		Now:               time.Now,
	}
}

func (w *Worker) logDir() string { return w.Cfg.ObservabilityDir + "/log" }

// Run claims and processes jobs until ctx ends. It wakes on NOTIFY
// smarthost_send_work and polls every DELIVERY_POLL_INTERVAL_SECONDS.
func (w *Worker) Run(ctx context.Context) {
	wake := make(chan struct{}, 1)
	go w.listen(ctx, wake)
	go w.deferralFeedback(ctx)
	var wg sync.WaitGroup
	slots := make(chan struct{}, maxJobs)
	for ctx.Err() == nil {
		claimedAny := false
		for len(slots) < cap(slots) {
			job, err := w.Store.Claim(ctx, w.Me, w.Cfg.Lease)
			if err != nil {
				if ctx.Err() == nil {
					w.Log.Warning("claim failed", "worker_id", w.Me, "error", err)
				}
				break
			}
			if job == nil {
				break
			}
			claimedAny = true
			w.Stats.JobsClaimed.Add(1)
			slots <- struct{}{}
			wg.Add(1)
			go func() {
				defer wg.Done()
				defer func() { <-slots }()
				w.ProcessJob(ctx, job)
				select { // a finished job frees a slot: look for more work now
				case wake <- struct{}{}:
				default:
				}
			}()
		}
		if claimedAny {
			continue
		}
		select {
		case <-ctx.Done():
		case <-wake:
		case <-time.After(w.Cfg.PollInterval):
		}
	}
	wg.Wait()
}

func (w *Worker) listen(ctx context.Context, wake chan<- struct{}) {
	for ctx.Err() == nil {
		err := func() error {
			conn, err := w.Store.Pool.Acquire(ctx)
			if err != nil {
				return err
			}
			defer conn.Release()
			if _, err := conn.Exec(ctx, "LISTEN "+NotifyChannel); err != nil {
				return err
			}
			for {
				if _, err := conn.Conn().WaitForNotification(ctx); err != nil {
					return err
				}
				select {
				case wake <- struct{}{}:
				default:
				}
			}
		}()
		if ctx.Err() != nil {
			return
		}
		w.Log.Warning("notification listener reconnecting", "error", err)
		select {
		case <-ctx.Done():
		case <-time.After(w.Cfg.PollInterval):
		}
	}
}

// deferralFeedback backs off recipient domains with several messages
// currently deferred by Postfix: provider pressure reduces our rate instead of
// adding to it (DELIVERY_DEFERRAL_BACKOFF_SECONDS).
func (w *Worker) deferralFeedback(ctx context.Context) {
	t := time.NewTicker(30 * time.Second)
	defer t.Stop()
	for {
		domains, err := w.Store.DeferredDomains(ctx, 3)
		if err == nil {
			for _, d := range domains {
				w.Lim.BackOff(d, w.Cfg.DeferralBackoff)
			}
		}
		select {
		case <-ctx.Done():
			return
		case <-t.C:
		}
	}
}

// lease keeps a job's lease renewed and knows how long it is valid.
type lease struct {
	until atomic.Int64 // unix nanoseconds
	lost  atomic.Bool
}

func (l *lease) remaining(now time.Time) time.Duration { return time.Unix(0, l.until.Load()).Sub(now) }

// ProcessJob runs one claimed job to dispatch (or until its lease is lost).
func (w *Worker) ProcessJob(parent context.Context, job *store.Job) {
	log := w.Log.With("job_id", job.ID, "client_id", job.ClientID, "worker_id", w.Me)
	ctx, cancel := context.WithCancel(parent)
	defer cancel()
	ls := &lease{}
	ls.until.Store(w.Now().Add(w.Cfg.Lease).UnixNano())
	go w.renew(ctx, cancel, job, ls, log)
	log.Info("send job claimed", "attempt", job.AttemptCount)

	if reason := w.domainRefusal(job); reason != "" {
		if err := w.Store.FailJob(ctx, job, w.Me, reason); err != nil {
			log.Error("failing job", "error", err)
			return
		}
		w.Stats.JobsFailed.Add(1)
		log.Warning("send job failed", "reason", reason)
		return
	}
	if err := w.expand(ctx, job, log); err != nil {
		w.stopped(job, ls, log, "expanding recipients", err)
		return
	}
	if job.AttemptCount > 1 { // reclaimed: never resubmit before checking the log (§6.3)
		if err := w.recoverFromLog(ctx, job, log); err != nil {
			w.stopped(job, ls, log, "recovering submissions from the log", err)
			return
		}
	}
	if err := w.dispatch(ctx, job, ls, log); err != nil {
		w.stopped(job, ls, log, "dispatching", err)
		return
	}
	dispatched, completed, err := w.Store.FinishDispatch(ctx, job, w.Me)
	if err != nil {
		w.stopped(job, ls, log, "finishing dispatch", err)
		return
	}
	if dispatched {
		w.Stats.JobsDispatched.Add(1)
		log.Info("send job dispatched", "completed", completed)
	}
}

func (w *Worker) stopped(job *store.Job, ls *lease, log *logx.Logger, what string, err error) {
	if errors.Is(err, store.ErrLeaseLost) || ls.lost.Load() || errors.Is(err, context.Canceled) || errors.Is(err, context.DeadlineExceeded) {
		w.Stats.LeaseLost.Add(1)
		log.Warning("send job stopped: lease lost or worker stopping", "during", what)
		return
	}
	log.Error("send job interrupted", "during", what, "error", err)
	w.Store.SetLastError(context.Background(), job.ID, w.Me, what+": "+err.Error())
}

func (w *Worker) renew(ctx context.Context, cancel context.CancelFunc, job *store.Job, ls *lease, log *logx.Logger) {
	t := time.NewTicker(w.Cfg.Lease / 3)
	defer t.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case <-t.C:
		}
		until, ok, err := w.Store.Renew(ctx, job.ID, w.Me, w.Cfg.Lease)
		switch {
		case err != nil:
			if w.Now().After(time.Unix(0, ls.until.Load())) { // could not renew in time
				ls.lost.Store(true)
				cancel()
				return
			}
			log.Warning("lease renewal failed; retrying", "error", err)
		case !ok:
			ls.lost.Store(true)
			log.Warning("lease lost (expired, reclaimed, cancelled or client suspended)")
			cancel()
			return
		default:
			ls.until.Store(until.UnixNano())
		}
	}
}

// domainRefusal: unverified or disabled sending domains are refused (spec
// sending.sending_domains.live_sending_rule); in production a domain also
// needs active DKIM. SMARTHOST_ALLOW_UNVERIFIED_SENDING_DOMAINS (never in
// production) admits unverified domains in development/test.
func (w *Worker) domainRefusal(job *store.Job) string {
	switch {
	case job.DomainStatus == "disabled":
		return "sending domain " + job.DomainName + " is disabled"
	case job.DomainStatus != "verified" && !w.Cfg.AllowUnverified:
		return "sending domain " + job.DomainName + " is not verified"
	case w.Cfg.Env == "production" && job.DomainDKIM != "active":
		return "sending domain " + job.DomainName + " has no active DKIM"
	}
	return ""
}

// expand creates the job's messages in chunks: one message per staged
// recipient, with its identifiers, D-32 address and suppression decision.
func (w *Worker) expand(ctx context.Context, job *store.Job, log *logx.Logger) error {
	tracked := job.TrackOpens || job.TrackClicks
	for {
		recips, err := w.Store.UnexpandedRecipients(ctx, job.ID, expandChunk)
		if err != nil || len(recips) == 0 {
			return err
		}
		msgs := make([]store.NewMessage, len(recips))
		var addrs, domains []string
		for i, r := range recips {
			m := store.NewMessage{Recipient: r, ID: ids.UUIDv7(), VERPToken: ids.VERPToken(), Outcome: "queued", Address: r.Normalized}
			m.ReturnPath = w.VERP.ReturnPath(m.VERPToken)
			if tracked {
				m.TrackingToken = ids.TrackingToken()
			}
			// D-32 is shared by PHP, Python and Go: a disagreement would mean
			// the message could be sent to an address other than the one
			// validated and de-duplicated, so it is never submitted.
			if n, ok := address.Normalize(r.Email); !ok || n != r.Normalized {
				m.Outcome, m.Diagnostic = "failed", "address normalisation disagrees with the stored normalised address"
				log.Error("D-32 normalisation mismatch; recipient not sent", "send_job_recipient_id", r.ID)
			}
			msgs[i] = m
			addrs = append(addrs, r.Normalized)
			domains = append(domains, address.Domain(r.Normalized))
		}
		sups, err := w.Store.ActiveSuppressions(ctx, job.ClientID, addrs, domains)
		if err != nil {
			return err
		}
		for i := range msgs {
			m := &msgs[i]
			if m.Outcome != "queued" {
				continue
			}
			for _, s := range sups {
				if (s.Scope == "address" && s.Value == m.Address) || (s.Scope == "domain" && s.Value == address.Domain(m.Address)) {
					m.Outcome, m.SuppressionID, m.Reason = "suppressed", s.ID, s.Reason
					w.Stats.Suppressed.Add(1)
					break
				}
			}
		}
		n, err := w.Store.CreateMessages(ctx, job, w.Me, msgs)
		if err != nil {
			return err
		}
		w.Stats.MessagesCreated.Add(int64(n))
	}
}

// recoverFromLog: after a reclaim, a queued message without a queue id may
// have been accepted by Postfix just before the previous worker died. Search
// the retained log for its Message-ID first (§6.3) and record what Postfix
// really queued; only the rest is (re)submitted.
func (w *Worker) recoverFromLog(ctx context.Context, job *store.Job, log *logx.Logger) error {
	select { // the previous lease has expired; give postlogd time to flush
	case <-ctx.Done():
		return ctx.Err()
	case <-time.After(w.SettleDelay):
	}
	msgIDs, err := w.Store.QueuedWithoutQueueID(ctx, job.ID)
	if err != nil || len(msgIDs) == 0 {
		return err
	}
	wanted := map[string]bool{}
	for _, id := range msgIDs {
		wanted[strings.ToLower(id+"@"+w.Cfg.BounceDomain)] = true
	}
	found, err := postfixlog.FindSubmissions(w.logDir(), job.StartedAt.Add(-time.Hour), wanted)
	if err != nil {
		return fmt.Errorf("searching the Postfix log: %w", err)
	}
	recovered := map[string]bool{}
	defer func() { w.ingestQueueIDs(ctx, job, recovered, log) }()
	for key, sub := range found {
		if !sub.Committed {
			continue
		}
		id := strings.TrimSuffix(key, "@"+w.Cfg.BounceDomain)
		ok, err := w.Store.RecordAccepted(ctx, job, w.Me, store.Acceptance{MessageID: id, QueueID: sub.Cleanup.QueueID,
			Source: "postfix_log", Key: sub.Cleanup.Key(), OccurredAt: sub.Cleanup.Time,
			Diagnostic: "recovered from the Postfix log after an interrupted submission"})
		if err != nil {
			return err
		}
		if ok {
			w.Stats.Recovered.Add(1)
			recovered[sub.Cleanup.QueueID] = true
			log.Info("submission recovered from the Postfix log", "message_id", id, "postfix_queue_id", sub.Cleanup.QueueID)
		}
	}
	return nil
}

// ---------------------------------------------------------------------------
// Dispatch

type item struct {
	p        store.Pending
	attempts int
	due      time.Time
}

type result struct {
	it    *item
	retry time.Duration // > 0: try again after this delay
	lost  bool
}

var zeroUUID = "00000000-0000-0000-0000-000000000000"

func (w *Worker) dispatch(ctx context.Context, job *store.Job, ls *lease, log *logx.Logger) error {
	var pending, waiting []*item
	after, more := zeroUUID, true
	inflight := 0
	results := make(chan result, pageSize)
	var lostLease bool
	for {
		if ctx.Err() != nil || lostLease {
			for inflight > 0 { // let in-flight submissions record (or fail their fence)
				<-results
				inflight--
			}
			if lostLease || ls.lost.Load() {
				return store.ErrLeaseLost
			}
			return ctx.Err()
		}
		now := w.Now()
		// due retries go back to the front
		keep := waiting[:0]
		for _, it := range waiting {
			if !it.due.After(now) {
				pending = append([]*item{it}, pending...)
			} else {
				keep = append(keep, it)
			}
		}
		waiting = keep
		if more && len(pending) < pageSize/2 {
			page, err := w.Store.PendingMessages(ctx, job.ID, after, pageSize)
			if err != nil {
				return err
			}
			for _, p := range page {
				pending = append(pending, &item{p: p})
			}
			if len(page) > 0 {
				after = page[len(page)-1].ID
			}
			more = len(page) == pageSize
		}
		if !more && len(pending) == 0 && len(waiting) == 0 && inflight == 0 {
			return nil
		}
		// start whatever the pacing limits allow; a throttled domain never
		// blocks messages to other domains
		wait := time.Second
		for i := 0; i < len(pending); {
			it := pending[i]
			release, ok, d := w.Lim.TryAcquire(it.p.Domain)
			if !ok {
				if d > 0 && d < wait {
					wait = d
				}
				if w.Lim.Inflight() >= w.Cfg.GlobalConcurrency {
					break
				}
				i++
				continue
			}
			pending = append(pending[:i], pending[i+1:]...)
			inflight++
			go func() {
				defer release()
				results <- w.submit(ctx, job, ls, it, log)
			}()
		}
		for _, it := range waiting {
			if d := it.due.Sub(now); d < wait {
				wait = d
			}
		}
		if wait < 10*time.Millisecond {
			wait = 10 * time.Millisecond
		}
		select {
		case r := <-results:
			inflight--
			if r.lost {
				lostLease = true
			} else if r.retry > 0 {
				r.it.due = w.Now().Add(r.retry)
				waiting = append(waiting, r.it)
			}
		case <-time.After(wait):
		case <-ctx.Done():
		}
	}
}

// submit builds and submits one message and records the outcome.
func (w *Worker) submit(ctx context.Context, job *store.Job, ls *lease, it *item, log *logx.Logger) result {
	mlog := log.With("message_id", it.p.ID)
	c, err := w.Store.LoadContent(ctx, it.p.ID)
	if err != nil {
		mlog.Warning("loading content failed", "error", err)
		return result{it: it, retry: retryBase}
	}
	if c.Status != "queued" || c.QueueID != "" {
		return result{it: it} // handled meanwhile (e.g. recovered from the log)
	}
	if c.Purged { // impossible by construction: content is purged only with a recorded acceptance
		mlog.Error("queued message has purged content; left for the operator")
		return result{it: it}
	}
	html := c.HTML
	if html != "" && c.TrackingToken != "" && (job.TrackOpens || job.TrackClicks) {
		var links []tracking.Link
		html, links = tracking.Instrument(c.HTML, tracking.Options{BaseURL: w.Cfg.PublicBaseURL, Token: c.TrackingToken,
			Opens: job.TrackOpens, Clicks: job.TrackClicks, UnsubscribeURL: c.UnsubscribeURL})
		if len(links) > 0 {
			rows := make([]store.Link, len(links))
			for i, l := range links {
				rows[i] = store.Link{Index: l.Index, Target: l.Target}
			}
			if err := w.Store.RecordLinks(ctx, job, w.Me, it.p.ID, rows); err != nil {
				return w.writeFailed(it, err, mlog)
			}
		}
	}
	msg := mimemsg.Message{FromName: job.SenderName, FromEmail: job.SenderEmail, ReplyToName: job.ReplyToName,
		ReplyToEmail: job.ReplyToEmail, To: c.Address, Subject: c.Subject, Text: c.Text, HTML: html,
		MessageID: it.p.ID, BounceDomain: w.Cfg.BounceDomain, Date: w.Now()}
	if job.MessageClass == "subscription" {
		msg.ListID, msg.UnsubscribeURL = job.ListID, c.UnsubscribeURL
	}
	data, err := mimemsg.Build(msg)
	if err != nil {
		if _, ferr := w.Store.RecordSubmissionFailed(ctx, job, w.Me, it.p.ID, 0, "", "message build failed: "+err.Error(), "build"); ferr != nil {
			return w.writeFailed(it, ferr, mlog)
		}
		w.Stats.Failed.Add(1)
		mlog.Warning("message could not be built; submission_failed recorded", "error", err)
		return result{it: it}
	}
	// A stale worker must never submit after its lease could have expired:
	// the whole SMTP transaction has to fit in the remaining lease.
	margin, minimum := min(10*time.Second, w.Cfg.Lease/4), min(minSubmitBudget, w.Cfg.Lease/3)
	budget := ls.remaining(w.Now()) - margin
	if budget < minimum { // the renewer extends the lease; try again shortly
		return result{it: it, retry: time.Second}
	}
	sctx, cancel := context.WithTimeout(ctx, budget)
	defer cancel()
	env := smtpsub.Envelope{From: c.ReturnPath, To: c.Address, EnvID: it.p.ID, Ret: w.Cfg.DSNRet, Notify: w.Cfg.DSNNotify,
		SMTPUTF8: mimemsg.NeedsSMTPUTF8(c.Address, job.SenderEmail, job.ReplyToEmail)}
	res := smtpsub.Submit(sctx, w.SMTP, env, data)
	it.attempts++
	switch res.Outcome {
	case smtpsub.Accepted:
		r, ok := w.recordAcceptance(ctx, job, it, store.Acceptance{MessageID: it.p.ID, QueueID: res.QueueID,
			Source: "postfix_submission", Key: it.p.ID + ":" + res.QueueID, OccurredAt: w.Now(),
			SMTPCode: res.Code, Enhanced: res.Enhanced, Diagnostic: res.Text}, mlog)
		if ok {
			w.Stats.Submitted.Add(1)
			mlog.Debug("submitted", "postfix_queue_id", res.QueueID, "recipient", c.Address)
		}
		return r
	case smtpsub.Permanent:
		if _, err := w.Store.RecordSubmissionFailed(ctx, job, w.Me, it.p.ID, res.Code, res.Enhanced, res.Text, res.Stage); err != nil {
			return w.writeFailed(it, err, mlog)
		}
		w.Stats.Failed.Add(1)
		mlog.Info("submission refused permanently", "stage", res.Stage, "smtp_code", res.Code, "enhanced", res.Enhanced)
		return result{it: it}
	case smtpsub.Ambiguous:
		w.Stats.Ambiguous.Add(1)
		mlog.Warning("ambiguous submission: checking the Postfix log before any resubmission", "stage", res.Stage, "detail", res.Text)
		return w.resolveAmbiguous(ctx, job, it, mlog)
	default: // Temporary: keep content and status; retry with bounded backoff
		w.Stats.TempFailures.Add(1)
		delay := pacing.Backoff(it.attempts, retryBase, w.Cfg.DeferralBackoff)
		if res.Infrastructure {
			w.Lim.PauseAll(pacing.Backoff(it.attempts, retryBase, infraPauseMax))
		}
		mlog.Info("temporary submission failure; will retry", "stage", res.Stage, "smtp_code", res.Code,
			"enhanced", res.Enhanced, "detail", res.Text, "retry_in", delay.String())
		w.Store.SetLastError(ctx, job.ID, w.Me, fmt.Sprintf("temporary submission failure at %s: %d %s", res.Stage, res.Code, res.Text))
		return result{it: it, retry: delay}
	}
}

// resolveAmbiguous decides an ambiguous submission from the Postfix log: if
// Postfix queued the message, record that; if no trace appears within the
// deadline, Postfix did not queue it and a resubmission is safe. A duplicate
// is worse than a delay, so the message is never resubmitted earlier.
func (w *Worker) resolveAmbiguous(ctx context.Context, job *store.Job, it *item, log *logx.Logger) result {
	want := map[string]bool{strings.ToLower(it.p.ID + "@" + w.Cfg.BounceDomain): true}
	deadline := w.Now().Add(w.AmbiguityDeadline)
	for {
		select {
		case <-ctx.Done():
			return result{it: it, lost: true}
		case <-time.After(w.SettleDelay):
		}
		found, err := postfixlog.FindSubmissions(w.logDir(), job.StartedAt.Add(-time.Hour), want)
		if err == nil {
			for _, sub := range found {
				if !sub.Committed {
					continue
				}
				r, ok := w.recordAcceptance(ctx, job, it, store.Acceptance{MessageID: it.p.ID, QueueID: sub.Cleanup.QueueID,
					Source: "postfix_log", Key: sub.Cleanup.Key(), OccurredAt: sub.Cleanup.Time,
					Diagnostic: "recovered from the Postfix log after an ambiguous submission"}, log)
				if ok {
					w.Stats.Recovered.Add(1)
					log.Info("ambiguous submission resolved from the log: Postfix had queued it", "postfix_queue_id", sub.Cleanup.QueueID)
					w.ingestQueueIDs(ctx, job, map[string]bool{sub.Cleanup.QueueID: true}, log)
				}
				return r
			}
		}
		if w.Now().After(deadline) {
			log.Info("ambiguous submission: Postfix never queued the message; resubmitting")
			return result{it: it, retry: time.Millisecond}
		}
	}
}

// recordAcceptance records a message Postfix has queued. It never leads to a
// resubmission: transient database errors retry only the recording; if the
// lease is lost or recording keeps failing, the message stays queued without
// a queue id and is parked (no retry), so the next lease owner recovers it
// from the Postfix log (§6.3) instead of sending it again.
func (w *Worker) recordAcceptance(ctx context.Context, job *store.Job, it *item, a store.Acceptance, log *logx.Logger) (result, bool) {
	var err error
	for attempt := 1; attempt <= 5; attempt++ {
		var ok bool
		if ok, err = w.Store.RecordAccepted(ctx, job, w.Me, a); err == nil {
			return result{it: it}, ok
		}
		if errors.Is(err, store.ErrLeaseLost) || ctx.Err() != nil {
			break
		}
		select {
		case <-ctx.Done():
		case <-time.After(time.Duration(attempt) * time.Second):
		}
	}
	w.Stats.AcceptedButUnrecorded.Add(1)
	log.Warning("Postfix queued the message but it could not be recorded; the next lease owner recovers it from the log",
		"postfix_queue_id", a.QueueID, "error", err)
	if errors.Is(err, store.ErrLeaseLost) || ctx.Err() != nil {
		return result{it: it, lost: true}, false
	}
	return result{it: it}, false // parked: never resubmitted by this worker
}

// ingestQueueIDs re-scans the retained log for queue ids whose acceptance was
// just recovered from the log: routine ingestion may already have passed their
// later records (delivery status) while the queue id was unknown.
func (w *Worker) ingestQueueIDs(ctx context.Context, job *store.Job, qids map[string]bool, log *logx.Logger) {
	if len(qids) == 0 {
		return
	}
	recs, err := postfixlog.FindQueueIDs(w.logDir(), job.StartedAt.Add(-time.Hour), qids)
	if err != nil {
		log.Warning("re-scanning the log for recovered queue ids failed", "error", err)
		return
	}
	byGen := map[string][]postfixlog.Located{}
	var order []string
	for _, l := range recs {
		if _, ok := byGen[l.GenerationID]; !ok {
			order = append(order, l.GenerationID)
		}
		byGen[l.GenerationID] = append(byGen[l.GenerationID], l)
	}
	for _, g := range order {
		if _, err := w.Store.ApplyLogBatch(ctx, byGen[g], g, 0, false, w.Cfg.BounceDomain, w.Now().UTC()); err != nil {
			log.Warning("applying re-scanned log records failed", "error", err)
		}
	}
}

func (w *Worker) writeFailed(it *item, err error, log *logx.Logger) result {
	if errors.Is(err, store.ErrLeaseLost) || errors.Is(err, context.Canceled) || errors.Is(err, context.DeadlineExceeded) {
		return result{it: it, lost: true}
	}
	log.Warning("recording failed; will retry", "error", err)
	return result{it: it, retry: retryBase}
}

// PoolStats is exposed for the health/stats output.
func PoolStats(p *pgxpool.Pool) map[string]any {
	s := p.Stat()
	return map[string]any{"acquired": s.AcquiredConns(), "total": s.TotalConns()}
}
