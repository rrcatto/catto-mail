// Package dsnspool consumes the shared DSN Maildir spool
// (docs/architecture/postfix-integration.md §5):
//
//	inbound/new/F --rename--> processing/K --(one DB transaction)--> done/K --(retention)--> deleted
//
// K is the Maildir unique file name (F without its ":2,..." info suffix), the
// D-06 source key of every event and unmatched row the file produces. The
// rename is the claim: exactly one worker wins, the loser gets ENOENT. A file
// left in processing/ longer than DELIVERY_LEASE_SECONDS (crashed worker) is
// reclaimed by another rename and processed again; the database records each
// key once, so crash, retry, restart and duplicate notifications never
// duplicate an event or an unmatched row. Files that cannot be read go to
// failed/ (operator alert); nothing else is ever lost: the database row is the
// durable record and the processed file stays in done/ for
// DELIVERY_DSN_RETENTION_DAYS.
package dsnspool

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"io"
	"io/fs"
	"os"
	"path/filepath"
	"sort"
	"strconv"
	"strings"
	"sync/atomic"
	"syscall"
	"time"

	"smarthost.local/delivery/internal/dsn"
	"smarthost.local/delivery/internal/ids"
	"smarthost.local/delivery/internal/logx"
	"smarthost.local/delivery/internal/store"
)

// readLimit bounds what is read of one spool file (Postfix's
// message_size_limit is far lower; a larger file is parsed truncated).
const readLimit = 16 << 20

// Stats are cumulative counters.
type Stats struct {
	Claimed, Reclaimed, Events, Partial, Informational, Unmatched, Already atomic.Int64
	Failed, Retries, Deleted, Suppressions                                 atomic.Int64
}

// Processor claims and ingests spool files.
type Processor struct {
	Dir          string // SMARTHOST_DSN_SPOOL_DIR
	Store        *store.Store
	Log          *logx.Logger
	VERP         ids.VERP
	BounceDomain string
	PollInterval time.Duration // DELIVERY_FILE_POLL_INTERVAL_SECONDS (fallback to inotify)
	StaleAfter   time.Duration // DELIVERY_LEASE_SECONDS: reclaim processing/ files older than this
	Retention    time.Duration // DELIVERY_DSN_RETENTION_DAYS for done/
	Instance     string        // this process; names reclaimed files
	DisableWatch bool          // tests: polling only
	Now          func() time.Time
	// AfterCommit, when set, runs after the database commit and before the move
	// to done/ (crash-injection tests).
	AfterCommit func(key string) error
	Stats       Stats
	retry       map[string]time.Time // processing/ files to retry after a transient error
}

func (p *Processor) now() time.Time {
	if p.Now != nil {
		return p.Now()
	}
	return time.Now()
}

func (p *Processor) path(parts ...string) string {
	return filepath.Join(append([]string{p.Dir}, parts...)...)
}

// Key is the Maildir unique name of a spool file name: the base name without
// the ":2,flags" info suffix and without a reclaim suffix ("#...").
func Key(name string) string {
	name = filepath.Base(name)
	if i := strings.IndexAny(name, ":#"); i >= 0 {
		name = name[:i]
	}
	return name
}

// Run processes the spool until ctx ends: on inotify events in inbound/new and
// every PollInterval, plus the hourly retention sweep.
func (p *Processor) Run(ctx context.Context) {
	wake := make(chan struct{}, 1)
	if !p.DisableWatch {
		go p.watch(ctx, wake)
	}
	lastSweep := time.Time{}
	for ctx.Err() == nil {
		if _, err := p.Pass(ctx); err != nil && ctx.Err() == nil {
			p.Log.Warning("DSN spool pass failed", "error", err)
		}
		if p.now().Sub(lastSweep) >= time.Hour {
			if n, err := p.Sweep(); err != nil {
				p.Log.Warning("DSN spool retention sweep failed", "error", err)
			} else if n > 0 {
				p.Log.Info("DSN spool retention: deleted processed files", "deleted", n)
			}
			lastSweep = p.now()
		}
		select {
		case <-ctx.Done():
		case <-wake:
		case <-time.After(p.PollInterval):
		}
	}
}

// PassResult counts one pass.
type PassResult struct{ Processed, Retry int }

// Pass reclaims stale claims, retries due files and claims every new file.
func (p *Processor) Pass(ctx context.Context) (PassResult, error) {
	var res PassResult
	if p.retry == nil {
		p.retry = map[string]time.Time{}
	}
	// 1. Stale claims of crashed workers (and our own due retries).
	entries, err := os.ReadDir(p.path("processing"))
	if err != nil {
		return res, err
	}
	for _, e := range entries {
		if ctx.Err() != nil {
			return res, ctx.Err()
		}
		if !e.Type().IsRegular() {
			continue
		}
		path := p.path("processing", e.Name())
		if due, mine := p.retry[path]; mine {
			if p.now().Before(due) {
				res.Retry++
				continue
			}
			delete(p.retry, path)
			p.Stats.Retries.Add(1)
			p.process(ctx, path, &res)
			continue
		}
		if !p.stale(path) {
			continue
		}
		reclaimed := p.path("processing", Key(e.Name())+"#"+safeName(p.Instance)+"-"+strconv.FormatInt(p.now().UnixNano(), 36))
		if err := os.Rename(path, reclaimed); err != nil {
			if !errors.Is(err, fs.ErrNotExist) {
				p.Log.Warning("reclaiming a stale DSN spool claim failed", "file", e.Name(), "error", err)
			}
			continue // another worker reclaimed it first
		}
		p.Stats.Reclaimed.Add(1)
		p.Log.Warning("reclaimed a DSN spool file left in processing/ by a stopped worker", "spool_key", Key(e.Name()))
		p.process(ctx, reclaimed, &res)
	}
	// 2. New files, oldest first.
	entries, err = os.ReadDir(p.path("inbound", "new"))
	if errors.Is(err, fs.ErrNotExist) {
		return res, nil // Postfix creates the Maildir on its first delivery
	}
	if err != nil {
		return res, err
	}
	sort.Slice(entries, func(i, j int) bool { return entries[i].Name() < entries[j].Name() })
	for _, e := range entries {
		if ctx.Err() != nil {
			return res, ctx.Err()
		}
		if !e.Type().IsRegular() || strings.HasPrefix(e.Name(), ".") {
			continue
		}
		claimed := p.path("processing", Key(e.Name()))
		if err := os.Rename(p.path("inbound", "new", e.Name()), claimed); err != nil {
			if !errors.Is(err, fs.ErrNotExist) {
				p.Log.Warning("claiming a DSN spool file failed", "file", e.Name(), "error", err)
			}
			continue // another worker won the claim
		}
		p.Stats.Claimed.Add(1)
		p.process(ctx, claimed, &res)
	}
	return res, nil
}

// stale reports a processing/ file whose claim (rename = ctime) is older than StaleAfter.
func (p *Processor) stale(path string) bool {
	info, err := os.Stat(path)
	if err != nil {
		return false
	}
	t := info.ModTime()
	if st, ok := info.Sys().(*syscall.Stat_t); ok {
		if c := time.Unix(st.Ctim.Sec, st.Ctim.Nsec); c.After(t) {
			t = c
		}
	}
	return p.now().Sub(t) > p.StaleAfter
}

// process ingests one claimed file in processing/.
func (p *Processor) process(ctx context.Context, path string, res *PassResult) {
	key := Key(path)
	log := p.Log.With("spool_key", key)
	f, err := os.Open(path)
	if errors.Is(err, fs.ErrNotExist) {
		return // reclaimed by another worker meanwhile
	}
	var raw []byte
	var info os.FileInfo
	if err == nil {
		info, err = f.Stat()
		if err == nil {
			raw, err = io.ReadAll(io.LimitReader(f, readLimit))
		}
		f.Close()
	}
	if err != nil {
		p.Stats.Failed.Add(1)
		dst := p.path("failed", key)
		if mvErr := os.Rename(path, dst); mvErr != nil {
			log.Error("unreadable DSN spool file could not be moved to failed/", "error", err, "move_error", mvErr)
			return
		}
		log.Error("OPERATOR ALERT: unreadable DSN spool file moved to failed/", "error", err)
		return
	}
	sum := sha256.Sum256(raw)
	report := dsn.Parse(raw)
	in := store.DSNInput{Key: key, Raw: raw, SHA256: hex.EncodeToString(sum[:]), ReceivedAt: receivedAt(key, info.ModTime()),
		Report: report, Evidence: report.Evidence(p.VERP, p.BounceDomain)}
	r, err := p.Store.IngestDSN(ctx, in)
	if err != nil {
		if ctx.Err() == nil {
			log.Warning("DSN ingestion failed; the claimed file is retried", "error", err)
			p.retry[path] = p.now().Add(min(30*time.Second, max(p.PollInterval, time.Second)*5))
		}
		res.Retry++
		return
	}
	if p.AfterCommit != nil {
		if err := p.AfterCommit(key); err != nil {
			return // simulated crash between commit and move
		}
	}
	if err := os.Rename(path, p.path("done", key)); err != nil && !errors.Is(err, fs.ErrNotExist) {
		log.Warning("moving a processed DSN spool file to done/ failed; it is reprocessed harmlessly", "error", err)
	}
	res.Processed++
	switch r.Result {
	case store.DSNEvent:
		p.Stats.Events.Add(1)
	case store.DSNPartial:
		p.Stats.Partial.Add(1)
	case store.DSNInformational:
		p.Stats.Informational.Add(1)
	case store.DSNUnmatched:
		p.Stats.Unmatched.Add(1)
	case store.DSNAlready:
		p.Stats.Already.Add(1)
	}
	p.Stats.Suppressions.Add(int64(len(r.Suppressions)))
	fields := []any{"result", r.Result, "message_id", r.MessageID, "event", r.EventType, "correlation", r.Correlation, "kind", string(report.Kind)}
	if r.Reason != "" {
		fields = append(fields, "reason", r.Reason)
	}
	for _, s := range r.Suppressions {
		log.Info("global suppression created", "suppression_id", s.ID, "reason", s.Reason, "message_id", s.MessageID)
	}
	if r.Result == store.DSNUnmatched {
		log.Info("DSN could not be correlated; kept as an unmatched DSN for the operator", fields...)
		return
	}
	log.Info("DSN processed", fields...)
}

// safeName makes a worker id usable inside a file name (worker ids contain "/").
func safeName(s string) string {
	b := []byte(s)
	for i, c := range b {
		if !(c >= 'a' && c <= 'z' || c >= 'A' && c <= 'Z' || c >= '0' && c <= '9' || c == '-' || c == '_') {
			b[i] = '-'
		}
	}
	return string(b)
}

// receivedAt is the delivery time encoded in the Maildir name
// ("<epoch>.V...") when plausible, else the file's modification time.
func receivedAt(key string, mtime time.Time) time.Time {
	if head, _, ok := strings.Cut(key, "."); ok {
		if sec, err := strconv.ParseInt(head, 10, 64); err == nil {
			t := time.Unix(sec, 0)
			if t.After(time.Date(2020, 1, 1, 0, 0, 0, 0, time.Local)) && t.Before(time.Now().Add(24*time.Hour)) {
				return t
			}
		}
	}
	return mtime
}

// Sweep deletes processed files older than the retention period. The database
// records (events, unmatched_dsns) are never touched.
func (p *Processor) Sweep() (int, error) {
	entries, err := os.ReadDir(p.path("done"))
	if err != nil {
		return 0, err
	}
	n := 0
	for _, e := range entries {
		info, err := e.Info()
		if err != nil || !info.Mode().IsRegular() {
			continue
		}
		if p.now().Sub(info.ModTime()) > p.Retention {
			if err := os.Remove(p.path("done", e.Name())); err == nil {
				n++
			}
		}
	}
	p.Stats.Deleted.Add(int64(n))
	return n, nil
}

// watch wakes Run on inotify events in inbound/new (IN_CREATE: Postfix links the
// complete file from tmp/; IN_MOVED_TO for other writers). Polling remains the
// fallback, so a missed event or an unavailable watch only delays processing.
func (p *Processor) watch(ctx context.Context, wake chan<- struct{}) {
	dir := p.path("inbound", "new")
	for ctx.Err() == nil {
		fd, err := syscall.InotifyInit1(syscall.IN_CLOEXEC)
		if err == nil {
			if _, err = syscall.InotifyAddWatch(fd, dir, syscall.IN_CREATE|syscall.IN_MOVED_TO); err == nil {
				p.readEvents(ctx, fd, wake)
			}
			syscall.Close(fd)
		}
		select { // the Maildir may not exist yet, or the watch was lost: retry
		case <-ctx.Done():
			return
		case <-time.After(max(p.PollInterval, time.Second) * 5):
		}
	}
}

func (p *Processor) readEvents(ctx context.Context, fd int, wake chan<- struct{}) {
	buf := make([]byte, 64*(syscall.SizeofInotifyEvent+syscall.NAME_MAX+1))
	for ctx.Err() == nil {
		n, err := syscall.Read(fd, buf)
		if err != nil || n <= 0 {
			if err == syscall.EINTR {
				continue
			}
			return
		}
		select {
		case wake <- struct{}{}:
		default:
		}
	}
}
