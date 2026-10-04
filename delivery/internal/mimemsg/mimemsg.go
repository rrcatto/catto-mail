// Package mimemsg builds the outgoing RFC 5322/2045 message from the client's
// already rendered content under the header contract (spec
// sending.header_contract, D-25). There is no template rendering and no merge:
// Subject, HTML and text are used exactly as submitted. Callers cannot add
// headers; every header comes from the contract.
package mimemsg

import (
	"bytes"
	"encoding/base64"
	"errors"
	"fmt"
	"mime/quotedprintable"
	"net/mail"
	"strings"
	"time"
	"unicode/utf8"

	"smarthost.local/delivery/internal/ids"
)

// Message is one recipient's message.
type Message struct {
	FromName, FromEmail       string
	ReplyToName, ReplyToEmail string // optional (job-level Reply-To)
	To                        string // the recipient address
	Subject                   string // rendered by the client
	Text, HTML                string // rendered by the client (at least one)
	MessageID                 string // Smarthost message id (UUID)
	BounceDomain              string // Message-ID domain
	Date                      time.Time
	// Subscription mail only (RFC 2369/2919/8058).
	ListID         string
	UnsubscribeURL string
}

// ErrHeaderInjection reports a CR, LF or NUL (or another C0 control) in a
// value that becomes a header. The API already rejects them; this is the
// independent second line of defence.
var ErrHeaderInjection = errors.New("control character in a header value")

func checkHeader(name, v string) error {
	for _, r := range v {
		if r < 0x20 && r != '\t' || r == 0x7f {
			return fmt.Errorf("%w (%s)", ErrHeaderInjection, name)
		}
	}
	if !utf8.ValidString(v) {
		return fmt.Errorf("invalid UTF-8 in %s", name)
	}
	return nil
}

// NeedsSMTPUTF8 reports whether an address cannot be expressed in ASCII
// (internationalised local part), so the submission must use SMTPUTF8.
func NeedsSMTPUTF8(addrs ...string) bool {
	for _, a := range addrs {
		for i := 0; i < len(a); i++ {
			if a[i] >= 0x80 {
				return true
			}
		}
	}
	return false
}

// Build returns the CRLF-terminated message. The boundary is random per message.
func Build(m Message) ([]byte, error) {
	for name, v := range map[string]string{"From": m.FromName + m.FromEmail, "Reply-To": m.ReplyToName + m.ReplyToEmail,
		"To": m.To, "Subject": m.Subject, "List-Id": m.ListID, "List-Unsubscribe": m.UnsubscribeURL} {
		if err := checkHeader(name, v); err != nil {
			return nil, err
		}
	}
	if m.Text == "" && m.HTML == "" {
		return nil, errors.New("message has neither a text nor an HTML part")
	}
	if m.FromEmail == "" || m.To == "" || m.MessageID == "" || m.BounceDomain == "" {
		return nil, errors.New("incomplete message")
	}
	if strings.ContainsAny(m.UnsubscribeURL, "<> ") {
		return nil, errors.New("unsubscribe URL contains characters that cannot appear in List-Unsubscribe")
	}
	var h bytes.Buffer
	header := func(name, value string) { h.WriteString(name + ": " + value + "\r\n") }
	header("Date", m.Date.UTC().Format(time.RFC1123Z))
	header("From", address(m.FromName, m.FromEmail))
	if m.ReplyToEmail != "" {
		header("Reply-To", address(m.ReplyToName, m.ReplyToEmail))
	}
	header("To", address("", m.To))
	header("Subject", encodeText(m.Subject, len("Subject: ")))
	header("Message-ID", ids.MessageIDHeader(m.MessageID, m.BounceDomain))
	header("X-Smarthost-Message-ID", m.MessageID)
	if m.ListID != "" || m.UnsubscribeURL != "" {
		if m.ListID == "" || m.UnsubscribeURL == "" {
			return nil, errors.New("subscription mail needs both List-Id and an unsubscribe URL")
		}
		header("List-Id", encodeText(m.ListID, len("List-Id: ")))
		header("List-Unsubscribe", "<"+m.UnsubscribeURL+">")
		header("List-Unsubscribe-Post", "List-Unsubscribe=One-Click")
	}
	header("MIME-Version", "1.0")

	var body bytes.Buffer
	switch {
	case m.Text != "" && m.HTML != "":
		boundary := "=_smarthost_" + ids.TrackingToken()
		header("Content-Type", `multipart/alternative; boundary="`+boundary+`"`)
		body.WriteString("This is a multi-part message in MIME format.\r\n")
		for _, p := range []struct{ ct, content string }{{"text/plain", m.Text}, {"text/html", m.HTML}} {
			body.WriteString("\r\n--" + boundary + "\r\n")
			body.WriteString("Content-Type: " + p.ct + "; charset=utf-8\r\n")
			body.WriteString("Content-Transfer-Encoding: quoted-printable\r\n\r\n")
			body.Write(qp(p.content))
		}
		body.WriteString("\r\n--" + boundary + "--\r\n")
	case m.HTML != "":
		header("Content-Type", "text/html; charset=utf-8")
		header("Content-Transfer-Encoding", "quoted-printable")
		body.Write(qp(m.HTML))
	default:
		header("Content-Type", "text/plain; charset=utf-8")
		header("Content-Transfer-Encoding", "quoted-printable")
		body.Write(qp(m.Text))
	}
	h.WriteString("\r\n")
	h.Write(body.Bytes())
	return h.Bytes(), nil
}

// qp quoted-printable encodes content with CRLF line breaks; a final line break
// is ensured so the part ends cleanly before the next boundary.
func qp(s string) []byte {
	s = strings.ReplaceAll(strings.ReplaceAll(s, "\r\n", "\n"), "\r", "\n")
	var b bytes.Buffer
	w := quotedprintable.NewWriter(&b)
	_, _ = w.Write([]byte(strings.ReplaceAll(s, "\n", "\r\n")))
	_ = w.Close()
	out := b.Bytes()
	if !bytes.HasSuffix(out, []byte("\r\n")) {
		out = append(out, '\r', '\n')
	}
	return out
}

// address formats a mailbox; the display name is RFC 2047-encoded when needed.
func address(name, email string) string {
	if name == "" {
		return "<" + email + ">"
	}
	return (&mail.Address{Name: name, Address: email}).String()
}

// encodeText returns a header value: printable ASCII that fits on a line is
// kept; anything else becomes RFC 2047 encoded-words (UTF-8, Q or B) folded
// with CRLF SP so no line exceeds 78 characters.
func encodeText(v string, prefix int) string {
	ascii := true
	for i := 0; i < len(v); i++ {
		if v[i] < 0x20 || v[i] > 0x7e {
			ascii = false
			break
		}
	}
	if ascii && prefix+len(v) <= 78 && !strings.Contains(v, "=?") {
		return v
	}
	// Encode in runs of at most 40 bytes (never splitting a character) so each
	// encoded word stays <= 75 chars. Every run is encoded, including pure
	// ASCII ones: whitespace between an encoded word and plain text would be
	// significant, between two encoded words it is not (RFC 2047 §6.2).
	var words []string
	var cur strings.Builder
	flush := func() {
		words = append(words, "=?utf-8?b?"+base64.StdEncoding.EncodeToString([]byte(cur.String()))+"?=")
		cur.Reset()
	}
	for _, r := range v {
		if cur.Len()+utf8.RuneLen(r) > 40 {
			flush()
		}
		cur.WriteRune(r)
	}
	if cur.Len() > 0 {
		flush()
	}
	return strings.Join(words, "\r\n ")
}
