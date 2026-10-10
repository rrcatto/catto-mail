// Package postfixlog reads the Postfix log in the shared observability volume
// (docs/architecture/postfix-integration.md §3) and turns the records Phase 4
// needs into message events. It never reads journald or host logs.
package postfixlog

import (
	"regexp"
	"strconv"
	"strings"
	"time"

	"smarthost.local/delivery/internal/smtpclass"
)

// Kind of a parsed record.
type Kind int

const (
	Other       Kind = iota
	MessageID        // cleanup: QID: message-id=<...>
	QueueActive      // qmgr: QID: from=<...>, size=..., nrcpt=N (queue active)
	Delivery         // smtp/lmtp/virtual/...: QID: to=<...>, relay=..., dsn=..., status=...
	Removed          // qmgr/postsuper: QID: removed
)

// Record is one parsed log line.
type Record struct {
	Time      time.Time
	Process   string // e.g. postfix/smtp, postfix/submission/smtpd
	QueueID   string
	Kind      Kind
	MessageID string // MessageID records
	To        string
	Relay     string
	DSN       string // enhanced status, e.g. 4.4.1
	Status    string // sent, deferred, bounced, expired
	Text      string // the parenthesised status text
}

var (
	syslogLine = regexp.MustCompile(`^([A-Z][a-z]{2}) +(\d{1,2}) (\d{2}):(\d{2}):(\d{2})(?:\.\d+)? \S+ (postfix[^\[\s]*)\[\d+\]: ([0-9A-Za-z]{6,}): (.*)$`)
	isoLine    = regexp.MustCompile(`^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})) \S+ (postfix[^\[\s]*)\[\d+\]: ([0-9A-Za-z]{6,}): (.*)$`)
	msgIDRe    = regexp.MustCompile(`^message-id=<([^>]*)>`)
	fromRe     = regexp.MustCompile(`^from=<[^>]*>, size=\d+, nrcpt=\d+ \(queue active\)`)
	toRe       = regexp.MustCompile(`^to=<([^>]*)>`)
	relayRe    = regexp.MustCompile(`, relay=([^,]+),`)
	dsnRe      = regexp.MustCompile(`, dsn=(\d\.\d{1,3}\.\d{1,3}),`)
	statusRe   = regexp.MustCompile(`, status=([a-z]+)(?: \((.*)\))?$`)
	months     = map[string]time.Month{"Jan": 1, "Feb": 2, "Mar": 3, "Apr": 4, "May": 5, "Jun": 6, "Jul": 7, "Aug": 8, "Sep": 9, "Oct": 10, "Nov": 11, "Dec": 12}
)

// Parse parses one record (without its newline). now resolves the year of
// syslog-style timestamps, which have none: the latest year that does not put
// the record more than a day into the future. Postfix writes the installation's
// local time (TZ=SMARTHOST_TIMEZONE in its container), so a syslog-style
// timestamp is read in now's location. ok is false for lines without a queue id.
func Parse(line string, now time.Time) (Record, bool) {
	var r Record
	var rest string
	if m := syslogLine.FindStringSubmatch(line); m != nil {
		day, _ := strconv.Atoi(m[2])
		h, _ := strconv.Atoi(m[3])
		mi, _ := strconv.Atoi(m[4])
		s, _ := strconv.Atoi(m[5])
		t := time.Date(now.Year(), months[m[1]], day, h, mi, s, 0, now.Location())
		if t.After(now.Add(24 * time.Hour)) {
			t = t.AddDate(-1, 0, 0)
		}
		r.Time, r.Process, r.QueueID, rest = t, m[6], m[7], m[8]
	} else if m := isoLine.FindStringSubmatch(line); m != nil {
		t, err := time.Parse(time.RFC3339Nano, normaliseOffset(m[1]))
		if err != nil {
			return r, false
		}
		r.Time, r.Process, r.QueueID, rest = t.In(now.Location()), m[2], m[3], m[4]
	} else {
		return r, false
	}
	switch {
	case strings.HasPrefix(rest, "message-id="):
		if m := msgIDRe.FindStringSubmatch(rest); m != nil {
			r.Kind, r.MessageID = MessageID, m[1]
		}
	case fromRe.MatchString(rest):
		r.Kind = QueueActive
	case strings.HasPrefix(rest, "to=<"):
		m := toRe.FindStringSubmatch(rest)
		sm := statusRe.FindStringSubmatch(rest)
		if m == nil || sm == nil {
			return r, true
		}
		r.Kind, r.To, r.Status, r.Text = Delivery, m[1], sm[1], sm[2]
		if x := relayRe.FindStringSubmatch(rest); x != nil {
			r.Relay = x[1]
		}
		if x := dsnRe.FindStringSubmatch(rest); x != nil {
			r.DSN = x[1]
		}
	case rest == "removed":
		r.Kind = Removed
	}
	return r, true
}

func normaliseOffset(ts string) string {
	if n := len(ts); n > 5 && (ts[n-5] == '+' || ts[n-5] == '-') && ts[n-3] != ':' {
		return ts[:n-2] + ":" + ts[n-2:]
	}
	return ts
}

// Event is the message event a record yields (status-vocabulary
// message_event_type, failure_scope).
type Event struct {
	Type         string
	FailureScope string // deferred, connection_failure, soft_bounce, hard_bounce only
	SMTPCode     int
	Enhanced     string
	RemoteHost   string
	Diagnostic   string
}

var (
	saidCode    = regexp.MustCompile(`said: (\d{3})[ -]`)
	leadingCode = regexp.MustCompile(`^(\d{3})[ -]`)
	hostRe      = regexp.MustCompile(`^([^\[\s]+)(?:\[[^\]]*\])?`)
)

// Classify maps a Delivery record to its event. QueueActive and MessageID
// records are handled by the caller (they depend on message state).
func Classify(r Record) (Event, bool) {
	if r.Kind != Delivery {
		return Event{}, false
	}
	e := Event{Enhanced: r.DSN, Diagnostic: truncate(r.Text, 1000)}
	if m := hostRe.FindStringSubmatch(r.Relay); m != nil && m[1] != "none" {
		e.RemoteHost = m[1]
	}
	if m := saidCode.FindStringSubmatch(r.Text); m != nil {
		e.SMTPCode, _ = strconv.Atoi(m[1])
	} else if m := leadingCode.FindStringSubmatch(r.Text); m != nil {
		e.SMTPCode, _ = strconv.Atoi(m[1])
	}
	switch r.Status {
	case "sent":
		e.Type = "remote_accepted"
	case "deferred":
		e.Type, e.FailureScope = "deferred", Scope(r.DSN, r.Text)
		if e.FailureScope == smtpclass.Connection {
			e.Type = "connection_failure"
		}
	case "bounced":
		if strings.HasPrefix(r.DSN, "4.") {
			e.Type = "soft_bounce"
		} else {
			e.Type = "hard_bounce"
		}
		e.FailureScope = Scope(r.DSN, r.Text)
	case "expired":
		e.Type, e.FailureScope = "soft_bounce", Scope(r.DSN, r.Text)
	default:
		return Event{}, false
	}
	if e.Enhanced != "" && !validEnhanced(e.Enhanced) {
		e.Enhanced = ""
	}
	if e.SMTPCode != 0 && (e.SMTPCode < 200 || e.SMTPCode > 599) {
		e.SMTPCode = 0
	}
	return e, true
}

// Scope classifies the failure scope of a deferral or bounce (D-18) with the
// classifier shared by every evidence source (internal/smtpclass, D-30).
func Scope(dsn, text string) string { return smtpclass.Scope(dsn, text) }

func validEnhanced(s string) bool { return smtpclass.Valid(s) }

func truncate(s string, n int) string {
	if len(s) <= n {
		return s
	}
	return s[:n]
}
