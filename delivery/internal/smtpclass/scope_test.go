package smtpclass

import "testing"

func TestScope(t *testing.T) {
	cases := []struct{ enhanced, text, want string }{
		// Recipient: mailbox-specific permanent and temporary conditions.
		{"5.1.1", "550 5.1.1 <a@x.example>: Recipient address rejected: User unknown in virtual mailbox table", Recipient},
		{"5.1.1", "550 5.1.1 The email account that you tried to reach does not exist", Recipient},
		{"5.1.6", "551 5.1.6 Mailbox has moved", Recipient},
		{"5.2.1", "550 5.2.1 Mailbox disabled", Recipient},
		{"4.2.2", "452 4.2.2 Mailbox full", Recipient},
		{"5.2.2", "552 5.2.2 Over quota", Recipient},
		{"", "550 User unknown", Recipient},
		{"5.0.0", "550 5.0.0 No such user here", Recipient},
		{"5.5.0", "550 5.5.0 Requested action not taken: mailbox unavailable", Recipient},
		{"5.0.0", "550 5.0.0 Sender address rejected: user unknown", Unknown},
		// Not the recipient.
		{"5.7.1", "550 5.7.1 Message rejected due to poor reputation of sending IP", ProviderPolicy},
		{"5.7.1", "554 5.7.1 Recipient address rejected: Access denied", ProviderPolicy},
		{"5.1.1", "550 5.1.1 Blocked by policy: listed on Spamhaus", ProviderPolicy},
		{"4.7.0", "421 4.7.0 Try again later, rate limited", ProviderPolicy},
		{"5.7.26", "550 5.7.26 Unauthenticated email is not accepted (DMARC)", ProviderPolicy},
		{"5.1.8", "553 5.1.8 Sender address rejected: Domain not found", DNS},
		{"5.1.8", "553 5.1.8 Bad sender system address", ProviderPolicy},
		{"5.1.2", "550 5.1.2 Bad destination system address", Domain},
		{"5.1.10", "550 5.1.10 Null MX", Domain},
		{"4.4.1", "connect to mx.x.example[192.0.2.1]:25: Connection refused", Connection},
		{"4.4.2", "lost connection with mx[192.0.2.1] while receiving the initial server greeting", Connection},
		{"4.4.3", "Host or domain name not found. Name service error", DNS},
		{"5.4.4", "550 5.4.4 Unable to route", DNS},
		{"4.4.7", "delivery temporarily suspended", Unknown},
		{"4.4.7", "Delivery time expired", Unknown},
		{"4.3.0", "451 4.3.0 Temporary server error", Infrastructure},
		{"5.3.4", "552 5.3.4 Message too big for system", Infrastructure},
		{"5.5.0", "500 5.5.0 Syntax error", Infrastructure},
		{"5.6.0", "554 5.6.0 Message content rejected", Unknown},
		{"5.2.3", "552 5.2.3 Message length exceeds administrative limit", Unknown},
		{"5.1.4", "550 5.1.4 Ambiguous destination", Unknown},
		{"", "554 Transaction failed", Unknown},
		{"", "", Unknown},
		{"9.9.9", "nonsense", Unknown},
	}
	for _, c := range cases {
		if got := Scope(c.enhanced, c.text); got != c.want {
			t.Errorf("Scope(%q, %q) = %s, want %s", c.enhanced, c.text, got, c.want)
		}
	}
}
