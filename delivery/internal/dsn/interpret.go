package dsn

import (
	"fmt"
	"regexp"
	"strconv"
	"strings"

	"smarthost.local/delivery/internal/address"
	"smarthost.local/delivery/internal/ids"
	"smarthost.local/delivery/internal/smtpclass"
)

// Outcome is what a report means for one message (status-vocabulary
// dsn_classification / message_event_type).
type Outcome struct {
	// Event is hard_bounce, soft_bounce, deferred, connection_failure or
	// complaint; "" when the report records no transport event (Informational)
	// or cannot be reconciled with the message (Unreconciled).
	Event         string
	Informational bool   // e.g. Action delivered/relayed, a not-spam feedback report
	Unreconciled  bool   // correlated, but the recipient/status cannot be reconciled
	Reason        string // why there is no event
	FailureScope  string // deferred, connection_failure, soft_bounce, hard_bounce only
	Enhanced      string
	SMTPCode      int
	RemoteHost    string
	Diagnostic    string
	Action        string
	FeedbackType  string
	Recipient     *Recipient // the per-recipient block used, if any
}

// Classification is the dsn_classification of the outcome (for unmatched rows).
func (o Outcome) Classification() string {
	if o.Event != "" {
		return o.Event
	}
	return "malformed_or_unmatched_dsn"
}

var (
	diagEnhancedRe = regexp.MustCompile(`\b([245]\.\d{1,3}\.\d{1,3})\b`)
	diagCodeRe     = regexp.MustCompile(`^\s*([245]\d\d)\b`)
	remoteHostRe   = regexp.MustCompile(`^[A-Za-z0-9.\-\[\]:]+`)
)

// Interpret classifies the report for a message whose D-18-normalised recipient
// is recipient ("" when no message is known, as for an unmatched DSN). It is
// conservative: a temporary failure never becomes permanent, a report naming a
// different recipient is not attributed to the message, and anything unclear
// yields no event.
func Interpret(r *Report, recipient string) Outcome {
	if r.Feedback != nil {
		return interpretFeedback(r, recipient)
	}
	if len(r.Recipients) == 0 {
		return Outcome{Unreconciled: true, Reason: "not a standard delivery status notification (no per-recipient status)"}
	}
	block, reason := pickRecipient(r.Recipients, recipient)
	if block == nil {
		return Outcome{Unreconciled: true, Reason: reason}
	}
	o := Outcome{Recipient: block, Action: block.Action, Diagnostic: block.Diagnostic}
	o.Enhanced = block.Status
	if o.Enhanced == "" {
		if m := diagEnhancedRe.FindStringSubmatch(block.Diagnostic); m != nil {
			o.Enhanced = m[1]
		}
	}
	if m := diagCodeRe.FindStringSubmatch(block.Diagnostic); m != nil {
		o.SMTPCode, _ = strconv.Atoi(m[1])
	}
	if h := remoteHostRe.FindString(block.RemoteMTA); h != "" {
		o.RemoteHost = strings.Trim(h, "[]")
	}
	class := 0
	if c, _, _, ok := smtpclass.Parse(o.Enhanced); ok {
		class = c
	} else if o.SMTPCode != 0 {
		class = o.SMTPCode / 100
	}
	scope := smtpclass.Scope(o.Enhanced, block.Diagnostic)
	switch block.Action {
	case "failed":
		switch class {
		case 5:
			o.Event = "hard_bounce"
		case 4: // a final failure of a temporary nature (e.g. expiry)
			o.Event = "soft_bounce"
		default:
			return Outcome{Unreconciled: true, Reason: "Action failed without a failure status", Recipient: block, Action: block.Action}
		}
	case "delayed":
		o.Event = "deferred" // never permanent, whatever the code says
		if scope == smtpclass.Connection {
			o.Event = "connection_failure"
		}
	case "delivered", "relayed", "expanded":
		return Outcome{Informational: true, Reason: "Action " + block.Action + " is not a failure", Recipient: block, Action: block.Action}
	case "":
		switch class {
		case 5:
			o.Event = "hard_bounce"
		case 4:
			o.Event = "deferred"
		case 2:
			return Outcome{Informational: true, Reason: "success status without Action", Recipient: block}
		default:
			return Outcome{Unreconciled: true, Reason: "no Action and no status", Recipient: block}
		}
	default:
		return Outcome{Unreconciled: true, Reason: fmt.Sprintf("unknown Action %q", clip(block.Action, 40)), Recipient: block, Action: block.Action}
	}
	o.FailureScope = scope
	if o.SMTPCode != 0 && o.SMTPCode/100 != class && class != 0 {
		o.SMTPCode = 0 // inconsistent basic code: keep only the enhanced status
	}
	return o
}

// pickRecipient chooses the block that concerns the message's recipient. A single
// block is taken unless its Original-Recipient (the ORCPT Smarthost sent) names a
// different address; with several blocks one must name the recipient.
func pickRecipient(blocks []Recipient, recipient string) (*Recipient, string) {
	names := func(b *Recipient) (orig, final string) {
		orig, _ = address.Normalize(b.OriginalRecipient)
		final, _ = address.Normalize(b.FinalRecipient)
		return
	}
	if len(blocks) == 1 {
		b := &blocks[0]
		if recipient != "" {
			if orig, _ := names(b); orig != "" && orig != recipient {
				return nil, "the report's Original-Recipient is not the message's recipient"
			}
		}
		return b, ""
	}
	if recipient == "" { // unmatched: describe the first failure block
		for i := range blocks {
			if blocks[i].Action == "failed" {
				return &blocks[i], ""
			}
		}
		return &blocks[0], ""
	}
	for i := range blocks {
		orig, final := names(&blocks[i])
		if orig == recipient || (orig == "" && final == recipient) {
			return &blocks[i], ""
		}
	}
	return nil, fmt.Sprintf("%d recipient reports, none for the message's recipient", len(blocks))
}

func interpretFeedback(r *Report, recipient string) Outcome {
	f := r.Feedback
	o := Outcome{FeedbackType: f.Type}
	if f.Type == "" {
		o.Unreconciled, o.Reason = true, "feedback report without Feedback-Type"
		return o
	}
	if recipient != "" && f.OriginalRcptTo != "" {
		if n, ok := address.Normalize(f.OriginalRcptTo); !ok || n != recipient {
			o.Unreconciled, o.Reason = true, "the complaint's Original-Rcpt-To is not the message's recipient"
			return o
		}
	}
	if f.Type != "abuse" {
		o.Informational, o.Reason = true, "feedback type "+clip(f.Type, 40)+" is not a complaint"
		return o
	}
	o.Event = "complaint"
	o.Diagnostic = clip("ARF feedback report (abuse) from "+firstNonEmpty(f.ReportingMTA, f.UserAgent, "unknown reporter"), 500)
	return o
}

// Evidence is the correlation evidence of a report (postfix-integration §5),
// strongest first. Every identifier is syntactically checked; whether it names
// a real message is decided by the store.
type Evidence struct {
	VERPToken    string   // from the topmost Delivered-To / X-Original-To
	EnvelopeID   string   // Original-Envelope-Id when it is a Smarthost message id
	MessageIDs   []string // Smarthost message ids from X-Smarthost-Message-ID / Message-ID
	QueueID      string   // X-Postfix-Queue-ID
	Recipients   []string // D-18-normalised recipient addresses named by the report
	ReturnedFrom string   // From of the returned original headers
}

var queueIDRe = regexp.MustCompile(`^[0-9A-Za-z]{6,32}$`)

// Evidence extracts the correlation evidence; verp parses the bounce-domain
// recipient and bounceDomain recognises Smarthost Message-IDs.
func (r *Report) Evidence(verp ids.VERP, bounceDomain string) Evidence {
	var e Evidence
	for _, rcpt := range []string{r.DeliveredTo, r.OriginalTo} {
		if t, ok := verp.Token(strings.Trim(rcpt, "<>")); ok {
			e.VERPToken = t
			break
		}
	}
	if ids.IsUUID(r.EnvelopeID) {
		e.EnvelopeID = strings.ToLower(r.EnvelopeID)
	}
	seen := map[string]bool{}
	addID := func(v string) {
		v = strings.TrimSpace(v)
		if ids.IsUUID(v) { // X-Smarthost-Message-ID carries the bare id
			v = strings.ToLower(v)
		} else if id, ok := ids.ParseMessageIDHeader(v, bounceDomain); ok {
			v = id
		} else if !strings.HasPrefix(v, "<") {
			if id, ok := ids.ParseMessageIDHeader("<"+v+">", bounceDomain); ok {
				v = id
			} else {
				return
			}
		} else {
			return
		}
		if !seen[v] {
			seen[v] = true
			e.MessageIDs = append(e.MessageIDs, v)
		}
	}
	if r.Returned != nil {
		addID(r.Returned.SmarthostMessageID)
		addID(r.Returned.MessageID)
		e.ReturnedFrom = r.Returned.From
	}
	for _, v := range r.BodyIdentifiers {
		addID(v)
	}
	if queueIDRe.MatchString(r.PostfixQueueID) {
		e.QueueID = r.PostfixQueueID
	}
	add := func(a string) {
		if n, ok := address.Normalize(a); ok && !seen["rcpt:"+n] {
			seen["rcpt:"+n] = true
			e.Recipients = append(e.Recipients, n)
		}
	}
	for _, b := range r.Recipients {
		if b.OriginalRecipient != "" {
			add(b.OriginalRecipient)
		} else {
			add(b.FinalRecipient)
		}
	}
	if r.Feedback != nil {
		add(r.Feedback.OriginalRcptTo)
	}
	return e
}

func firstNonEmpty(v ...string) string {
	for _, s := range v {
		if s != "" {
			return s
		}
	}
	return ""
}

func sprintf(format string, args ...any) string { return fmt.Sprintf(format, args...) }
