package smtpsub

import (
	"context"
	"crypto/tls"
	"net"
	"strings"
	"testing"
	"time"

	"smarthost.local/delivery/internal/testsmtp"
)

func setup(t *testing.T) (*testsmtp.Server, Config) {
	t.Helper()
	s, err := testsmtp.Start("submit", "secret")
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(s.Close)
	return s, Config{Addr: s.Addr, HeloName: "test", Username: "submit", Password: "secret",
		ConnectTimeout: 2 * time.Second, CommandTimeout: 2 * time.Second, DataTimeout: 2 * time.Second,
		TLS: &tls.Config{InsecureSkipVerify: true}, RequireTLSForAuth: true} //nolint:gosec
}

func env(to string) Envelope {
	return Envelope{From: "bounce+tok@bounce.example", To: to, EnvID: "0189-id", Ret: "HDRS", Notify: "FAILURE,DELAY"}
}

var msg = []byte("Subject: t\r\n\r\n.leading dot\r\nbody\r\n")

func TestAcceptedWithQueueIDAndDSNParameters(t *testing.T) {
	s, cfg := setup(t)
	r := Submit(context.Background(), cfg, env("ok@rcpt.example"), msg)
	if r.Outcome != Accepted || !strings.HasPrefix(r.QueueID, "4T") || r.Code != 250 {
		t.Fatalf("%+v", r)
	}
	m := s.Messages()[0]
	if m.MailParams != "RET=HDRS ENVID=0189-id" || m.RcptParams != "NOTIFY=FAILURE,DELAY ORCPT=rfc822;ok@rcpt.example" {
		t.Fatalf("params %q / %q", m.MailParams, m.RcptParams)
	}
	if string(m.Data) != string(msg) {
		t.Fatalf("data (dot-stuffing round trip) %q", m.Data)
	}
	cmds := strings.Join(s.Commands(), " ")
	if cmds != "EHLO STARTTLS EHLO AUTH MAIL RCPT DATA QUIT" {
		t.Fatalf("command sequence %q", cmds)
	}
}

func TestOutcomes(t *testing.T) {
	cases := []struct {
		to    string
		want  Outcome
		stage string
		infra bool
	}{
		{testsmtp.TempfailRcpt + "a@x.example", Temporary, "rcpt", false},
		{testsmtp.RejectRcpt + "a@x.example", Permanent, "rcpt", false},
		{testsmtp.RejectData + "a@x.example", Permanent, "end-of-data", false},
		{testsmtp.Milter + "a@x.example", Temporary, "end-of-data", true},
		{testsmtp.DropBeforeData + "a@x.example", Temporary, "rcpt", false}, // dropped before DATA: never queued
		{testsmtp.DropAfterData + "a@x.example", Ambiguous, "end-of-data", false},
		{testsmtp.NoQID + "a@x.example", Ambiguous, "end-of-data", false},
	}
	for _, c := range cases {
		_, cfg := setup(t)
		r := Submit(context.Background(), cfg, env(c.to), msg)
		if r.Outcome != c.want || r.Stage != c.stage || r.Infrastructure != c.infra {
			t.Errorf("%s: %+v (want %s at %s infra=%v)", c.to, r, c.want, c.stage, c.infra)
		}
	}
}

func TestConnectionRefusedAndGreetingRefusal(t *testing.T) {
	ln, _ := net.Listen("tcp", "127.0.0.1:0")
	addr := ln.Addr().String()
	ln.Close()
	_, cfg := setup(t)
	cfg.Addr = addr
	if r := Submit(context.Background(), cfg, env("a@x.example"), msg); r.Outcome != Temporary || !r.Infrastructure || r.Stage != "connect" {
		t.Fatalf("refused: %+v", r)
	}
	s, cfg := setup(t)
	s.RefuseAll.Store(true)
	if r := Submit(context.Background(), cfg, env("a@x.example"), msg); r.Outcome != Temporary || !r.Infrastructure || r.Code != 421 {
		t.Fatalf("421 greeting: %+v", r)
	}
}

func TestWrongCredentialsAreInfrastructure(t *testing.T) {
	_, cfg := setup(t)
	cfg.Password = "wrong"
	r := Submit(context.Background(), cfg, env("a@x.example"), msg)
	if r.Outcome != Temporary || r.Stage != "auth" || !r.Infrastructure {
		t.Fatalf("%+v", r)
	}
}

func TestNoAuthWithoutTLS(t *testing.T) {
	_, cfg := setup(t)
	cfg.TLS = nil
	r := Submit(context.Background(), cfg, env("a@x.example"), msg)
	if r.Outcome != Temporary || r.Stage != "auth" {
		t.Fatalf("credentials must not be sent in clear: %+v", r)
	}
}

func TestHelpers(t *testing.T) {
	if XText("a+b=c d") != "a+2Bb+3Dc+20d" {
		t.Error(XText("a+b=c d"))
	}
	if q, err := QueueID("2.0.0 Ok: queued as 4hy3XY7274z1kFg"); err != nil || q != "4hy3XY7274z1kFg" {
		t.Error(q, err)
	}
	if _, err := QueueID("2.0.0 Ok"); err == nil {
		t.Error("no queue id accepted")
	}
	if got := string(dotStuff([]byte(".a\r\nb"))); got != "..a\r\nb\r\n.\r\n" {
		t.Errorf("dotStuff %q", got)
	}
}
