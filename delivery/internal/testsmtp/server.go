// Package testsmtp is a deterministic in-process SMTP submission server for
// tests: it plays Postfix's submission service (STARTTLS, AUTH PLAIN, DSN,
// "250 2.0.0 Ok: queued as <QID>") and lets tests script failures by the
// recipient's local-part prefix. It records every command and message.
package testsmtp

import (
	"bufio"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/tls"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/base64"
	"fmt"
	"math/big"
	"net"
	"strings"
	"sync"
	"sync/atomic"
	"time"
)

// Recipient prefixes and what they trigger:
//
//	tempfail-rcpt-...   450 4.2.1 at RCPT
//	reject-rcpt-...     550 5.1.1 at RCPT
//	reject-data-...     554 5.7.1 after the end of data
//	milter-...          451 4.7.1 after the end of data (milter tempfail)
//	drop-before-data-.. connection closed after RCPT
//	drop-after-data-... connection closed after the end of data (ambiguous)
//	noqid-...           250 2.0.0 Ok (no queue id)
//	once-tempfail-...   451 4.3.0 the first time, then accepted
const (
	TempfailRcpt   = "tempfail-rcpt-"
	RejectRcpt     = "reject-rcpt-"
	RejectData     = "reject-data-"
	Milter         = "milter-"
	DropBeforeData = "drop-before-data-"
	DropAfterData  = "drop-after-data-"
	NoQID          = "noqid-"
	OnceTempfail   = "once-tempfail-"
)

// Message is one accepted (or attempted) message.
type Message struct {
	From, To, MailParams, RcptParams, QueueID string
	Data                                      []byte
	Accepted                                  bool
}

// Server is a running test server.
type Server struct {
	Addr     string
	User     string
	Password string
	ln       net.Listener
	tlsCfg   *tls.Config
	mu       sync.Mutex
	commands []string
	messages []Message
	seen     map[string]int
	next     atomic.Int64
	prefix   string // random per server: queue ids are unique across servers, as Postfix long ids are
	// RefuseAll makes the greeting 421 (submission service unavailable).
	RefuseAll atomic.Bool
}

// Start listens on 127.0.0.1:0.
func Start(user, password string) (*Server, error) {
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		return nil, err
	}
	cert, err := selfSigned()
	if err != nil {
		return nil, err
	}
	pfx := make([]byte, 4)
	_, _ = rand.Read(pfx)
	s := &Server{Addr: ln.Addr().String(), User: user, Password: password, ln: ln,
		tlsCfg: &tls.Config{Certificates: []tls.Certificate{cert}}, seen: map[string]int{},
		prefix: fmt.Sprintf("4T%X", pfx)}
	go s.serve()
	return s, nil
}

// Close stops listening.
func (s *Server) Close() { s.ln.Close() }

// Commands returns every command verb received, in order.
func (s *Server) Commands() []string {
	s.mu.Lock()
	defer s.mu.Unlock()
	return append([]string(nil), s.commands...)
}

// Messages returns every message transaction that reached DATA.
func (s *Server) Messages() []Message {
	s.mu.Lock()
	defer s.mu.Unlock()
	return append([]Message(nil), s.messages...)
}

// Accepted counts accepted messages per recipient.
func (s *Server) Accepted() map[string]int {
	out := map[string]int{}
	for _, m := range s.Messages() {
		if m.Accepted {
			out[m.To]++
		}
	}
	return out
}

func (s *Server) serve() {
	for {
		c, err := s.ln.Accept()
		if err != nil {
			return
		}
		go s.session(c)
	}
}

func (s *Server) record(verb string) {
	s.mu.Lock()
	s.commands = append(s.commands, verb)
	s.mu.Unlock()
}

func (s *Server) session(conn net.Conn) {
	defer conn.Close()
	r := bufio.NewReader(conn)
	w := func(line string) { _, _ = conn.Write([]byte(line + "\r\n")) }
	if s.RefuseAll.Load() {
		w("421 4.3.2 Service not available")
		return
	}
	w("220 test.submission ESMTP")
	var from, to, mailParams, rcptParams string
	authed, tlsOn := false, false
	for {
		_ = conn.SetDeadline(time.Now().Add(30 * time.Second))
		line, err := r.ReadString('\n')
		if err != nil {
			return
		}
		line = strings.TrimRight(line, "\r\n")
		verb := strings.ToUpper(strings.SplitN(line, " ", 2)[0])
		if strings.HasPrefix(strings.ToUpper(line), "MAIL FROM") {
			verb = "MAIL"
		} else if strings.HasPrefix(strings.ToUpper(line), "RCPT TO") {
			verb = "RCPT"
		}
		s.record(verb)
		switch verb {
		case "EHLO":
			ext := []string{"250-test.submission", "250-PIPELINING", "250-SIZE 10240000", "250-ENHANCEDSTATUSCODES", "250-DSN", "250-SMTPUTF8"}
			if !tlsOn {
				ext = append(ext, "250-STARTTLS")
			} else {
				ext = append(ext, "250-AUTH PLAIN LOGIN")
			}
			ext = append(ext, "250 8BITMIME")
			w(strings.Join(ext, "\r\n"))
		case "STARTTLS":
			w("220 2.0.0 Ready to start TLS")
			tc := tls.Server(conn, s.tlsCfg)
			if err := tc.Handshake(); err != nil {
				return
			}
			conn, r, tlsOn = tc, bufio.NewReader(tc), true
		case "AUTH":
			f := strings.Fields(line)
			raw, _ := base64.StdEncoding.DecodeString(f[len(f)-1])
			parts := strings.Split(string(raw), "\x00")
			if len(parts) == 3 && parts[1] == s.User && parts[2] == s.Password {
				authed = true
				w("235 2.7.0 Authentication successful")
			} else {
				w("535 5.7.8 Error: authentication failed")
			}
		case "MAIL":
			if !authed {
				w("530 5.7.0 Authentication required")
				continue
			}
			from, mailParams = between(line, "<", ">"), after(line, ">")
			w("250 2.1.0 Ok")
		case "RCPT":
			to, rcptParams = between(line, "<", ">"), after(line, ">")
			local := strings.ToLower(to)
			switch {
			case strings.HasPrefix(local, TempfailRcpt):
				w("450 4.2.1 <" + to + ">: Recipient address rejected: try again later")
				continue
			case strings.HasPrefix(local, RejectRcpt):
				w("550 5.1.1 <" + to + ">: Recipient address rejected: User unknown")
				continue
			case strings.HasPrefix(local, DropBeforeData):
				return
			}
			w("250 2.1.5 Ok")
		case "DATA":
			w("354 End data with <CR><LF>.<CR><LF>")
			data, err := readData(r)
			if err != nil {
				return
			}
			m := Message{From: from, To: to, MailParams: mailParams, RcptParams: rcptParams, Data: data}
			local := strings.ToLower(to)
			s.mu.Lock()
			s.seen[to]++
			first := s.seen[to] == 1
			s.mu.Unlock()
			switch {
			case strings.HasPrefix(local, RejectData):
				w("554 5.7.1 Message rejected by policy")
			case strings.HasPrefix(local, Milter):
				w("451 4.7.1 Service unavailable - try again later")
			case strings.HasPrefix(local, OnceTempfail) && first:
				w("451 4.3.0 Error: queue file write error")
			case strings.HasPrefix(local, DropAfterData):
				s.mu.Lock()
				s.messages = append(s.messages, m)
				s.mu.Unlock()
				return
			case strings.HasPrefix(local, NoQID):
				m.Accepted = true
				w("250 2.0.0 Ok")
			default:
				m.Accepted = true
				m.QueueID = fmt.Sprintf("%s%07d", s.prefix, s.next.Add(1))
				w("250 2.0.0 Ok: queued as " + m.QueueID)
			}
			s.mu.Lock()
			s.messages = append(s.messages, m)
			s.mu.Unlock()
		case "RSET":
			w("250 2.0.0 Ok")
		case "QUIT":
			w("221 2.0.0 Bye")
			return
		default:
			w("502 5.5.2 Error: command not recognized")
		}
	}
}

func readData(r *bufio.Reader) ([]byte, error) {
	var out []byte
	for {
		line, err := r.ReadString('\n')
		if err != nil {
			return nil, err
		}
		if line == ".\r\n" {
			return out, nil
		}
		if strings.HasPrefix(line, "..") {
			line = line[1:]
		}
		out = append(out, line...)
	}
}

func between(s, a, b string) string {
	i := strings.Index(s, a)
	j := strings.Index(s, b)
	if i < 0 || j < i {
		return ""
	}
	return s[i+1 : j]
}

func after(s, a string) string {
	if i := strings.Index(s, a); i >= 0 {
		return strings.TrimSpace(s[i+1:])
	}
	return ""
}

func selfSigned() (tls.Certificate, error) {
	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		return tls.Certificate{}, err
	}
	tmpl := &x509.Certificate{SerialNumber: big.NewInt(1), Subject: pkix.Name{CommonName: "test.submission"},
		NotBefore: time.Now().Add(-time.Hour), NotAfter: time.Now().Add(time.Hour), DNSNames: []string{"localhost"}}
	der, err := x509.CreateCertificate(rand.Reader, tmpl, tmpl, &key.PublicKey, key)
	if err != nil {
		return tls.Certificate{}, err
	}
	return tls.Certificate{Certificate: [][]byte{der}, PrivateKey: key}, nil
}
