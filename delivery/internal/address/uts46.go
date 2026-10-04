package address

import (
	"errors"
	"strings"

	"golang.org/x/net/idna"
)

// profile is UTS #46 non-transitional processing as PHP/ICU applies it with
// IDNA_NONTRANSITIONAL_TO_ASCII: mapping, CheckHyphens, CheckBidi,
// CheckJoiners and DNS length verification, but no STD3 ASCII rules (so "_"
// and other non-LDH ASCII characters are kept).
var profile = idna.New(
	idna.MapForLookup(),
	idna.Transitional(false),
	idna.StrictDomainName(false),
	idna.BidiRule(),
	idna.CheckJoiners(true),
	idna.CheckHyphens(true),
	idna.ValidateLabels(true),
	idna.VerifyDNSLength(true),
)

var errConversion = errors.New("UTS #46 conversion failed")

func uts46ToASCII(domain string) (string, error) {
	out, err := profile.ToASCII(domain)
	if err != nil {
		return "", errConversion
	}
	// UTS #46 §4.1 (and ICU): an "xn--" label must decode to a non-empty label
	// that is not all ASCII. x/net/idna accepts e.g. "xn--ss-" (decodes to
	// "ss"); PHP/ICU and the Python implementation reject it (shared vector).
	for _, label := range strings.Split(out, ".") {
		if !strings.HasPrefix(label, "xn--") && !strings.HasPrefix(label, "XN--") {
			continue
		}
		u, err := idna.Punycode.ToUnicode(label)
		if err != nil || u == "" || isASCII(u) {
			return "", errConversion
		}
	}
	return out, nil
}
