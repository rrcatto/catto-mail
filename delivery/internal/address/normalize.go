// Package address implements the single cross-language address-normalisation
// rule D-32 (docs/architecture/conventions.md "Email addresses"), shared with
// PHP (App\Sending\AddressNormalizer) and Python (smarthost_validator.normalize)
// and proven by docs/contracts/address-normalization-vectors.json:
//
//  1. trim surrounding ASCII whitespace only (SP, HT, LF, VT, FF, CR);
//  2. split at the final "@"; both parts must be non-empty;
//  3. keep the local part byte-for-byte (never case-folded);
//  4. an all-ASCII domain is lower-cased (ASCII only, no other processing);
//  5. a domain with non-ASCII characters is converted to A-labels with UTS #46
//     non-transitional processing (CheckHyphens, CheckBidi, CheckJoiners, no
//     STD3 rules, DNS length limits), then lower-cased;
//  6. a failed conversion means there is no normalised address.
//
// Normalisation is not validation, and nothing here rewrites the submitted
// address: callers keep the original and store this form separately.
package address

import (
	"strings"
)

const asciiWhitespace = " \t\n\v\f\r"

// Normalize returns the D-32 form of address and true, or "" and false when the
// address has no normalised form.
func Normalize(address string) (string, bool) {
	trimmed := strings.Trim(address, asciiWhitespace)
	at := strings.LastIndexByte(trimmed, '@')
	if at <= 0 || at == len(trimmed)-1 {
		return "", false
	}
	domain, ok := NormalizeDomain(trimmed[at+1:])
	if !ok {
		return "", false
	}
	return trimmed[:at] + "@" + domain, true
}

// NormalizeDomain applies rules 4-6 to a domain.
func NormalizeDomain(domain string) (string, bool) {
	if domain == "" {
		return "", false
	}
	if isASCII(domain) {
		return asciiLower(domain), true
	}
	a, err := uts46ToASCII(domain)
	if err != nil {
		return "", false
	}
	return asciiLower(a), true
}

// Domain returns the domain part of a normalised address.
func Domain(normalized string) string {
	return normalized[strings.LastIndexByte(normalized, '@')+1:]
}

func isASCII(s string) bool {
	for i := 0; i < len(s); i++ {
		if s[i] >= 0x80 {
			return false
		}
	}
	return true
}

// asciiLower lower-cases A-Z only (PHP strtolower / Python str.lower on ASCII).
func asciiLower(s string) string {
	b := []byte(s)
	for i, c := range b {
		if c >= 'A' && c <= 'Z' {
			b[i] = c + 32
		}
	}
	return string(b)
}
