// Package tracking applies delivery-time instrumentation to the client's
// rendered HTML when the send job enables it (spec
// sending.tracking_instrumentation, message_tracking.engagement_tracking):
//
//   - open tracking: one 1x1 pixel <img src="<base>/t/o/<token>.gif"> in the
//     HTML part only (before </body>, or appended);
//   - click tracking: eligible <a href> values become <base>/t/c/<token>/<n>,
//     n = 1, 2, ... in document order; the original target is returned for the
//     server-side message_links mapping and never appears in the URL.
//
// Only absolute http/https links are eligible. Never rewritten: plain-text
// URLs (the text part is never touched), mailto:, fragment-only, any other
// scheme, relative URLs and the recipient's unsubscribe URL. Everything other
// than the rewritten <a> start tags is copied byte for byte.
package tracking

import (
	"bytes"
	"html"
	"io"
	"net/url"
	"strconv"
	"strings"

	xhtml "golang.org/x/net/html"
)

// Options says what to instrument for one message.
type Options struct {
	BaseURL        string // SMARTHOST_PUBLIC_BASE_URL (no trailing slash)
	Token          string // the message's tracking token
	Opens, Clicks  bool
	UnsubscribeURL string // never rewritten
}

// Link is one rewritten link (message_links row).
type Link struct {
	Index  int
	Target string
}

// OpenURL is the pixel URL of a token.
func OpenURL(base, token string) string { return base + "/t/o/" + token + ".gif" }

// ClickURL is the redirect URL of link n.
func ClickURL(base, token string, n int) string {
	return base + "/t/c/" + token + "/" + strconv.Itoa(n)
}

// Eligible reports whether href may be click-tracked.
func Eligible(href, unsubscribe string) bool {
	h := strings.TrimSpace(href)
	if h == "" || h == strings.TrimSpace(unsubscribe) {
		return false
	}
	u, err := url.Parse(h)
	if err != nil || u.Host == "" {
		return false
	}
	s := strings.ToLower(u.Scheme)
	return s == "http" || s == "https"
}

// Instrument returns the instrumented HTML and the rewritten links.
func Instrument(doc string, o Options) (string, []Link) {
	if doc == "" || (!o.Opens && !o.Clicks) {
		return doc, nil
	}
	var out bytes.Buffer
	var links []Link
	z := xhtml.NewTokenizer(strings.NewReader(doc))
	for {
		tt := z.Next()
		if tt == xhtml.ErrorToken {
			if z.Err() != io.EOF {
				out.Write(z.Raw())
			}
			break
		}
		raw := z.Raw()
		if o.Clicks && (tt == xhtml.StartTagToken || tt == xhtml.SelfClosingTagToken) {
			tok := z.Token()
			if tok.Data == "a" {
				if rewritten, link, ok := rewriteAnchor(tok, tt, o, len(links)+1); ok {
					out.WriteString(rewritten)
					links = append(links, link)
					continue
				}
			}
		}
		out.Write(raw)
	}
	result := out.String()
	if o.Opens {
		pixel := `<img src="` + html.EscapeString(OpenURL(o.BaseURL, o.Token)) +
			`" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0">`
		if i := lastIndexFold(result, "</body"); i >= 0 {
			result = result[:i] + pixel + result[i:]
		} else {
			result += pixel
		}
	}
	return result, links
}

func rewriteAnchor(tok xhtml.Token, tt xhtml.TokenType, o Options, n int) (string, Link, bool) {
	idx := -1
	for i, a := range tok.Attr {
		if a.Namespace == "" && a.Key == "href" {
			idx = i
			break
		}
	}
	if idx < 0 || !Eligible(tok.Attr[idx].Val, o.UnsubscribeURL) {
		return "", Link{}, false
	}
	target := strings.TrimSpace(tok.Attr[idx].Val)
	var b strings.Builder
	b.WriteString("<a")
	for i, a := range tok.Attr {
		v := a.Val
		if i == idx {
			v = ClickURL(o.BaseURL, o.Token, n)
		}
		b.WriteString(" " + a.Key + `="` + html.EscapeString(v) + `"`)
	}
	if tt == xhtml.SelfClosingTagToken {
		b.WriteString("/")
	}
	b.WriteString(">")
	return b.String(), Link{Index: n, Target: target}, true
}

// lastIndexFold is an ASCII case-insensitive LastIndex that keeps byte offsets
// (strings.ToLower may change the length of non-ASCII text).
func lastIndexFold(s, sub string) int {
	lower := func(x string) string {
		b := []byte(x)
		for i, c := range b {
			if c >= 'A' && c <= 'Z' {
				b[i] = c + 32
			}
		}
		return string(b)
	}
	return strings.LastIndex(lower(s), lower(sub))
}
