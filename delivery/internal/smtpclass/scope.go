// Package smtpclass is the single failure-scope classifier (D-18, D-30) for
// every source of transport evidence: Postfix log records and inbound DSNs use
// the same rules, so the global suppression policy cannot differ by source.
//
// Only "recipient" makes a failure count against the address: a recipient soft
// bounce counts towards repeated_soft_bounce and a recipient hard bounce creates
// a global hard_bounce suppression. The classifier is therefore conservative:
// any policy, reputation, DNS, connection or infrastructure evidence in the text
// wins over the status code, and an unclear code is "unknown", never "recipient".
package smtpclass

import (
	"regexp"
	"strconv"
	"strings"
)

// Failure scopes (status-vocabulary failure_scope).
const (
	Recipient      = "recipient"
	Domain         = "domain"
	ProviderPolicy = "provider_policy"
	Connection     = "connection"
	DNS            = "dns"
	Infrastructure = "infrastructure"
	Unknown        = "unknown"
)

var (
	dnsRe = regexp.MustCompile(`(?i)host or domain name not found|name service error|domain not found|no mx host|nxdomain|dns (lookup|resolution) (failed|error)|unable to look up`)
	// TCP/TLS-level failures to reach the remote system.
	connRe = regexp.MustCompile(`(?i)connect to |connection refused|connection timed out|connection reset|no route to host|network is unreachable|lost connection with .* while (connecting|receiving the initial server greeting|performing the EHLO handshake)|conversation with .* timed out|tls handshake|cannot start tls|tls is required|starttls`)
	// Provider-wide limits, sender/IP reputation, authentication and content
	// policy: never evidence about the mailbox itself.
	policyRe = regexp.MustCompile(`(?i)rate limit|too many (connections|messages|recipients|errors)|try again later|temporarily deferred|reputation|spamhaus|spamcop|barracuda|blocked|block ?list|black ?list|listed (on|at|in)|\bpolicy\b|access denied|not allowed|refused by|\bspf\b|\bdkim\b|\bdmarc\b|unauthenticated|authentication|throttl|spam|junk|abuse|poor sending|bulk mail`)
	// Sender-side addressing problems (x.1.7, x.1.8 or wording): about us, not the recipient.
	senderRe = regexp.MustCompile(`(?i)sender (address|domain)|mail from|envelope from|return.?path`)
	// Mailbox-specific wording, used only when no usable enhanced code exists.
	recipientRe = regexp.MustCompile(`(?i)user unknown|unknown user|no such (user|mailbox|recipient|account)|mailbox (is )?(unavailable|not found|does not exist|disabled|inactive|full)|(recipient|mailbox) does not exist|invalid (recipient|mailbox)|over ?quota|quota exceeded|account (is |has been )?(disabled|suspended|inactive)|address (does not exist|unknown)|unrouteable address|recipient (address )?rejected: (user unknown|unknown)`)
	enhancedRe  = regexp.MustCompile(`^([245])\.(\d{1,3})\.(\d{1,3})$`)
)

// Scope classifies a deferral or bounce from its RFC 3463 enhanced status code
// (may be empty) and diagnostic text.
func Scope(enhanced, text string) string {
	switch {
	case dnsRe.MatchString(text):
		return DNS
	case connRe.MatchString(text):
		return Connection
	case policyRe.MatchString(text):
		return ProviderPolicy
	}
	_, subject, detail, ok := Parse(enhanced)
	// No code, or a generic one (x.0.0 "other", x.5.0 "other protocol status",
	// which some providers use for unknown mailboxes): only unambiguous mailbox
	// wording makes it a recipient failure.
	if !ok || (subject == 0 && detail == 0) || (subject == 5 && detail == 0) {
		if recipientRe.MatchString(text) && !senderRe.MatchString(text) {
			return Recipient
		}
		if ok && subject == 5 {
			return Infrastructure
		}
		return Unknown
	}
	switch subject {
	case 1: // addressing status
		switch detail {
		case 0, 1, 3, 6: // other address status, bad mailbox, bad mailbox syntax, mailbox moved
			if senderRe.MatchString(text) {
				return ProviderPolicy
			}
			return Recipient
		case 2, 10: // bad destination system address, null MX
			return Domain
		case 7, 8: // bad sender mailbox syntax / system address
			return ProviderPolicy
		default: // x.1.4 ambiguous, x.1.5 valid destination (a success code), others
			return Unknown
		}
	case 2: // mailbox status
		switch detail {
		case 0, 1, 2: // other, mailbox disabled, mailbox full
			return Recipient
		default: // x.2.3 message too long, x.2.4 list expansion: about the message or a list
			return Unknown
		}
	case 3: // mail system status
		return Infrastructure
	case 4: // network and routing status
		switch detail {
		case 3, 4: // directory server failure, unable to route
			return DNS
		case 7: // delivery time expired: says nothing about the cause
			return Unknown
		default:
			return Connection
		}
	case 5: // mail delivery protocol status
		return Infrastructure
	case 7: // security or policy status
		return ProviderPolicy
	default: // x.6.x message content, unknown subjects
		return Unknown
	}
}

// Parse splits an enhanced status code "C.S.D".
func Parse(enhanced string) (class, subject, detail int, ok bool) {
	m := enhancedRe.FindStringSubmatch(strings.TrimSpace(enhanced))
	if m == nil {
		return 0, 0, 0, false
	}
	class, _ = strconv.Atoi(m[1])
	subject, _ = strconv.Atoi(m[2])
	detail, _ = strconv.Atoi(m[3])
	return class, subject, detail, true
}

// Valid reports whether s is a well-formed enhanced status code.
func Valid(s string) bool { return enhancedRe.MatchString(s) }
