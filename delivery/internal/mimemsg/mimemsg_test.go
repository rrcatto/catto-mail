package mimemsg

import (
	"bytes"
	"errors"
	"io"
	"mime"
	"mime/multipart"
	"mime/quotedprintable"
	"net/mail"
	"strings"
	"testing"
	"time"
)

func base() Message {
	return Message{FromName: "Example News", FromEmail: "news@send.example", To: "John@rcpt.example",
		Subject: "Hello", Text: "Plain body\nline 2", MessageID: "01890000-0000-7000-8000-000000000001",
		BounceDomain: "bounce.example", Date: time.Date(2026, 10, 4, 12, 0, 0, 0, time.UTC)}
}

func parse(t *testing.T, raw []byte) *mail.Message {
	t.Helper()
	for i, l := range bytes.Split(raw, []byte("\r\n")) {
		if len(l) > 998 {
			t.Fatalf("line %d longer than 998", i)
		}
	}
	if bytes.Contains(bytes.ReplaceAll(raw, []byte("\r\n"), nil), []byte("\n")) {
		t.Fatal("bare LF in message")
	}
	m, err := mail.ReadMessage(bytes.NewReader(raw))
	if err != nil {
		t.Fatal(err)
	}
	return m
}

func body(t *testing.T, r io.Reader) string {
	b, err := io.ReadAll(quotedprintable.NewReader(r))
	if err != nil {
		t.Fatal(err)
	}
	return strings.ReplaceAll(string(b), "\r\n", "\n")
}

func TestPlainTextOnly(t *testing.T) {
	raw, err := Build(base())
	if err != nil {
		t.Fatal(err)
	}
	m := parse(t, raw)
	h := m.Header
	if h.Get("Message-ID") != "<01890000-0000-7000-8000-000000000001@bounce.example>" ||
		h.Get("X-Smarthost-Message-ID") != "01890000-0000-7000-8000-000000000001" {
		t.Fatalf("ids: %v", h)
	}
	if !strings.HasPrefix(h.Get("Content-Type"), "text/plain; charset=utf-8") || h.Get("MIME-Version") != "1.0" {
		t.Fatalf("content type %q", h.Get("Content-Type"))
	}
	if d, err := h.Date(); err != nil || !d.Equal(base().Date) {
		t.Fatalf("date %v %v", d, err)
	}
	if got := body(t, m.Body); got != "Plain body\nline 2\n" {
		t.Fatalf("body %q", got)
	}
	for _, k := range []string{"List-Id", "List-Unsubscribe", "List-Unsubscribe-Post", "Reply-To"} {
		if h.Get(k) != "" {
			t.Errorf("transactional message has %s", k)
		}
	}
	from, _ := mail.ParseAddress(h.Get("From"))
	if from.Name != "Example News" || from.Address != "news@send.example" {
		t.Errorf("from %v", from)
	}
	if h.Get("To") != "<John@rcpt.example>" {
		t.Errorf("to %q (local part case must be kept)", h.Get("To"))
	}
}

func TestHTMLOnlyAndMultipart(t *testing.T) {
	m := base()
	m.Text, m.HTML = "", "<p>Hi</p>"
	raw, _ := Build(m)
	msg := parse(t, raw)
	if !strings.HasPrefix(msg.Header.Get("Content-Type"), "text/html") || body(t, msg.Body) != "<p>Hi</p>\n" {
		t.Fatal("html-only")
	}
	m = base()
	m.HTML = "<p>Hi ünïcode =</p>"
	raw, _ = Build(m)
	msg = parse(t, raw)
	mt, params, _ := mime.ParseMediaType(msg.Header.Get("Content-Type"))
	if mt != "multipart/alternative" {
		t.Fatalf("type %s", mt)
	}
	mr := multipart.NewReader(msg.Body, params["boundary"])
	var types, bodies []string
	for {
		p, err := mr.NextRawPart()
		if err != nil {
			break
		}
		types = append(types, p.Header.Get("Content-Type"))
		bodies = append(bodies, body(t, p))
	}
	if len(types) != 2 || !strings.HasPrefix(types[0], "text/plain") || !strings.HasPrefix(types[1], "text/html") {
		t.Fatalf("parts %v", types)
	}
	if bodies[1] != "<p>Hi ünïcode =</p>\n" {
		t.Fatalf("html part %q", bodies[1])
	}
}

func TestUnicodeHeaders(t *testing.T) {
	m := base()
	m.FromName = "Zoë Ström"
	m.Subject = "Grüße aus Köln — " + strings.Repeat("très longue ligne ", 20)
	raw, err := Build(m)
	if err != nil {
		t.Fatal(err)
	}
	for _, l := range strings.Split(string(raw), "\r\n") {
		if strings.HasPrefix(l, "Subject:") || strings.HasPrefix(l, " =?") {
			if len(l) > 78 {
				t.Errorf("unfolded header line of %d chars", len(l))
			}
		}
	}
	msg := parse(t, raw)
	dec := new(mime.WordDecoder)
	subj, err := dec.DecodeHeader(msg.Header.Get("Subject"))
	if err != nil || subj != m.Subject {
		t.Fatalf("subject round trip %q (%v)", subj, err)
	}
	from, err := mail.ParseAddress(msg.Header.Get("From"))
	if err != nil || from.Name != "Zoë Ström" {
		t.Fatalf("from %v %v", from, err)
	}
}

func TestHeaderInjectionRejected(t *testing.T) {
	for _, mut := range []func(*Message){
		func(m *Message) { m.Subject = "Hi\r\nBcc: victim@example.com" },
		func(m *Message) { m.Subject = "Hi\nX-Evil: 1" },
		func(m *Message) { m.FromName = "A\rB" },
		func(m *Message) { m.To = "a@b.example\r\nBcc: x@y" },
		func(m *Message) { m.ListID = "list\x00id"; m.UnsubscribeURL = "https://u.example/x" },
		func(m *Message) { m.ReplyToEmail = "r@x.example\nBcc: y@z" },
	} {
		m := base()
		mut(&m)
		if _, err := Build(m); !errors.Is(err, ErrHeaderInjection) {
			t.Errorf("not rejected: %v", err)
		}
	}
}

func TestSubscriptionHeadersRFC8058(t *testing.T) {
	m := base()
	m.ListID = "Weekly News <weekly.news.example>"
	m.UnsubscribeURL = "https://app.example/u/opaque-token"
	m.ReplyToName, m.ReplyToEmail = "Desk", "desk@send.example"
	raw, err := Build(m)
	if err != nil {
		t.Fatal(err)
	}
	h := parse(t, raw).Header
	if h.Get("List-Unsubscribe") != "<https://app.example/u/opaque-token>" ||
		h.Get("List-Unsubscribe-Post") != "List-Unsubscribe=One-Click" ||
		h.Get("List-Id") != "Weekly News <weekly.news.example>" {
		t.Fatalf("subscription headers: %v", h)
	}
	if rt, _ := mail.ParseAddress(h.Get("Reply-To")); rt == nil || rt.Address != "desk@send.example" {
		t.Fatal("reply-to")
	}
	m.UnsubscribeURL = ""
	if _, err := Build(m); err == nil {
		t.Fatal("List-Id without an unsubscribe URL accepted")
	}
}

func TestDotLinesAndNoCallerHeaders(t *testing.T) {
	m := base()
	m.Text = ".\n..leading dots\nFrom: not a header"
	raw, _ := Build(m)
	msg := parse(t, raw)
	if got := body(t, msg.Body); got != ".\n..leading dots\nFrom: not a header\n" {
		t.Fatalf("body %q", got)
	}
	if len(msg.Header["From"]) != 1 {
		t.Fatal("body text became a header")
	}
}
