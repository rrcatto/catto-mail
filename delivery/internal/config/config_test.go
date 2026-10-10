package config

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

func valid() map[string]string {
	return map[string]string{
		"SMARTHOST_ENV": "development", "SMARTHOST_PUBLIC_BASE_URL": "https://smarthost.localhost/",
		"SMARTHOST_LIVE_DELIVERY_ENABLED": "false", "SMARTHOST_TIMEZONE": "Africa/Johannesburg", "SMARTHOST_ALLOW_UNVERIFIED_SENDING_DOMAINS": "false",
		"SMARTHOST_BOUNCE_DOMAIN": "Bounce.Example", "SMARTHOST_VERP_LOCAL_PART": "bounce", "SMARTHOST_VERP_DELIMITER": "+",
		"SMARTHOST_SUBMISSION_USERNAME": "u", "SMARTHOST_SUBMISSION_PASSWORD": "p",
		"DELIVERY_POSTFIX_SUBMISSION_HOST": "postfix", "DELIVERY_POSTFIX_SUBMISSION_PORT": "587",
		"SMARTHOST_POSTFIX_OBSERVABILITY_DIR": "/obs", "SMARTHOST_DSN_SPOOL_DIR": "/spool",
		"SMARTHOST_DB_HOST": "postgres", "SMARTHOST_DB_PORT": "5432", "SMARTHOST_DB_NAME": "smarthost", "SMARTHOST_DB_SSLMODE": "disable",
		"DELIVERY_DB_USER": "smarthost_delivery", "DELIVERY_DB_PASSWORD": "x'y", "DELIVERY_WORKER_ID": "w1",
		"DELIVERY_POLL_INTERVAL_SECONDS": "5", "DELIVERY_LEASE_SECONDS": "300", "DELIVERY_GLOBAL_CONCURRENCY": "10",
		"DELIVERY_PER_DOMAIN_CONCURRENCY": "2", "DELIVERY_PER_DOMAIN_RATE_PER_MINUTE": "60", "DELIVERY_DEFERRAL_BACKOFF_SECONDS": "900",
		"DELIVERY_DSN_NOTIFY": "FAILURE,DELAY", "DELIVERY_DSN_RET": "HDRS", "DELIVERY_FILE_POLL_INTERVAL_SECONDS": "2",
		"DELIVERY_RECONCILE_INTERVAL_SECONDS": "300", "DELIVERY_RECONCILE_GRACE_SECONDS": "3600", "DELIVERY_RECONCILE_MIN_SNAPSHOTS": "2",
		"POSTFIX_QUEUE_SNAPSHOT_INTERVAL_SECONDS": "60", "SMARTHOST_LOG_LEVEL": "info", "SMARTHOST_LOG_FORMAT": "json",
		"DELIVERY_SOFT_BOUNCE_SUPPRESSION_THRESHOLD": "3", "DELIVERY_SOFT_BOUNCE_SUPPRESSION_WINDOW_DAYS": "30", "DELIVERY_DSN_RETENTION_DAYS": "7",
		"DELIVERY_GLOBAL_RATE_PER_MINUTE": "0", "DELIVERY_THROTTLED_CLIENT_RATE_PER_MINUTE": "10",
	}
}

func TestHoldAndRates(t *testing.T) {
	m := valid()
	c, _ := Load(func(k string) string { return m[k] })
	if c.HoldSendWork() || c.GlobalRatePerMin != 0 || c.ThrottledClientRate != 10 || c.PauseFlagPath() != "/obs/control/outbound-paused" {
		t.Fatalf("development: %+v", c)
	}
	m["SMARTHOST_ENV"] = "production"
	m["DELIVERY_GLOBAL_RATE_PER_MINUTE"] = "30"
	c, _ = Load(func(k string) string { return m[k] })
	if !c.HoldSendWork() || c.GlobalRatePerMin != 30 {
		t.Fatal("production without live delivery holds send work")
	}
	m["SMARTHOST_LIVE_DELIVERY_ENABLED"] = "true"
	c, _ = Load(func(k string) string { return m[k] })
	if c.HoldSendWork() {
		t.Fatal("live production does not hold")
	}
}

func TestValidConfig(t *testing.T) {
	c, err := Load(func(k string) string { return valid()[k] })
	if err != nil {
		t.Fatal(err)
	}
	if c.PublicBaseURL != "https://smarthost.localhost" || c.BounceDomain != "bounce.example" || c.SubmissionAddr() != "postfix:587" {
		t.Fatalf("%+v", c)
	}
	if c.SoftBounceThreshold != 3 || c.SoftBounceWindow.Hours() != 720 || c.DSNRetention.Hours() != 168 {
		t.Fatalf("phase 5 settings: %+v", c)
	}
	if !strings.Contains(c.DSN(), `password='x\'y'`) {
		t.Fatalf("dsn quoting: %s", c.DSN())
	}
	if c.TimeZone.String() != "Africa/Johannesburg" || !strings.Contains(c.DSN(), `timezone='Africa/Johannesburg'`) {
		t.Fatalf("time zone: %v %s", c.TimeZone, c.DSN())
	}
	if _, off := time.Date(2026, 10, 10, 8, 0, 0, 0, time.UTC).In(c.TimeZone).Zone(); off != 2*3600 {
		t.Fatalf("SAST is UTC+2, got offset %d", off)
	}
}

func TestFailsClosed(t *testing.T) {
	for name, mut := range map[string]func(map[string]string){
		"missing variable":  func(m map[string]string) { delete(m, "DELIVERY_LEASE_SECONDS") },
		"missing time zone": func(m map[string]string) { delete(m, "SMARTHOST_TIMEZONE") },
		"unknown time zone": func(m map[string]string) { m["SMARTHOST_TIMEZONE"] = "Mars/Olympus" },
		"unverified in prod": func(m map[string]string) {
			m["SMARTHOST_ENV"] = "production"
			m["SMARTHOST_ALLOW_UNVERIFIED_SENDING_DOMAINS"] = "true"
		},
		"live outside prod":    func(m map[string]string) { m["SMARTHOST_LIVE_DELIVERY_ENABLED"] = "true" },
		"http base url":        func(m map[string]string) { m["SMARTHOST_PUBLIC_BASE_URL"] = "http://x" },
		"one snapshot":         func(m map[string]string) { m["DELIVERY_RECONCILE_MIN_SNAPSHOTS"] = "1" },
		"zero threshold":       func(m map[string]string) { m["DELIVERY_SOFT_BOUNCE_SUPPRESSION_THRESHOLD"] = "0" },
		"missing retention":    func(m map[string]string) { delete(m, "DELIVERY_DSN_RETENTION_DAYS") },
		"bad integer":          func(m map[string]string) { m["DELIVERY_GLOBAL_CONCURRENCY"] = "ten" },
		"negative global rate": func(m map[string]string) { m["DELIVERY_GLOBAL_RATE_PER_MINUTE"] = "-1" },
		"zero throttled rate":  func(m map[string]string) { m["DELIVERY_THROTTLED_CLIENT_RATE_PER_MINUTE"] = "0" },
		"both value and _FILE": func(m map[string]string) { m["DELIVERY_DB_PASSWORD_FILE"] = "/x" },
	} {
		m := valid()
		mut(m)
		if _, err := Load(func(k string) string { return m[k] }); err == nil {
			t.Errorf("%s: accepted", name)
		}
	}
}

func TestSecretFile(t *testing.T) {
	f := filepath.Join(t.TempDir(), "pw")
	_ = os.WriteFile(f, []byte("from-file\n"), 0o600)
	m := valid()
	delete(m, "DELIVERY_DB_PASSWORD")
	m["DELIVERY_DB_PASSWORD_FILE"] = f
	c, err := Load(func(k string) string { return m[k] })
	if err != nil || c.DBPassword != "from-file" {
		t.Fatalf("%v %v", c, err)
	}
}
