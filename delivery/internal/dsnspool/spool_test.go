package dsnspool

import (
	"testing"
	"time"
)

func TestKeyIsTheMaildirUniqueName(t *testing.T) {
	for in, want := range map[string]string{
		"1790000000.V803I1234M567890.postfix":                   "1790000000.V803I1234M567890.postfix",
		"1790000000.V803I1234M567890.postfix:2,S":               "1790000000.V803I1234M567890.postfix",
		"/spool/processing/1790000000.V803I1234M567890.postfix": "1790000000.V803I1234M567890.postfix",
		"1790000000.V803I1234M567890.postfix#w1-abc":            "1790000000.V803I1234M567890.postfix",
	} {
		if got := Key(in); got != want {
			t.Errorf("Key(%q) = %q", in, got)
		}
	}
}

func TestSafeNameKeepsReclaimNamesInTheDirectory(t *testing.T) {
	if got := safeName("smarthost/b9554940"); got != "smarthost-b9554940" {
		t.Fatal(got)
	}
	if Key("1790000000.V1I2M3.postfix#"+safeName("w/1:2")+"-x") != "1790000000.V1I2M3.postfix" {
		t.Fatal("key of a reclaimed name")
	}
}

func TestReceivedAt(t *testing.T) {
	mtime := time.Date(2026, 10, 4, 12, 0, 0, 0, time.UTC)
	if got := receivedAt("1790000000.V1I2M3.postfix", mtime); got.Unix() != 1790000000 {
		t.Fatalf("Maildir epoch: %v", got)
	}
	for _, k := range []string{"garbage", "12.V1.x", "99999999999.V1.x"} {
		if got := receivedAt(k, mtime); !got.Equal(mtime) {
			t.Errorf("%s: %v (want the file time)", k, got)
		}
	}
}
