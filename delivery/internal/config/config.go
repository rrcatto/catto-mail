// Package config reads the delivery daemon's configuration from the
// environment contract (docs/contracts/environment.md, consumer "delivery").
// It fails closed: a missing required variable or an unsafe combination is a
// startup error. Any secret X may be given as X_FILE (contract rule 2).
package config

import (
	"errors"
	"fmt"
	"net"
	"net/url"
	"os"
	"strconv"
	"strings"
	"time"
)

// Config is everything the daemon reads from its environment.
type Config struct {
	Env             string // development | test | production
	PublicBaseURL   string // tracking URLs: <base>/t/o/<token>.gif, <base>/t/c/<token>/<i>
	LogLevel        string
	LogFormat       string
	LiveDelivery    bool
	AllowUnverified bool

	BounceDomain  string
	VERPLocalPart string
	VERPDelimiter string

	SubmissionUser     string
	SubmissionPassword string
	SubmissionHost     string
	SubmissionPort     int

	ObservabilityDir string
	DSNSpoolDir      string

	DBHost, DBPort, DBName, DBSSLMode, DBUser, DBPassword string

	WorkerID          string
	PollInterval      time.Duration
	Lease             time.Duration
	GlobalConcurrency int
	DomainConcurrency int
	DomainRatePerMin  int
	DeferralBackoff   time.Duration
	// Phase 8 (specification 2.9): an installation-wide submission ceiling for
	// warm-up (0 = none) and the per-client rate of throttled clients.
	GlobalRatePerMin    int
	ThrottledClientRate int
	DSNNotify           string
	DSNRet              string
	FilePollInterval    time.Duration
	ReconcileInterval   time.Duration
	ReconcileGrace      time.Duration
	ReconcileMinSnaps   int
	SnapshotInterval    time.Duration

	// Phase 5 (D-18, D-30): global suppression policy and DSN spool retention.
	SoftBounceThreshold int
	SoftBounceWindow    time.Duration
	DSNRetention        time.Duration
}

type reader struct {
	get  func(string) string
	errs []string
}

func (r *reader) raw(name string) (string, bool) {
	v, fv := r.get(name), r.get(name+"_FILE")
	if v != "" && fv != "" {
		r.errs = append(r.errs, fmt.Sprintf("both %s and %s_FILE are set", name, name))
		return "", false
	}
	if fv != "" {
		b, err := os.ReadFile(fv)
		if err != nil {
			r.errs = append(r.errs, fmt.Sprintf("%s_FILE: %v", name, err))
			return "", false
		}
		return strings.TrimRight(string(b), "\r\n"), true
	}
	return v, v != ""
}

func (r *reader) required(name string) string {
	v, ok := r.raw(name)
	if !ok {
		r.errs = append(r.errs, "missing "+name)
	}
	return v
}

func (r *reader) optional(name, def string) string {
	if v, ok := r.raw(name); ok {
		return v
	}
	return def
}

func (r *reader) integer(name string, min int) int {
	v := r.required(name)
	n, err := strconv.Atoi(v)
	if v != "" && (err != nil || n < min) {
		r.errs = append(r.errs, fmt.Sprintf("%s must be an integer >= %d (got %q)", name, min, v))
	}
	return n
}

func (r *reader) seconds(name string, min int) time.Duration {
	return time.Duration(r.integer(name, min)) * time.Second
}

func (r *reader) boolean(name string) bool {
	switch v := r.required(name); v {
	case "true":
		return true
	case "false", "":
		return false
	default:
		r.errs = append(r.errs, fmt.Sprintf("%s must be true or false (got %q)", name, v))
		return false
	}
}

// FromEnv reads the process environment.
func FromEnv() (*Config, error) { return Load(os.Getenv) }

// Load reads configuration through get (os.Getenv in production; a map in tests).
func Load(get func(string) string) (*Config, error) {
	r := &reader{get: get}
	c := &Config{
		Env:                 r.required("SMARTHOST_ENV"),
		PublicBaseURL:       strings.TrimRight(r.required("SMARTHOST_PUBLIC_BASE_URL"), "/"),
		LogLevel:            r.optional("SMARTHOST_LOG_LEVEL", "info"),
		LogFormat:           r.optional("SMARTHOST_LOG_FORMAT", "json"),
		LiveDelivery:        r.boolean("SMARTHOST_LIVE_DELIVERY_ENABLED"),
		AllowUnverified:     r.boolean("SMARTHOST_ALLOW_UNVERIFIED_SENDING_DOMAINS"),
		BounceDomain:        strings.ToLower(r.required("SMARTHOST_BOUNCE_DOMAIN")),
		VERPLocalPart:       r.required("SMARTHOST_VERP_LOCAL_PART"),
		VERPDelimiter:       r.required("SMARTHOST_VERP_DELIMITER"),
		SubmissionUser:      r.required("SMARTHOST_SUBMISSION_USERNAME"),
		SubmissionPassword:  r.required("SMARTHOST_SUBMISSION_PASSWORD"),
		SubmissionHost:      r.required("DELIVERY_POSTFIX_SUBMISSION_HOST"),
		SubmissionPort:      r.integer("DELIVERY_POSTFIX_SUBMISSION_PORT", 1),
		ObservabilityDir:    r.required("SMARTHOST_POSTFIX_OBSERVABILITY_DIR"),
		DSNSpoolDir:         r.required("SMARTHOST_DSN_SPOOL_DIR"),
		DBHost:              r.required("SMARTHOST_DB_HOST"),
		DBPort:              r.required("SMARTHOST_DB_PORT"),
		DBName:              r.required("SMARTHOST_DB_NAME"),
		DBSSLMode:           r.required("SMARTHOST_DB_SSLMODE"),
		DBUser:              r.required("DELIVERY_DB_USER"),
		DBPassword:          r.required("DELIVERY_DB_PASSWORD"),
		WorkerID:            r.optional("DELIVERY_WORKER_ID", ""),
		PollInterval:        r.seconds("DELIVERY_POLL_INTERVAL_SECONDS", 1),
		Lease:               r.seconds("DELIVERY_LEASE_SECONDS", 10),
		GlobalConcurrency:   r.integer("DELIVERY_GLOBAL_CONCURRENCY", 1),
		DomainConcurrency:   r.integer("DELIVERY_PER_DOMAIN_CONCURRENCY", 1),
		DomainRatePerMin:    r.integer("DELIVERY_PER_DOMAIN_RATE_PER_MINUTE", 1),
		DeferralBackoff:     r.seconds("DELIVERY_DEFERRAL_BACKOFF_SECONDS", 1),
		GlobalRatePerMin:    r.integer("DELIVERY_GLOBAL_RATE_PER_MINUTE", 0),
		ThrottledClientRate: r.integer("DELIVERY_THROTTLED_CLIENT_RATE_PER_MINUTE", 1),
		DSNNotify:           r.required("DELIVERY_DSN_NOTIFY"),
		DSNRet:              r.required("DELIVERY_DSN_RET"),
		FilePollInterval:    r.seconds("DELIVERY_FILE_POLL_INTERVAL_SECONDS", 1),
		ReconcileInterval:   r.seconds("DELIVERY_RECONCILE_INTERVAL_SECONDS", 1),
		ReconcileGrace:      r.seconds("DELIVERY_RECONCILE_GRACE_SECONDS", 0),
		ReconcileMinSnaps:   r.integer("DELIVERY_RECONCILE_MIN_SNAPSHOTS", 2),
		SnapshotInterval:    r.seconds("POSTFIX_QUEUE_SNAPSHOT_INTERVAL_SECONDS", 1),
		SoftBounceThreshold: r.integer("DELIVERY_SOFT_BOUNCE_SUPPRESSION_THRESHOLD", 1),
		SoftBounceWindow:    time.Duration(r.integer("DELIVERY_SOFT_BOUNCE_SUPPRESSION_WINDOW_DAYS", 1)) * 24 * time.Hour,
		DSNRetention:        time.Duration(r.integer("DELIVERY_DSN_RETENTION_DAYS", 1)) * 24 * time.Hour,
	}
	switch c.Env {
	case "development", "test", "production":
	default:
		r.errs = append(r.errs, fmt.Sprintf("SMARTHOST_ENV must be development, test or production (got %q)", c.Env))
	}
	if c.Env == "production" && c.AllowUnverified {
		r.errs = append(r.errs, "SMARTHOST_ALLOW_UNVERIFIED_SENDING_DOMAINS=true is forbidden in production")
	}
	if c.LiveDelivery && c.Env != "production" {
		r.errs = append(r.errs, "SMARTHOST_LIVE_DELIVERY_ENABLED=true is only permitted with SMARTHOST_ENV=production")
	}
	if u, err := url.Parse(c.PublicBaseURL); c.PublicBaseURL != "" && (err != nil || u.Scheme != "https" || u.Host == "") {
		r.errs = append(r.errs, "SMARTHOST_PUBLIC_BASE_URL must be an https origin")
	}
	if len(c.VERPDelimiter) != 1 {
		r.errs = append(r.errs, "SMARTHOST_VERP_DELIMITER must be one character")
	}
	if c.ReconcileMinSnaps < 2 && c.ReconcileMinSnaps != 0 {
		r.errs = append(r.errs, "DELIVERY_RECONCILE_MIN_SNAPSHOTS must be >= 2")
	}
	switch c.LogLevel {
	case "debug", "info", "warning", "error":
	default:
		r.errs = append(r.errs, fmt.Sprintf("SMARTHOST_LOG_LEVEL invalid (%q)", c.LogLevel))
	}
	if c.WorkerID == "" {
		c.WorkerID, _ = os.Hostname()
	}
	if len(r.errs) > 0 {
		return nil, errors.New("configuration: " + strings.Join(r.errs, "; "))
	}
	return c, nil
}

// HoldSendWork reports the production state before live activation
// (SMARTHOST_ENV=production, SMARTHOST_LIVE_DELIVERY_ENABLED=false): send jobs
// are not claimed, so no campaign mail reaches Postfix. DSN, log ingestion and
// reconciliation still run. In development/test, capture mode submits normally
// (Postfix relays to Mailpit).
func (c *Config) HoldSendWork() bool { return c.Env == "production" && !c.LiveDelivery }

// PauseFlagPath is the operator's emergency-pause flag in the shared observability
// volume, written by Postfix's smarthost-postfix-control (Go reads it only).
func (c *Config) PauseFlagPath() string { return c.ObservabilityDir + "/control/outbound-paused" }

// SubmissionAddr is host:port of Postfix's authenticated submission service.
func (c *Config) SubmissionAddr() string {
	return net.JoinHostPort(c.SubmissionHost, strconv.Itoa(c.SubmissionPort))
}

// DSN is the libpq connection string of the delivery role.
func (c *Config) DSN() string {
	q := func(s string) string { return "'" + strings.NewReplacer(`\`, `\\`, `'`, `\'`).Replace(s) + "'" }
	return fmt.Sprintf("host=%s port=%s dbname=%s sslmode=%s user=%s password=%s connect_timeout=10 application_name=smarthost-delivery",
		q(c.DBHost), q(c.DBPort), q(c.DBName), q(c.DBSSLMode), q(c.DBUser), q(c.DBPassword))
}
