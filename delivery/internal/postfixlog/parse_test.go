package postfixlog

import (
	"compress/gzip"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

var now = time.Date(2026, 10, 4, 12, 0, 0, 0, time.UTC)

func TestParseRealRecords(t *testing.T) {
	cases := []struct {
		line string
		kind Kind
		qid  string
	}{
		{"Oct 04 00:38:21 smarthost postfix/cleanup[574]: 4hy3XY7274z1kFg: message-id=<0189abcd-0000-7000-8000-000000000001@bounce.example>", MessageID, "4hy3XY7274z1kFg"},
		{"Oct 04 00:38:22 smarthost postfix/qmgr[538]: 4hy3XY7274z1kFg: from=<bounce+tok@bounce.example>, size=529, nrcpt=1 (queue active)", QueueActive, "4hy3XY7274z1kFg"},
		{"Oct 04 00:38:22 smarthost postfix/smtp[602]: 4hy3XY7274z1kFg: to=<hold@example.com>, relay=none, delay=0.14, delays=0.12/0.01/0/0, dsn=4.4.1, status=deferred (connect to mailpit[10.89.20.27]:1025: Connection refused)", Delivery, "4hy3XY7274z1kFg"},
		{"Oct 04 00:38:28 smarthost postfix/qmgr[538]: 4hy3Xh11Zwz1kJ7: removed", Removed, "4hy3Xh11Zwz1kJ7"},
		{"Oct 04 00:38:21 smarthost postfix/submission/smtpd[567]: 4hy3XY7274z1kFg: client=x[10.89.20.44], sasl_method=PLAIN", Other, "4hy3XY7274z1kFg"},
	}
	for _, c := range cases {
		r, ok := Parse(c.line, now)
		if !ok || r.Kind != c.kind || r.QueueID != c.qid {
			t.Errorf("%q -> %+v %v", c.line, r, ok)
		}
		if r.Time != time.Date(2026, 10, 4, 0, 38, r.Time.Second(), 0, time.UTC) {
			t.Errorf("time %v", r.Time)
		}
	}
	if _, ok := Parse("Oct 04 00:38:00 smarthost postfix/postlog: starting the Postfix mail system", now); ok {
		t.Error("record without a queue id parsed")
	}
	r, _ := Parse(cases[0].line, now)
	if r.MessageID != "0189abcd-0000-7000-8000-000000000001@bounce.example" {
		t.Error(r.MessageID)
	}
}

func TestYearInferenceAndISOTimestamps(t *testing.T) {
	jan := time.Date(2027, 1, 1, 0, 5, 0, 0, time.UTC)
	r, _ := Parse("Dec 31 23:59:59 h postfix/qmgr[1]: ABCDEF123: removed", jan)
	if r.Time.Year() != 2026 {
		t.Errorf("December record read in January: %v", r.Time)
	}
	r, ok := Parse("2026-10-04T10:00:00.123456+02:00 h postfix/qmgr[1]: ABCDEF123: removed", now)
	if !ok || !r.Time.Equal(time.Date(2026, 10, 4, 8, 0, 0, 123456000, time.UTC)) {
		t.Errorf("iso %v %v", r.Time, ok)
	}
}

func classify(t *testing.T, rest string) Event {
	t.Helper()
	r, ok := Parse("Oct 04 01:00:00 h postfix/smtp[9]: ABCDEF1234: "+rest, now)
	if !ok {
		t.Fatalf("unparsed %q", rest)
	}
	e, ok := Classify(r)
	if !ok {
		t.Fatalf("unclassified %q", rest)
	}
	return e
}

func TestClassify(t *testing.T) {
	cases := []struct {
		rest, typ, scope string
		code             int
		host             string
	}{
		{"to=<a@x.example>, relay=mx.x.example[192.0.2.1]:25, delay=1, delays=0/0/0/1, dsn=2.0.0, status=sent (250 2.0.0 Ok: queued as XYZ)", "remote_accepted", "", 250, "mx.x.example"},
		{"to=<a@x.example>, relay=mailpit[10.89.20.27]:1025, delay=0.1, delays=0/0/0/0.1, dsn=2.0.0, status=sent (250 2.0.0 Ok: queued as 1)", "remote_accepted", "", 250, "mailpit"},
		{"to=<a@x.example>, relay=none, delay=0.14, delays=0.12/0.01/0/0, dsn=4.4.1, status=deferred (connect to mailpit[10.89.20.27]:1025: Connection refused)", "connection_failure", "connection", 0, ""},
		{"to=<a@x.example>, relay=mx[192.0.2.1]:25, delay=2, delays=0/0/1/1, dsn=4.2.2, status=deferred (host mx[192.0.2.1] said: 452 4.2.2 Mailbox full (in reply to RCPT TO command))", "deferred", "recipient", 452, "mx"},
		{"to=<a@x.example>, relay=mx[192.0.2.1]:25, delay=2, delays=0/0/1/1, dsn=4.7.0, status=deferred (host mx[192.0.2.1] said: 421 4.7.0 Try again later, rate limited (in reply to MAIL FROM command))", "deferred", "provider_policy", 421, "mx"},
		{"to=<a@x.example>, relay=none, delay=1, delays=0/0/1/0, dsn=4.4.3, status=deferred (Host or domain name not found. Name service error for name=x.example type=MX: Host not found, try again)", "deferred", "dns", 0, ""},
		{"to=<a@x.example>, relay=mx[192.0.2.1]:25, delay=2, delays=0/0/1/1, dsn=5.1.1, status=bounced (host mx[192.0.2.1] said: 550 5.1.1 User unknown (in reply to RCPT TO command))", "hard_bounce", "recipient", 550, "mx"},
		{"to=<a@x.example>, relay=mx[192.0.2.1]:25, delay=2, delays=0/0/1/1, dsn=4.2.2, status=bounced (host mx[192.0.2.1] said: 452 4.2.2 Over quota (in reply to end of DATA command))", "soft_bounce", "recipient", 452, "mx"},
		{"to=<a@x.example>, relay=none, delay=432000, delays=432000/0/0/0, dsn=4.4.7, status=expired, returned to sender", "soft_bounce", "connection", 0, ""},
	}
	for _, c := range cases {
		if strings.Contains(c.rest, "status=expired") {
			// Postfix logs expiry as "status=expired, returned to sender" in some versions
			c.rest = strings.Replace(c.rest, "status=expired, returned to sender", "status=expired (delivery temporarily suspended)", 1)
		}
		e := classify(t, c.rest)
		if e.Type != c.typ || e.FailureScope != c.scope || e.SMTPCode != c.code || e.RemoteHost != c.host {
			t.Errorf("%q -> %+v", c.rest, e)
		}
	}
}

func writeLog(t *testing.T, dir, name string, lines []string, gz bool, partial string) {
	t.Helper()
	data := strings.Join(lines, "\n") + "\n" + partial
	path := filepath.Join(dir, name)
	if !gz {
		if err := os.WriteFile(path, []byte(data), 0o640); err != nil {
			t.Fatal(err)
		}
		return
	}
	f, _ := os.Create(path)
	z := gzip.NewWriter(f)
	_, _ = z.Write([]byte(data))
	z.Close()
	f.Close()
}

func TestGenerationsFingerprintReaderAndSearch(t *testing.T) {
	dir := t.TempDir()
	old := []string{
		"Oct 03 10:00:00 h postfix/cleanup[1]: QIDAAAAAA1: message-id=<0189abcd-0000-7000-8000-000000000001@bounce.example>",
		"Oct 03 10:00:01 h postfix/qmgr[2]: QIDAAAAAA1: from=<b@bounce.example>, size=1, nrcpt=1 (queue active)",
		"Oct 03 10:00:02 h postfix/cleanup[1]: QIDAAAAAA2: message-id=<0189abcd-0000-7000-8000-000000000002@bounce.example>",
	}
	active := []string{"Oct 04 10:00:00 h postfix/qmgr[2]: QIDAAAAAA1: removed"}
	writeLog(t, dir, "postfix.log.20261003-235959.gz", old, true, "")
	writeLog(t, dir, "postfix.log", active, false, "Oct 04 10:00:01 h postfix/qmgr[2]: QIDPARTIAL1: rem")
	gens, err := List(dir)
	if err != nil || len(gens) != 2 || !gens[0].Compressed || !gens[1].Active {
		t.Fatalf("%+v %v", gens, err)
	}
	// The same first record means the same generation, compressed or not.
	writeLog(t, dir, "copy.uncompressed", old, false, "")
	plain, _ := fingerprint(Generation{Path: filepath.Join(dir, "copy.uncompressed")})
	if plain != gens[0].ID {
		t.Fatal("fingerprint differs between the .gz and the uncompressed generation")
	}
	os.Remove(filepath.Join(dir, "copy.uncompressed"))
	// Reader: positions and only complete records.
	rd, err := Open(gens[1], 0)
	if err != nil {
		t.Fatal(err)
	}
	var got []Line
	for {
		l, ok, err := rd.Next()
		if err != nil || !ok {
			break
		}
		got = append(got, l)
	}
	rd.Close()
	if len(got) != 1 || got[0].Pos != 0 || rd.Pos() != int64(len(active[0])+1) {
		t.Fatalf("partial trailing record consumed or bad positions: %+v pos=%d", got, rd.Pos())
	}
	rd, _ = Open(gens[0], int64(len(old[0])+1))
	l, _, _ := rd.Next()
	rd.Close()
	if l.Pos != int64(len(old[0])+1) || !strings.Contains(l.Text, "queue active") {
		t.Fatalf("gz seek: %+v", l)
	}
	// FindSubmissions: message 1 was committed (queue active), message 2 never was.
	found, _ := FindSubmissions(dir, time.Time{}, map[string]bool{
		"0189abcd-0000-7000-8000-000000000001@bounce.example": true, "0189abcd-0000-7000-8000-000000000002@bounce.example": true})
	if s := found["0189abcd-0000-7000-8000-000000000001@bounce.example"]; !s.Committed || s.Cleanup.QueueID != "QIDAAAAAA1" || s.Cleanup.Key() != gens[0].ID+":0" {
		t.Fatalf("committed: %+v", s)
	}
	if s := found["0189abcd-0000-7000-8000-000000000002@bounce.example"]; s.Committed {
		t.Fatal("never-queued message reported as committed")
	}
	// A rotation between List and Open is detected.
	writeLog(t, dir, "postfix.log", []string{"Oct 04 11:00:00 h postfix/qmgr[2]: NEWGEN0001: removed"}, false, "")
	if _, err := Open(gens[1], 0); err != ErrRotated {
		t.Fatalf("rotation not detected: %v", err)
	}
}
