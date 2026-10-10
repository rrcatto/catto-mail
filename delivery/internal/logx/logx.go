// Package logx writes the contract's structured logs (docs/architecture/
// conventions.md "Logging"): one JSON object per line on stderr with ts, level,
// service, msg and the known identifiers (client_id, job_id, message_id,
// worker_id). Message bodies, secrets, credentials and tracking tokens are never
// logged; recipient addresses only at debug level.
package logx

import (
	"encoding/json"
	"fmt"
	"io"
	"os"
	"sort"
	"strings"
	"sync"
	"time"
)

var levels = map[string]int{"debug": 0, "info": 1, "warning": 2, "error": 3}

// Logger is safe for concurrent use.
type Logger struct {
	mu     sync.Mutex
	out    io.Writer
	min    int
	text   bool
	fields map[string]any
}

// New returns a logger at level ("debug".."error") in format "json" or "text".
func New(level, format string) *Logger {
	return &Logger{out: os.Stderr, min: levels[level], text: format == "text", fields: map[string]any{}}
}

// With returns a logger that adds fields to every record.
func (l *Logger) With(kv ...any) *Logger {
	n := &Logger{out: l.out, min: l.min, text: l.text, fields: map[string]any{}}
	for k, v := range l.fields {
		n.fields[k] = v
	}
	for i := 0; i+1 < len(kv); i += 2 {
		n.fields[fmt.Sprint(kv[i])] = kv[i+1]
	}
	return n
}

func (l *Logger) log(level, msg string, kv []any) {
	if levels[level] < l.min {
		return
	}
	rec := map[string]any{}
	for k, v := range l.fields {
		rec[k] = v
	}
	for i := 0; i+1 < len(kv); i += 2 {
		if err, ok := kv[i+1].(error); ok {
			rec[fmt.Sprint(kv[i])] = err.Error()
			continue
		}
		rec[fmt.Sprint(kv[i])] = kv[i+1]
	}
	rec["ts"] = time.Now().Format("2006-01-02T15:04:05.000Z07:00") // the installation's zone (time.Local)
	rec["level"] = level
	rec["service"] = "delivery"
	rec["msg"] = msg
	var line []byte
	if l.text {
		keys := make([]string, 0, len(rec))
		for k := range rec {
			if k != "ts" && k != "level" && k != "msg" && k != "service" {
				keys = append(keys, k)
			}
		}
		sort.Strings(keys)
		var b strings.Builder
		fmt.Fprintf(&b, "%s %-7s %s", rec["ts"], level, msg)
		for _, k := range keys {
			fmt.Fprintf(&b, " %s=%v", k, rec[k])
		}
		line = []byte(b.String())
	} else {
		line, _ = json.Marshal(rec)
	}
	l.mu.Lock()
	defer l.mu.Unlock()
	_, _ = l.out.Write(append(line, '\n'))
}

func (l *Logger) Debug(msg string, kv ...any)   { l.log("debug", msg, kv) }
func (l *Logger) Info(msg string, kv ...any)    { l.log("info", msg, kv) }
func (l *Logger) Warning(msg string, kv ...any) { l.log("warning", msg, kv) }
func (l *Logger) Error(msg string, kv ...any)   { l.log("error", msg, kv) }

// SetOutput redirects the logger (tests).
func (l *Logger) SetOutput(w io.Writer) { l.out = w }
