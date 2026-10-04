package tracking

import (
	"strings"
	"testing"
)

const base = "https://smarthost.example"

func TestOpenPixel(t *testing.T) {
	out, _ := Instrument("<html><body><p>x</p></BODY></html>", Options{BaseURL: base, Token: "TOK", Opens: true})
	if !strings.Contains(out, `<img src="https://smarthost.example/t/o/TOK.gif" width="1" height="1"`) {
		t.Fatalf("no pixel: %s", out)
	}
	if strings.Index(out, "/t/o/TOK.gif") > strings.Index(out, "</BODY>") {
		t.Fatal("pixel not before </body>")
	}
	out, _ = Instrument("<p>fragment, no body</p>", Options{BaseURL: base, Token: "TOK", Opens: true})
	if !strings.HasSuffix(out, `border:0">`) {
		t.Fatalf("pixel not appended: %s", out)
	}
	if out, _ := Instrument("", Options{BaseURL: base, Token: "TOK", Opens: true}); out != "" {
		t.Fatal("text-only message got a pixel")
	}
	// non-ASCII before </body> must not shift the insertion point
	out, _ = Instrument("<body>İİİ ẞ</body>", Options{BaseURL: base, Token: "TOK", Opens: true})
	if !strings.HasPrefix(out, "<body>İİİ ẞ<img") || !strings.HasSuffix(out, "</body>") {
		t.Fatalf("offset error: %s", out)
	}
}

func TestClickRewritingAndExclusions(t *testing.T) {
	doc := `<html><body>
<a href="https://shop.example/a?x=1&amp;y=2" class="c">A</a>
<a href="http://shop.example/b">B</a>
<a href="mailto:x@y.example">mail</a>
<a href="#top">frag</a>
<a href="/relative">rel</a>
<a href="javascript:alert(1)">js</a>
<a href="ftp://files.example/f">ftp</a>
<a href="https://app.example/unsub/token">unsubscribe</a>
<a name="anchor">no href</a>
<A HREF=" HTTPS://Shop.Example/C ">C</A>
Plain text https://shop.example/not-a-link stays.
</body></html>`
	out, links := Instrument(doc, Options{BaseURL: base, Token: "TOK", Clicks: true, UnsubscribeURL: "https://app.example/unsub/token"})
	if len(links) != 3 {
		t.Fatalf("links %v", links)
	}
	want := []string{"https://shop.example/a?x=1&y=2", "http://shop.example/b", "HTTPS://Shop.Example/C"}
	for i, l := range links {
		if l.Index != i+1 || l.Target != want[i] {
			t.Errorf("link %d = %+v", i, l)
		}
		if !strings.Contains(out, ClickURL(base, "TOK", i+1)) {
			t.Errorf("rewritten link %d missing", i+1)
		}
	}
	for _, keep := range []string{`href="mailto:x@y.example"`, `href="#top"`, `href="/relative"`, `href="javascript:alert(1)"`,
		`href="ftp://files.example/f"`, `href="https://app.example/unsub/token"`, "Plain text https://shop.example/not-a-link stays.", `class="c"`} {
		if !strings.Contains(out, keep) {
			t.Errorf("lost %q", keep)
		}
	}
	for _, target := range []string{"shop.example/a", "shop.example/b", "Shop.Example/C"} {
		if strings.Contains(out, target) {
			t.Errorf("original target %q leaked into the message", target)
		}
	}
	if strings.Contains(out, "/t/o/") {
		t.Error("pixel added without open tracking")
	}
}

func TestDisabledLeavesHTMLUntouched(t *testing.T) {
	doc := `<a href="https://x.example">x</a>`
	if out, links := Instrument(doc, Options{BaseURL: base, Token: "TOK"}); out != doc || links != nil {
		t.Fatal("instrumented although disabled")
	}
}

func TestEligible(t *testing.T) {
	for href, ok := range map[string]bool{"https://a.example": true, "HTTP://a.example/x": true, "mailto:a@b": false,
		"#x": false, "//a.example": false, "a.example": false, "tel:+1": false, "": false} {
		if Eligible(href, "") != ok {
			t.Errorf("Eligible(%q) != %v", href, ok)
		}
	}
}
