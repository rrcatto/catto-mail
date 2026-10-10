package postfixlog

import (
	"strings"
	"time"
)

// Located is a parsed record with its event identity.
type Located struct {
	Record
	GenerationID string
	Pos          int64
}

// Key is the postfix_log source_event_key of the record.
func (l Located) Key() string { return l.GenerationID + ":" + itoa(l.Pos) }

func itoa(n int64) string {
	if n == 0 {
		return "0"
	}
	var b [20]byte
	i := len(b)
	for n > 0 {
		i--
		b[i] = byte('0' + n%10)
		n /= 10
	}
	return string(b[i:])
}

// Scan reads every retained generation modified at or after since and calls fn
// for each parsed record. It is used for recovery and reconciliation re-scans,
// never for routine ingestion (which follows the cursor).
func Scan(dir string, since time.Time, fn func(Located)) error {
	gens, err := List(dir)
	if err != nil {
		return err
	}
	now := time.Now()
	for _, g := range gens {
		if g.ModTime.Before(since) {
			continue
		}
		rd, err := Open(g, 0)
		if err != nil {
			continue
		}
		for {
			l, ok, err := rd.Next()
			if err != nil || !ok {
				break
			}
			if rec, ok := Parse(l.Text, now); ok {
				fn(Located{Record: rec, GenerationID: g.ID, Pos: l.Pos})
			}
		}
		rd.Close()
	}
	return nil
}

// Submission is what the log shows about one Smarthost Message-ID.
type Submission struct {
	Cleanup   Located // the cleanup "message-id=<...>" record
	Committed bool    // the queue manager took the message (it is in Postfix's queue)
}

// FindSubmissions looks up Message-IDs (the value inside <...>, compared
// case-insensitively) in the retained log (§6.3). A message counts as queued
// only when, after its cleanup record, the queue manager logged the same
// queue id ("queue active", a delivery status, or "removed").
func FindSubmissions(dir string, since time.Time, messageIDs map[string]bool) (map[string]Submission, error) {
	found := map[string]Submission{}
	byQID := map[string]string{}
	err := Scan(dir, since, func(l Located) {
		switch l.Kind {
		case MessageID:
			id := strings.ToLower(l.MessageID)
			if messageIDs[id] {
				if _, seen := found[id]; !seen {
					found[id] = Submission{Cleanup: l}
					byQID[l.QueueID] = id
				}
			}
		case QueueActive, Delivery, Removed:
			if id, ok := byQID[l.QueueID]; ok {
				s := found[id]
				s.Committed = true
				found[id] = s
			}
		}
	})
	return found, err
}

// FindQueueIDs returns every parsed record of the given queue ids (D-27 re-scan).
func FindQueueIDs(dir string, since time.Time, qids map[string]bool) ([]Located, error) {
	var out []Located
	err := Scan(dir, since, func(l Located) {
		if qids[l.QueueID] {
			out = append(out, l)
		}
	})
	return out, err
}
