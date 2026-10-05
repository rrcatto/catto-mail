// Package dsn parses inbound bounce and complaint mail from the DSN spool
// (docs/architecture/postfix-integration.md §5): RFC 3464 delivery status
// notifications (multipart/report; report-type=delivery-status, and the RFC 6533
// global variant), RFC 5965 ARF feedback reports, and non-standard bounces.
//
// Input is untrusted remote content. It is only parsed as data: nothing is
// executed, rendered or followed, sizes and MIME structure are bounded, and a
// malformed message never fails the parse - it yields a Report with Problems, so
// the caller can still keep it as an unmatched DSN. No field is assumed present.
package dsn

import (
	"bufio"
	"bytes"
	"encoding/base64"
	"io"
	"mime"
	"mime/multipart"
	"mime/quotedprintable"
	"net/mail"
	"net/textproto"
	"regexp"
	"strings"
	"time"
)

// Parsing limits. The whole file is bounded by the caller (MaxMessageBytes).
const (
	MaxMessageBytes = 10 << 20  // larger spool files are truncated before parsing
	maxPartBytes    = 1 << 20   // status, feedback and header parts
	maxTextScan     = 256 << 10 // text parts scanned for returned identifiers
	maxParts        = 32        // MIME parts visited in total
	maxDepth        = 4         // MIME nesting
	maxRecipients   = 100       // per-recipient blocks kept
)

// Kind of report.
type Kind string

const (
	DeliveryStatus Kind = "delivery_status" // RFC 3464 / RFC 6533
	FeedbackReport Kind = "feedback_report" // RFC 5965 (ARF)
	Unstructured   Kind = "unstructured"    // anything else (non-standard bounce)
)

// Recipient is one per-recipient block of a delivery status notification.
type Recipient struct {
	FinalRecipient    string // address only; the "rfc822;" type is removed
	OriginalRecipient string
	Action            string // lower-case: failed, delayed, delivered, relayed, expanded
	Status            string // enhanced status code when well formed
	Diagnostic        string // Diagnostic-Code text after its type ("smtp; ...")
	RemoteMTA         string
	LastAttempt       time.Time
}

// Returned holds identifiers from the returned original headers
// (text/rfc822-headers or message/rfc822).
type Returned struct {
	MessageID, SmarthostMessageID, From, To string
}

// Feedback holds the fields of an ARF message/feedback-report part.
type Feedback struct {
	Type, UserAgent, Version, OriginalMailFrom, OriginalRcptTo, ReportingMTA, SourceIP string
	ArrivalDate                                                                        time.Time
}

// Report is everything parsed from one spool file.
type Report struct {
	Kind Kind
	// DeliveredTo and OriginalTo are the topmost Delivered-To / X-Original-To
	// headers: the envelope recipient as prepended by the receiving Postfix
	// (virtual(8)). Lower ones may come from the remote sender and are ignored.
	DeliveredTo, OriginalTo string
	Date                    time.Time
	Subject                 string
	// Per-message DSN fields.
	ReportingMTA, EnvelopeID, PostfixQueueID string
	ArrivalDate                              time.Time
	Recipients                               []Recipient
	Returned                                 *Returned
	Feedback                                 *Feedback
	// BodyIdentifiers are Message-ID / X-Smarthost-Message-ID values found in
	// text parts (non-standard bounces quote the original headers inline).
	BodyIdentifiers []string
	Problems        []string
	parts           int
}

var (
	idLineRe = regexp.MustCompile(`(?im)^[ \t>]*(?:X-Smarthost-Message-ID|Message-ID)[ \t]*:[ \t]*(<[^<>\s]{1,300}>|[^<>\s]{1,300})`)
	angleRe  = regexp.MustCompile(`<([^<>]*)>`)
)

// Parse parses a raw spool file. It never fails.
func Parse(raw []byte) *Report {
	r := &Report{Kind: Unstructured}
	if len(raw) > MaxMessageBytes {
		raw = raw[:MaxMessageBytes]
		r.problem("message truncated to %d bytes for parsing", MaxMessageBytes)
	}
	msg, err := mail.ReadMessage(bufio.NewReader(bytes.NewReader(normaliseNewlines(raw))))
	if err != nil {
		r.problem("unparseable message header: %v", err)
		r.scanText(raw)
		return r
	}
	r.DeliveredTo = first(msg.Header, "Delivered-To")
	r.OriginalTo = first(msg.Header, "X-Original-To")
	if d, err := mail.ParseDate(msg.Header.Get("Date")); err == nil {
		r.Date = d.UTC()
	}
	r.Subject = clip(msg.Header.Get("Subject"), 300)
	r.walk(textproto.MIMEHeader(msg.Header), msg.Body, 0)
	switch {
	case r.Feedback != nil:
		r.Kind = FeedbackReport
	case len(r.Recipients) > 0 || r.ReportingMTA != "" || r.EnvelopeID != "":
		r.Kind = DeliveryStatus
	}
	return r
}

func (r *Report) problem(format string, args ...any) {
	if len(r.Problems) < 20 {
		r.Problems = append(r.Problems, clip(sprintf(format, args...), 300))
	}
}

// walk visits one MIME entity.
func (r *Report) walk(h textproto.MIMEHeader, body io.Reader, depth int) {
	r.parts++
	if r.parts > maxParts {
		if r.parts == maxParts+1 {
			r.problem("more than %d MIME parts; the rest is ignored", maxParts)
		}
		return
	}
	ct := h.Get("Content-Type")
	media, params, err := mime.ParseMediaType(ct)
	if ct == "" || err != nil {
		if ct != "" {
			r.problem("malformed Content-Type %q", clip(ct, 100))
		}
		media, params = "text/plain", map[string]string{}
	}
	body = decodeTransfer(h.Get("Content-Transfer-Encoding"), body)
	switch {
	case strings.HasPrefix(media, "multipart/"):
		if depth >= maxDepth {
			r.problem("MIME nesting deeper than %d", maxDepth)
			return
		}
		boundary := params["boundary"]
		if boundary == "" {
			r.problem("%s without boundary", media)
			r.scanReader(body)
			return
		}
		mr := multipart.NewReader(body, boundary)
		for {
			p, err := mr.NextRawPart() // raw: transfer encodings are decoded by walk itself
			if err == io.EOF {
				return
			}
			if err != nil {
				r.problem("malformed multipart body: %v", err)
				return
			}
			r.walk(p.Header, p, depth+1)
			if r.parts > maxParts {
				return
			}
		}
	case media == "message/delivery-status" || media == "message/global-delivery-status":
		r.parseDeliveryStatus(readLimited(body, maxPartBytes))
	case media == "message/feedback-report":
		r.parseFeedback(readLimited(body, maxPartBytes))
	case media == "message/rfc822" || media == "message/global" || media == "text/rfc822-headers" || media == "message/global-headers":
		r.parseReturned(readLimited(body, maxPartBytes))
	case strings.HasPrefix(media, "text/"):
		r.scanReader(body)
	}
}

// ---------------------------------------------------------------------------
// RFC 3464 fields

// fieldGroups splits a status body into blank-line separated groups of
// unfolded "name: value" fields (names lower-case). Malformed lines are skipped.
func fieldGroups(b []byte) []map[string]string {
	var groups []map[string]string
	cur := map[string]string{}
	last := ""
	flush := func() {
		if len(cur) > 0 {
			groups = append(groups, cur)
		}
		cur, last = map[string]string{}, ""
	}
	for _, line := range strings.Split(string(normaliseNewlines(b)), "\n") {
		line = strings.TrimRight(line, "\r")
		switch {
		case strings.TrimSpace(line) == "":
			flush()
		case (line[0] == ' ' || line[0] == '\t') && last != "":
			cur[last] = clip(cur[last]+" "+strings.TrimSpace(line), 2000)
		default:
			name, value, ok := strings.Cut(line, ":")
			if !ok || strings.ContainsAny(name, " \t") || name == "" {
				last = ""
				continue
			}
			last = strings.ToLower(name)
			if _, seen := cur[last]; !seen { // first occurrence wins
				cur[last] = clip(strings.TrimSpace(value), 2000)
			}
		}
	}
	flush()
	return groups
}

func (r *Report) parseDeliveryStatus(b []byte) {
	groups := fieldGroups(b)
	if len(groups) == 0 {
		r.problem("empty delivery-status part")
		return
	}
	perMessage := groups[0]
	if _, isRecipient := perMessage["final-recipient"]; isRecipient {
		r.problem("delivery-status part without per-message fields")
		perMessage = map[string]string{}
	} else {
		groups = groups[1:]
	}
	r.ReportingMTA = typedValue(perMessage["reporting-mta"])
	r.EnvelopeID = xtextDecode(strings.TrimSpace(perMessage["original-envelope-id"]))
	r.PostfixQueueID = firstToken(perMessage["x-postfix-queue-id"])
	if d, err := mail.ParseDate(perMessage["arrival-date"]); err == nil {
		r.ArrivalDate = d.UTC()
	}
	for _, g := range groups {
		if len(r.Recipients) >= maxRecipients {
			r.problem("more than %d recipient blocks; the rest is ignored", maxRecipients)
			break
		}
		rc := Recipient{
			FinalRecipient:    fieldAddress(g["final-recipient"]),
			OriginalRecipient: fieldAddress(g["original-recipient"]),
			Action:            strings.ToLower(firstToken(g["action"])),
			Diagnostic:        typedValue(g["diagnostic-code"]),
			RemoteMTA:         typedValue(g["remote-mta"]),
		}
		if st := firstToken(g["status"]); validEnhanced(st) {
			rc.Status = st
		} else if g["status"] != "" {
			r.problem("malformed Status %q", clip(g["status"], 50))
		}
		if d, err := mail.ParseDate(g["last-attempt-date"]); err == nil {
			rc.LastAttempt = d.UTC()
		}
		if rc.FinalRecipient == "" && rc.OriginalRecipient == "" && rc.Action == "" && rc.Status == "" {
			continue // not a recipient block
		}
		if rc.FinalRecipient == "" {
			r.problem("recipient block without Final-Recipient")
		}
		r.Recipients = append(r.Recipients, rc)
	}
	if len(r.Recipients) == 0 {
		r.problem("delivery-status part without recipient blocks")
	}
}

// ---------------------------------------------------------------------------
// RFC 5965 feedback report

func (r *Report) parseFeedback(b []byte) {
	f := &Feedback{}
	for _, g := range fieldGroups(b) {
		set := func(dst *string, key string) {
			if *dst == "" {
				*dst = g[key]
			}
		}
		set(&f.Type, "feedback-type")
		set(&f.UserAgent, "user-agent")
		set(&f.Version, "version")
		set(&f.ReportingMTA, "reporting-mta")
		set(&f.SourceIP, "source-ip")
		if f.OriginalMailFrom == "" {
			f.OriginalMailFrom = fieldAddress(g["original-mail-from"])
		}
		if f.OriginalRcptTo == "" {
			f.OriginalRcptTo = fieldAddress(g["original-rcpt-to"])
		}
		if d, err := mail.ParseDate(g["arrival-date"]); err == nil && f.ArrivalDate.IsZero() {
			f.ArrivalDate = d.UTC()
		}
	}
	f.Type = strings.ToLower(firstToken(f.Type))
	f.ReportingMTA = typedValue(f.ReportingMTA)
	if f.Type == "" {
		r.problem("feedback report without Feedback-Type")
	}
	r.Feedback = f
}

// ---------------------------------------------------------------------------
// Returned headers

func (r *Report) parseReturned(b []byte) {
	tp := textproto.NewReader(bufio.NewReader(bytes.NewReader(normaliseNewlines(b))))
	h, err := tp.ReadMIMEHeader()
	if err != nil && len(h) == 0 {
		r.problem("unparseable returned headers: %v", err)
		r.scanText(b)
		return
	}
	if r.Returned == nil {
		r.Returned = &Returned{}
	}
	ret := r.Returned
	if ret.MessageID == "" {
		ret.MessageID = clip(strings.TrimSpace(h.Get("Message-Id")), 300)
	}
	if ret.SmarthostMessageID == "" {
		ret.SmarthostMessageID = clip(strings.TrimSpace(h.Get("X-Smarthost-Message-Id")), 300)
	}
	if ret.From == "" {
		ret.From = headerAddress(h.Get("From"))
	}
	if ret.To == "" {
		ret.To = headerAddress(h.Get("To"))
	}
}

// ---------------------------------------------------------------------------
// Text scanning (non-standard bounces)

func (r *Report) scanReader(body io.Reader) { r.scanText(readLimited(body, maxTextScan)) }

func (r *Report) scanText(b []byte) {
	if len(b) > maxTextScan {
		b = b[:maxTextScan]
	}
	for _, m := range idLineRe.FindAllSubmatch(b, 20) {
		if len(r.BodyIdentifiers) < 20 {
			r.BodyIdentifiers = append(r.BodyIdentifiers, string(m[1]))
		}
	}
}

// ---------------------------------------------------------------------------
// helpers

func first(h mail.Header, key string) string {
	if v := h[textproto.CanonicalMIMEHeaderKey(key)]; len(v) > 0 {
		return clip(strings.TrimSpace(v[0]), 400)
	}
	return ""
}

func normaliseNewlines(b []byte) []byte {
	if !bytes.Contains(b, []byte("\r\n")) {
		return b
	}
	return bytes.ReplaceAll(b, []byte("\r\n"), []byte("\n"))
}

func decodeTransfer(cte string, body io.Reader) io.Reader {
	switch strings.ToLower(strings.TrimSpace(cte)) {
	case "base64":
		return base64.NewDecoder(base64.StdEncoding, &stripSpace{r: body})
	case "quoted-printable":
		return quotedprintable.NewReader(body)
	default:
		return body
	}
}

// stripSpace removes whitespace from base64 input (line breaks).
type stripSpace struct{ r io.Reader }

func (s *stripSpace) Read(p []byte) (int, error) {
	for {
		n, err := s.r.Read(p)
		j := 0
		for _, c := range p[:n] {
			if c != '\n' && c != '\r' && c != ' ' && c != '\t' {
				p[j] = c
				j++
			}
		}
		if j > 0 || err != nil {
			return j, err
		}
	}
}

func readLimited(r io.Reader, n int64) []byte {
	b, _ := io.ReadAll(io.LimitReader(r, n))
	return b
}

// typedValue strips the "type;" prefix of a DSN field ("dns; mx.example",
// "smtp; 550 ...") and surrounding whitespace.
func typedValue(v string) string {
	v = strings.TrimSpace(v)
	if t, rest, ok := strings.Cut(v, ";"); ok && !strings.ContainsAny(t, " \t<@") {
		v = strings.TrimSpace(rest)
	}
	return clip(v, 1000)
}

// fieldAddress extracts the mailbox of an address-typed DSN field ("rfc822; <a@b>").
func fieldAddress(v string) string {
	v = typedValue(v)
	if m := angleRe.FindStringSubmatch(v); m != nil {
		v = m[1]
	}
	v = strings.TrimSpace(v)
	if !strings.Contains(v, "@") || strings.ContainsAny(v, " \t\r\n") {
		return ""
	}
	return clip(v, 320)
}

func headerAddress(v string) string {
	if v == "" {
		return ""
	}
	if a, err := mail.ParseAddress(v); err == nil {
		return clip(a.Address, 320)
	}
	if m := angleRe.FindStringSubmatch(v); m != nil {
		return clip(strings.TrimSpace(m[1]), 320)
	}
	return ""
}

func firstToken(v string) string {
	f := strings.Fields(v)
	if len(f) == 0 {
		return ""
	}
	return strings.Trim(f[0], ";,()")
}

// xtextDecode decodes RFC 3461 xtext ("+2B" -> "+").
func xtextDecode(s string) string {
	if !strings.Contains(s, "+") {
		return s
	}
	var b strings.Builder
	for i := 0; i < len(s); i++ {
		if s[i] == '+' && i+2 < len(s) {
			if c, ok := unhex(s[i+1], s[i+2]); ok {
				b.WriteByte(c)
				i += 2
				continue
			}
		}
		b.WriteByte(s[i])
	}
	return b.String()
}

func unhex(a, b byte) (byte, bool) {
	h := func(c byte) (byte, bool) {
		switch {
		case c >= '0' && c <= '9':
			return c - '0', true
		case c >= 'A' && c <= 'F':
			return c - 'A' + 10, true
		case c >= 'a' && c <= 'f':
			return c - 'a' + 10, true
		}
		return 0, false
	}
	x, ok1 := h(a)
	y, ok2 := h(b)
	return x<<4 | y, ok1 && ok2
}

var enhancedRe = regexp.MustCompile(`^[245]\.\d{1,3}\.\d{1,3}$`)

func validEnhanced(s string) bool { return enhancedRe.MatchString(s) }

func clip(s string, n int) string {
	if len(s) <= n {
		return s
	}
	return s[:n]
}
