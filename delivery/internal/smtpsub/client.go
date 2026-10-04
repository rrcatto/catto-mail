// Package smtpsub submits one message with one recipient to Postfix's
// authenticated submission service (docs/architecture/postfix-integration.md §1):
//
//	EHLO, STARTTLS (when offered), EHLO, AUTH PLAIN,
//	MAIL FROM:<return-path> RET=HDRS ENVID=<message id> [SMTPUTF8]
//	RCPT TO:<recipient> NOTIFY=FAILURE,DELAY ORCPT=rfc822;<recipient>
//	DATA ... "250 2.0.0 Ok: queued as <QID>", QUIT
//
// Unlike the Phase 3 validator, this client is meant to send DATA: it delivers
// a real message to the local MTA. Postfix remains the transport; this is not
// an SMTP delivery engine.
//
// The outcome distinguishes what is safe to do next:
//
//	Accepted   Postfix queued the message (queue id captured)
//	Temporary  4xx, milter tempfail, refused/failed connection, or the
//	           connection dropped before the end of DATA: retry later
//	Permanent  5xx for this message (MAIL, RCPT or end of DATA)
//	Ambiguous  the complete message was sent but the final reply was lost:
//	           do NOT resubmit before checking the Postfix log (§6.3)
package smtpsub

import (
	"bufio"
	"context"
	"crypto/tls"
	"encoding/base64"
	"errors"
	"fmt"
	"net"
	"regexp"
	"strconv"
	"strings"
	"time"
)

// Outcome of one submission.
type Outcome int

const (
	Accepted Outcome = iota
	Temporary
	Permanent
	Ambiguous
)

func (o Outcome) String() string {
	return [...]string{"accepted", "temporary", "permanent", "ambiguous"}[o]
}

// Result describes a submission attempt.
type Result struct {
	Outcome  Outcome
	QueueID  string // Accepted only
	Code     int    // last SMTP reply code, 0 if none
	Enhanced string // RFC 3463 code of the last reply, if any
	Text     string // last reply text (no message content)
	Stage    string // connect, greeting, ehlo, starttls, auth, mail, rcpt, data, end-of-data
	// Infrastructure is true when the failure concerns the submission service
	// itself (connection, greeting, TLS, AUTH, milter tempfail), not this
	// message: callers pause all submissions instead of retrying at once.
	Infrastructure bool
}

// Config of the submission client.
type Config struct {
	Addr           string // host:port
	HeloName       string
	Username       string
	Password       string
	ConnectTimeout time.Duration
	CommandTimeout time.Duration
	DataTimeout    time.Duration // for the message transfer and the final reply
	// TLS for STARTTLS. Postfix presents a certificate the daemon cannot verify
	// against a configured CA (there is no contract variable for one); the hop
	// stays inside the smarthost pod. nil disables STARTTLS.
	TLS *tls.Config
	// RequireTLSForAuth refuses to send credentials over a plain connection.
	RequireTLSForAuth bool
}

// Envelope of one transaction.
type Envelope struct {
	From     string // return path (VERP)
	To       string // the single recipient
	EnvID    string // ENVID (message id)
	Ret      string // HDRS or FULL
	Notify   string // e.g. FAILURE,DELAY
	SMTPUTF8 bool
}

var queuedAs = regexp.MustCompile(`(?i)queued as ([0-9A-Za-z]+)`)
var enhanced = regexp.MustCompile(`^([245])\.(\d{1,3})\.(\d{1,3})\b`)

type reply struct {
	code     int
	enhanced string
	text     string   // all lines joined with spaces
	lines    []string // text of each reply line
}

type session struct {
	conn net.Conn
	r    *bufio.Reader
	cfg  Config
	ext  map[string]string
	tls  bool
}

func (s *session) deadline(d time.Duration) { _ = s.conn.SetDeadline(time.Now().Add(d)) }

func (s *session) readReply() (reply, error) {
	var rp reply
	var lines []string
	for {
		line, err := s.r.ReadString('\n')
		if err != nil {
			return rp, err
		}
		line = strings.TrimRight(line, "\r\n")
		if len(line) < 3 {
			return rp, fmt.Errorf("short reply %q", line)
		}
		code, err := strconv.Atoi(line[:3])
		if err != nil {
			return rp, fmt.Errorf("malformed reply %q", line)
		}
		rp.code = code
		text := ""
		if len(line) > 4 {
			text = line[4:]
		}
		lines = append(lines, text)
		if len(line) == 3 || line[3] == ' ' {
			break
		}
	}
	rp.text = strings.Join(lines, " ")
	rp.lines = lines
	if m := enhanced.FindStringSubmatch(lines[0]); m != nil {
		rp.enhanced = m[0]
	}
	return rp, nil
}

func (s *session) cmd(line string) (reply, error) {
	s.deadline(s.cfg.CommandTimeout)
	if _, err := s.conn.Write([]byte(line + "\r\n")); err != nil {
		return reply{}, err
	}
	return s.readReply()
}

// Submit performs one transaction. It never retries by itself.
func Submit(ctx context.Context, cfg Config, env Envelope, data []byte) Result {
	d := net.Dialer{Timeout: cfg.ConnectTimeout}
	conn, err := d.DialContext(ctx, "tcp", cfg.Addr)
	if err != nil {
		return Result{Outcome: Temporary, Stage: "connect", Text: err.Error(), Infrastructure: true}
	}
	defer conn.Close()
	s := &session{conn: conn, r: bufio.NewReader(conn), cfg: cfg}
	stop := context.AfterFunc(ctx, func() { _ = conn.SetDeadline(time.Now()) })
	defer stop()

	s.deadline(cfg.CommandTimeout)
	greet, err := s.readReply()
	if err != nil {
		return Result{Outcome: Temporary, Stage: "greeting", Text: err.Error(), Infrastructure: true}
	}
	if greet.code != 220 {
		return infra("greeting", greet)
	}
	if res, ok := s.hello(); !ok {
		return res
	}
	if _, offered := s.ext["STARTTLS"]; offered && cfg.TLS != nil {
		rp, err := s.cmd("STARTTLS")
		if err != nil {
			return Result{Outcome: Temporary, Stage: "starttls", Text: err.Error(), Infrastructure: true}
		}
		if rp.code != 220 {
			return infra("starttls", rp)
		}
		tc := tls.Client(conn, cfg.TLS)
		s.deadline(cfg.CommandTimeout)
		if err := tc.Handshake(); err != nil {
			return Result{Outcome: Temporary, Stage: "starttls", Text: err.Error(), Infrastructure: true}
		}
		s.conn, s.r, s.tls = tc, bufio.NewReader(tc), true
		if res, ok := s.hello(); !ok {
			return res
		}
	}
	if cfg.Username != "" {
		if _, offered := s.ext["AUTH"]; !offered {
			return Result{Outcome: Temporary, Stage: "auth", Text: "server does not offer AUTH", Infrastructure: true}
		}
		if cfg.RequireTLSForAuth && !s.tls {
			return Result{Outcome: Temporary, Stage: "auth", Text: "refusing AUTH without TLS", Infrastructure: true}
		}
		token := base64.StdEncoding.EncodeToString([]byte("\x00" + cfg.Username + "\x00" + cfg.Password))
		rp, err := s.cmd("AUTH PLAIN " + token)
		if err != nil {
			return Result{Outcome: Temporary, Stage: "auth", Text: err.Error(), Infrastructure: true}
		}
		if rp.code != 235 {
			return infra("auth", rp)
		}
	}

	_, dsn := s.ext["DSN"]
	mailCmd := "MAIL FROM:<" + env.From + ">"
	if dsn {
		if env.Ret != "" {
			mailCmd += " RET=" + env.Ret
		}
		if env.EnvID != "" {
			mailCmd += " ENVID=" + XText(env.EnvID)
		}
	}
	if env.SMTPUTF8 {
		if _, ok := s.ext["SMTPUTF8"]; !ok {
			return Result{Outcome: Permanent, Stage: "mail", Text: "the address needs SMTPUTF8, which the submission service does not offer"}
		}
		mailCmd += " SMTPUTF8"
	}
	if res, ok := s.step("mail", mailCmd, 250); !ok {
		return res
	}
	rcptCmd := "RCPT TO:<" + env.To + ">"
	if dsn {
		if env.Notify != "" {
			rcptCmd += " NOTIFY=" + env.Notify
		}
		if !env.SMTPUTF8 {
			rcptCmd += " ORCPT=rfc822;" + XText(env.To)
		}
	}
	if res, ok := s.step("rcpt", rcptCmd, 250, 251); !ok {
		return res
	}
	if res, ok := s.step("data", "DATA", 354); !ok {
		return res
	}
	// Transfer. A failure while writing means the end-of-data marker was not
	// (completely) sent, so Postfix cannot have queued the message.
	s.deadline(cfg.DataTimeout)
	if _, err := s.conn.Write(dotStuff(data)); err != nil {
		return Result{Outcome: Temporary, Stage: "data", Text: "connection lost during DATA: " + err.Error()}
	}
	// From here on the message is complete on the wire: without a reply the
	// result is ambiguous.
	final, err := s.readReply()
	if err != nil {
		return Result{Outcome: Ambiguous, Stage: "end-of-data", Text: err.Error()}
	}
	res := classify("end-of-data", final)
	if res.Outcome == Accepted {
		if m := queuedAs.FindStringSubmatch(final.text); m != nil {
			res.QueueID = m[1]
		} else {
			// Accepted without a recognisable queue id: correlate through the log.
			res.Outcome = Ambiguous
			res.Text = "accepted without a queue id: " + final.text
		}
	}
	s.deadline(cfg.CommandTimeout)
	_, _ = s.conn.Write([]byte("QUIT\r\n"))
	_, _ = s.readReply()
	return res
}

// hello sends EHLO and records the advertised extensions.
func (s *session) hello() (Result, bool) {
	rp, err := s.cmd("EHLO " + s.cfg.HeloName)
	if err != nil {
		return Result{Outcome: Temporary, Stage: "ehlo", Text: err.Error(), Infrastructure: true}, false
	}
	if rp.code != 250 {
		return infra("ehlo", rp), false
	}
	s.ext = parseExtensions(rp.lines)
	return Result{}, true
}

// step sends one transaction command and classifies a non-expected reply.
func (s *session) step(stage, line string, want ...int) (Result, bool) {
	rp, err := s.cmd(line)
	if err != nil {
		return Result{Outcome: Temporary, Stage: stage, Text: err.Error(), Infrastructure: stage == "mail"}, false
	}
	for _, w := range want {
		if rp.code == w {
			return Result{}, true
		}
	}
	return classify(stage, rp), false
}

func classify(stage string, rp reply) Result {
	r := Result{Code: rp.code, Enhanced: rp.enhanced, Text: rp.text, Stage: stage}
	switch {
	case rp.code >= 200 && rp.code < 300:
		r.Outcome = Accepted
	case rp.code >= 400 && rp.code < 500:
		r.Outcome = Temporary
		// 421 closes the session; 4.7.x at MAIL/end-of-data is how a milter
		// tempfail (OpenDKIM unavailable) appears: both concern the service.
		r.Infrastructure = rp.code == 421 || strings.HasPrefix(rp.enhanced, "4.7.") || strings.HasPrefix(rp.enhanced, "4.3.")
	default:
		r.Outcome = Permanent
	}
	return r
}

// infra classifies a failure before the transaction (greeting, EHLO, STARTTLS,
// AUTH): never a verdict on the message, always retryable.
func infra(stage string, rp reply) Result {
	return Result{Outcome: Temporary, Stage: stage, Code: rp.code, Enhanced: rp.enhanced, Text: rp.text, Infrastructure: true}
}

// parseExtensions reads the EHLO keywords: every line after the first starts
// with one ("AUTH PLAIN LOGIN", "SIZE 10240000", "DSN", ...).
func parseExtensions(lines []string) map[string]string {
	ext := map[string]string{}
	for _, l := range lines[1:] {
		f := strings.Fields(l)
		if len(f) == 0 {
			continue
		}
		kw := strings.ToUpper(strings.SplitN(f[0], "=", 2)[0])
		ext[kw] = strings.TrimSpace(strings.TrimPrefix(l, f[0]))
	}
	return ext
}

// XText encodes an RFC 3461 xtext value (ENVID, ORCPT).
func XText(s string) string {
	var b strings.Builder
	for i := 0; i < len(s); i++ {
		c := s[i]
		if c < 33 || c > 126 || c == '+' || c == '=' {
			fmt.Fprintf(&b, "+%02X", c)
		} else {
			b.WriteByte(c)
		}
	}
	return b.String()
}

// dotStuff applies RFC 5321 transparency and appends the end-of-data marker.
// data must already use CRLF line endings.
func dotStuff(data []byte) []byte {
	out := make([]byte, 0, len(data)+64)
	start := true
	for _, c := range data {
		if start && c == '.' {
			out = append(out, '.')
		}
		out = append(out, c)
		start = c == '\n'
	}
	if !start {
		out = append(out, '\r', '\n')
	}
	return append(out, '.', '\r', '\n')
}

// ErrNoQueueID is returned by parsers when a reply lacks a queue id.
var ErrNoQueueID = errors.New("no queue id in reply")

// QueueID extracts the queue id from a final reply text.
func QueueID(text string) (string, error) {
	if m := queuedAs.FindStringSubmatch(text); m != nil {
		return m[1], nil
	}
	return "", ErrNoQueueID
}
