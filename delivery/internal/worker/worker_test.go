package worker

import (
	"testing"

	"smarthost.local/delivery/internal/config"
	"smarthost.local/delivery/internal/store"
)

// The daemon checks the sending domain again when it claims a job (the API checks it
// at submit): a disabled or unverified domain is refused, and in production so is a
// domain without active DKIM, so an unfinished domain never sends unsigned mail.
func TestDomainRefusal(t *testing.T) {
	cases := []struct {
		env, status, dkim string
		allowUnverified   bool
		want              string
	}{
		{"production", "verified", "active", false, ""},
		{"production", "verified", "pending_dns", false, "sending domain d.example has no active DKIM"},
		{"production", "verified", "not_configured", false, "sending domain d.example has no active DKIM"},
		{"production", "pending", "active", false, "sending domain d.example is not verified"},
		{"production", "disabled", "active", false, "sending domain d.example is disabled"},
		{"development", "verified", "pending_dns", false, ""},
		{"development", "pending", "not_configured", true, ""},
		{"test", "pending", "not_configured", false, "sending domain d.example is not verified"},
	}
	for _, c := range cases {
		w := &Worker{Cfg: &config.Config{Env: c.env, AllowUnverified: c.allowUnverified}}
		job := &store.Job{DomainName: "d.example", DomainStatus: c.status, DomainDKIM: c.dkim}
		if got := w.domainRefusal(job); got != c.want {
			t.Errorf("%s %s dkim=%s: got %q, want %q", c.env, c.status, c.dkim, got, c.want)
		}
	}
}
