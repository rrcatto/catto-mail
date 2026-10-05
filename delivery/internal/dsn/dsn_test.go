package dsn

import (
	"encoding/base64"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"smarthost.local/delivery/internal/ids"
)

const (
	bounce   = "bounce.smarthost.test"
	token    = "abcdefghijklmnopqrstuvwxyz"
	msgID    = "01990000-0000-7000-8000-000000000001"
	queueID  = "4hxKf66gyVz187c"
	rcpt     = "Jane.Doe@Example.ORG"
	rcptNorm = "Jane.Doe@example.org"
	other    = "someone@example.org"
	sender   = "news@sender.example"
)

var verp = ids.VERP{LocalPart: "bounce", Delimiter: "+", Domain: bounce}

func fixture(t *testing.T, name string, vars map[string]string) []byte {
	t.Helper()
	b, err := os.ReadFile(filepath.Join("testdata", name))
	if err != nil {
		t.Fatal(err)
	}
	pairs := []string{}
	for k, v := range vars {
		pairs = append(pairs, "{{"+k+"}}", v)
	}
	return []byte(strings.NewReplacer(pairs...).Replace(string(b)))
}

func defaults() map[string]string {
	rp := verp.ReturnPath(token)
	return map[string]string{"VERP": rp, "VERP_SENDER": rp, "ENVID": msgID, "MSGID": msgID, "BOUNCE": bounce,
		"QID": queueID, "RCPT": rcpt, "OTHER": other, "FROM": sender,
		"B64_STATUS": base64.StdEncoding.EncodeToString([]byte("Reporting-MTA: dns; mx.example.org\n\nFinal-Recipient: rfc822; " + rcpt +
			"\nAction: failed\nStatus: 5.1.1\nDiagnostic-Code: smtp; 550 5.1.1 unknown\n"))}
}

func parse(t *testing.T, name string, override map[string]string) *Report {
	t.Helper()
	v := defaults()
	for k, x := range override {
		v[k] = x
	}
	return Parse(fixture(t, name, v))
}

func TestPostfixMultipartReport(t *testing.T) {
	r := parse(t, "postfix-hard-5.1.1.eml", nil)
	if r.Kind != DeliveryStatus || len(r.Problems) != 0 {
		t.Fatalf("kind %s problems %v", r.Kind, r.Problems)
	}
	if r.ReportingMTA != "smarthost.example" || r.EnvelopeID != msgID || r.PostfixQueueID != queueID || r.ArrivalDate.IsZero() {
		t.Fatalf("per-message fields: %+v", r)
	}
	if len(r.Recipients) != 1 {
		t.Fatalf("recipients: %+v", r.Recipients)
	}
	b := r.Recipients[0]
	if b.FinalRecipient != rcpt || b.OriginalRecipient != rcpt || b.Action != "failed" || b.Status != "5.1.1" || b.RemoteMTA != "mx.example.org" ||
		!strings.Contains(b.Diagnostic, "User unknown in virtual mailbox table") || strings.HasPrefix(b.Diagnostic, "smtp;") {
		t.Fatalf("recipient block: %+v", b)
	}
	if r.Returned == nil || r.Returned.SmarthostMessageID != msgID || r.Returned.From != sender {
		t.Fatalf("returned headers: %+v", r.Returned)
	}
	e := r.Evidence(verp, bounce)
	if e.VERPToken != token || e.EnvelopeID != msgID || len(e.MessageIDs) != 1 || e.MessageIDs[0] != msgID || e.QueueID != queueID ||
		len(e.Recipients) != 1 || e.Recipients[0] != rcptNorm || e.ReturnedFrom != sender {
		t.Fatalf("evidence: %+v", e)
	}
	o := Interpret(r, rcptNorm)
	if o.Event != "hard_bounce" || o.FailureScope != "recipient" || o.Enhanced != "5.1.1" || o.SMTPCode != 550 || o.RemoteHost != "mx.example.org" {
		t.Fatalf("outcome: %+v", o)
	}
}

func TestClassification(t *testing.T) {
	cases := []struct {
		file, event, scope string
		informational      bool
		unreconciled       bool
	}{
		{"postfix-hard-5.1.1.eml", "hard_bounce", "recipient", false, false},
		{"remote-mailbox-full-4.2.2.eml", "soft_bounce", "recipient", false, false}, // final temporary failure: never hard
		{"delay-notice.eml", "connection_failure", "connection", false, false},      // a DELAY DSN never bounces
		{"mixed-recipients.eml", "deferred", "recipient", false, false},             // the block for our recipient, not the other
		{"missing-optional-fields.eml", "hard_bounce", "recipient", false, false},   // only Action and Status
		{"message-id-only.eml", "hard_bounce", "recipient", false, false},
		{"queue-id-only.eml", "hard_bounce", "recipient", false, false},
		{"provider-policy-5.7.1.eml", "hard_bounce", "provider_policy", false, false}, // permanent, but not the recipient
		{"domain-failure-5.1.2.eml", "hard_bounce", "domain", false, false},           // permanent, domain-level
		{"encoded-parts.eml", "hard_bounce", "recipient", false, false},               // base64 / quoted-printable parts
		{"global-delivery-status.eml", "hard_bounce", "recipient", false, false},      // RFC 6533
		{"arf-abuse.eml", "complaint", "", false, false},                              // ARF
		{"arf-missing-recipient.eml", "complaint", "", false, false},                  // correlated message, no Original-Rcpt-To
		{"arf-not-spam.eml", "", "", true, false},                                     // feedback, but not a complaint
		{"arf-malformed.eml", "", "", false, true},                                    // no Feedback-Type
		{"malformed.eml", "", "", false, true},                                        // no boundary: no status
		{"garbage.eml", "", "", false, true},                                          // not a message
		{"nonstandard-bounce.eml", "", "", false, true},                               // no machine-readable status
	}
	for _, c := range cases {
		r := parse(t, c.file, nil)
		o := Interpret(r, rcptNorm)
		if o.Event != c.event || o.FailureScope != c.scope || o.Informational != c.informational || o.Unreconciled != c.unreconciled {
			t.Errorf("%s: %+v (problems %v)", c.file, o, r.Problems)
		}
	}
}

func TestMixedRecipientsNeverAttributeAnotherRecipientsFailure(t *testing.T) {
	r := parse(t, "mixed-recipients.eml", nil)
	if len(r.Recipients) != 2 {
		t.Fatalf("%+v", r.Recipients)
	}
	// For the other address the failure block applies; for an address not in the
	// report nothing does (dsn_unmatched on the message, never a guess).
	if o := Interpret(r, other); o.Event != "hard_bounce" || o.FailureScope != "recipient" {
		t.Fatalf("other: %+v", o)
	}
	if o := Interpret(r, "third@example.org"); !o.Unreconciled || o.Event != "" {
		t.Fatalf("absent recipient: %+v", o)
	}
	// Without a message (unmatched), the classification describes the first failure.
	if o := Interpret(r, ""); o.Classification() != "hard_bounce" {
		t.Fatalf("unmatched classification: %+v", o)
	}
}

func TestSingleBlockNamingAnotherOriginalRecipient(t *testing.T) {
	r := parse(t, "postfix-hard-5.1.1.eml", nil)
	if o := Interpret(r, "different@example.org"); !o.Unreconciled {
		t.Fatalf("a report whose Original-Recipient differs must not be attributed: %+v", o)
	}
	r = parse(t, "arf-abuse.eml", nil)
	if o := Interpret(r, "different@example.org"); !o.Unreconciled || o.Event != "" {
		t.Fatalf("a complaint for another recipient must not be attributed: %+v", o)
	}
}

func TestCorrelationEvidence(t *testing.T) {
	cases := []struct {
		file                    string
		verp, envid, msg, queue bool
	}{
		{"postfix-hard-5.1.1.eml", true, true, true, true},
		{"remote-mailbox-full-4.2.2.eml", true, true, true, false},
		{"missing-optional-fields.eml", false, true, false, false}, // sent to postmaster@: no VERP
		{"message-id-only.eml", false, false, true, false},
		{"queue-id-only.eml", false, false, false, true},
		{"nonstandard-bounce.eml", false, false, true, false}, // Message-ID quoted in the body text
		{"arf-abuse.eml", true, false, true, false},
		{"arf-unknown-message.eml", false, false, false, false}, // a foreign Message-ID is not evidence
	}
	for _, c := range cases {
		e := parse(t, c.file, nil).Evidence(verp, bounce)
		if (e.VERPToken != "") != c.verp || (e.EnvelopeID != "") != c.envid || (len(e.MessageIDs) > 0) != c.msg || (e.QueueID != "") != c.queue {
			t.Errorf("%s: %+v", c.file, e)
		}
	}
}

func TestVERPOnlyFromTheTopmostEnvelopeHeader(t *testing.T) {
	// A forged lower Delivered-To naming a VERP address is not trusted.
	raw := []byte("Delivered-To: postmaster@" + bounce + "\nDelivered-To: " + verp.ReturnPath(token) + "\nSubject: x\n\nbody\n")
	if e := Parse(raw).Evidence(verp, bounce); e.VERPToken != "" {
		t.Fatalf("lower Delivered-To trusted: %+v", e)
	}
	// Receivers may change the local part's case; tokens are lower-case base32.
	raw = []byte("Delivered-To: BOUNCE+" + strings.ToUpper(token) + "@" + strings.ToUpper(bounce) + "\nSubject: x\n\nbody\n")
	if e := Parse(raw).Evidence(verp, bounce); e.VERPToken != token {
		t.Fatalf("case-changed VERP: %+v", e)
	}
	// Another domain's address is not a VERP address.
	raw = []byte("Delivered-To: bounce+" + token + "@other.example\nSubject: x\n\nbody\n")
	if e := Parse(raw).Evidence(verp, bounce); e.VERPToken != "" {
		t.Fatalf("foreign domain: %+v", e)
	}
}

func TestMalformedInputIsBoundedAndNeverPanics(t *testing.T) {
	inputs := [][]byte{
		nil, []byte(""), []byte("\n\n\n"), []byte("Content-Type: multipart/report; boundary=\"z\"\n\n--z\n"),
		[]byte("Content-Type: message/delivery-status\n\nAction failed\nStatus: x.y.z\n\n\n"),
		[]byte("Content-Type: multipart/mixed; boundary=a\n\n--a\nContent-Type: multipart/mixed; boundary=b\n\n--b\nContent-Type: multipart/mixed; boundary=c\n\n--c\nContent-Type: multipart/mixed; boundary=d\n\n--d\nContent-Type: multipart/mixed; boundary=e\n\n--e\nContent-Type: message/delivery-status\n\nFinal-Recipient: rfc822; a@b.example\nAction: failed\nStatus: 5.1.1\n"),
		[]byte("Content-Type: message/delivery-status\nContent-Transfer-Encoding: base64\n\n!!!not base64!!!\n"),
	}
	var many strings.Builder
	many.WriteString("Content-Type: multipart/mixed; boundary=p\n\n")
	for i := 0; i < 200; i++ {
		many.WriteString("--p\nContent-Type: text/plain\n\nx\n")
	}
	inputs = append(inputs, []byte(many.String()))
	huge := append([]byte("Subject: big\n\n"), []byte(strings.Repeat("A", MaxMessageBytes+10))...)
	inputs = append(inputs, huge)
	for i, in := range inputs {
		r := Parse(in)
		if r == nil {
			t.Fatalf("input %d: nil report", i)
		}
		_ = Interpret(r, rcptNorm)
		_ = r.Evidence(verp, bounce)
	}
	r := Parse(inputs[5])
	if len(r.Recipients) != 0 || len(r.Problems) == 0 {
		t.Fatalf("nesting beyond the limit must be ignored with a problem: %+v", r)
	}
	if r := Parse(huge); len(r.Problems) == 0 {
		t.Fatal("truncation must be reported")
	}
	if r := Parse(inputs[len(inputs)-2]); r.parts <= maxParts || len(r.Problems) == 0 {
		t.Fatalf("part limit: %d %v", r.parts, r.Problems)
	}
}

func TestDiagnosticOnlyStatusAndBasicCodes(t *testing.T) {
	raw := "Content-Type: multipart/report; report-type=delivery-status; boundary=s\n\n--s\nContent-Type: message/delivery-status\n\n" +
		"Reporting-MTA: dns; mx.example.org\n\nFinal-Recipient: rfc822; " + rcpt + "\nAction: failed\nDiagnostic-Code: smtp; 550 5.2.1 Mailbox disabled\n\n--s--\n"
	o := Interpret(Parse([]byte(raw)), rcptNorm)
	if o.Event != "hard_bounce" || o.Enhanced != "5.2.1" || o.FailureScope != "recipient" {
		t.Fatalf("enhanced code from the diagnostic: %+v", o)
	}
	raw = strings.Replace(raw, "550 5.2.1 Mailbox disabled", "450 Try later", 1)
	if o := Interpret(Parse([]byte(raw)), rcptNorm); o.Event != "soft_bounce" || o.FailureScope != "unknown" {
		t.Fatalf("basic 4xx: %+v", o)
	}
	raw = strings.Replace(raw, "Action: failed\n", "", 1)
	if o := Interpret(Parse([]byte(raw)), rcptNorm); o.Event != "deferred" {
		t.Fatalf("no Action and 4xx is deferred, never permanent: %+v", o)
	}
	raw = strings.Replace(raw, "450 Try later", "250 2.0.0 OK", 1)
	if o := Interpret(Parse([]byte(raw)), rcptNorm); !o.Informational {
		t.Fatalf("success without Action: %+v", o)
	}
	relayed := strings.Replace(strings.Replace(raw, "250 2.0.0 OK", "250 relayed", 1), "Final-Recipient", "Action: relayed\nFinal-Recipient", 1)
	if o := Interpret(Parse([]byte(relayed)), rcptNorm); !o.Informational || o.Event != "" {
		t.Fatalf("relayed: %+v", o)
	}
}

func TestCRLFAndXtextEnvelopeID(t *testing.T) {
	raw := strings.ReplaceAll(string(fixture(t, "postfix-hard-5.1.1.eml", defaults())), "\n", "\r\n")
	if r := Parse([]byte(raw)); len(r.Recipients) != 1 || r.EnvelopeID != msgID {
		t.Fatalf("CRLF: %+v", r)
	}
	if got := xtextDecode("abc+2Bdef+3D"); got != "abc+def=" {
		t.Fatal(got)
	}
}
