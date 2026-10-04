package ids

import (
	"regexp"
	"strings"
	"testing"
)

var uuidV7 = regexp.MustCompile(`^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$`)

func TestUUIDv7FormatAndOrder(t *testing.T) {
	prev := ""
	for i := 0; i < 20000; i++ {
		u := UUIDv7()
		if !uuidV7.MatchString(u) {
			t.Fatalf("not a v7 UUID: %s", u)
		}
		if u <= prev {
			t.Fatalf("not strictly increasing: %s after %s", u, prev)
		}
		prev = u
	}
}

func TestVERPTokens(t *testing.T) {
	seen := map[string]bool{}
	re := regexp.MustCompile(`^[a-z2-7]{26}$`)
	for i := 0; i < 50000; i++ {
		tok := VERPToken()
		if !re.MatchString(tok) {
			t.Fatalf("bad token %q", tok)
		}
		if seen[tok] {
			t.Fatalf("duplicate token %q", tok)
		}
		seen[tok] = true
	}
}

func TestReturnPathAndReverseLookup(t *testing.T) {
	v := VERP{LocalPart: "bounce", Delimiter: "+", Domain: "bounce.example"}
	tok := VERPToken()
	rp := v.ReturnPath(tok)
	if rp != "bounce+"+tok+"@bounce.example" {
		t.Fatalf("return path %q", rp)
	}
	if strings.Contains(rp, "recipient") {
		t.Fatal("return path must carry no personal data")
	}
	for _, a := range []string{rp, strings.ToUpper(rp), "Bounce+" + strings.ToUpper(tok) + "@BOUNCE.example"} {
		got, ok := v.Token(a)
		if !ok || got != tok {
			t.Errorf("Token(%q) = %q, %v", a, got, ok)
		}
	}
	for _, a := range []string{"bounce@bounce.example", "bounce+short@bounce.example", "bounce+" + tok + "@other.example",
		"other+" + tok + "@bounce.example", "bounce+" + tok[:25] + "1@bounce.example"} {
		if _, ok := v.Token(a); ok {
			t.Errorf("Token(%q) accepted", a)
		}
	}
}

func TestTrackingToken(t *testing.T) {
	re := regexp.MustCompile(`^[A-Za-z0-9_-]{32}$`)
	seen := map[string]bool{}
	for i := 0; i < 20000; i++ {
		tok := TrackingToken() // 24 random bytes = 192 bits
		if !re.MatchString(tok) || seen[tok] {
			t.Fatalf("bad or duplicate tracking token %q", tok)
		}
		seen[tok] = true
	}
}

func TestMessageID(t *testing.T) {
	id := UUIDv7()
	h := MessageIDHeader(id, "bounce.example")
	if h != "<"+id+"@bounce.example>" {
		t.Fatalf("header %q", h)
	}
	if got, ok := ParseMessageIDHeader(h, "bounce.example"); !ok || got != id {
		t.Fatalf("parse %q -> %q %v", h, got, ok)
	}
	if got, ok := ParseMessageIDHeader(strings.ToUpper(h), "bounce.example"); !ok || got != id {
		t.Fatalf("case-insensitive parse failed: %q", got)
	}
	for _, bad := range []string{"<x@bounce.example>", "<" + id + "@other.example>", id + "@bounce.example"} {
		if _, ok := ParseMessageIDHeader(bad, "bounce.example"); ok {
			t.Errorf("accepted %q", bad)
		}
	}
}
