// Package status holds the message status and event vocabulary of
// docs/contracts/status-vocabulary.yaml (message_status, message_event_type)
// and the projection rule: a new event changes messages.current_status only to
// a status of higher rank, so replayed or out-of-order events never regress a
// message; failed and suppressed are reachable only from created/queued;
// resolved_at is set the first time a terminal status is entered.
package status

// Rank of every message_status value.
var Rank = map[string]int{
	"created": 10, "queued": 20, "submitted": 30, "deferred": 40, "outcome_unknown": 45,
	"remote_accepted": 50, "soft_bounced": 60, "hard_bounced": 70, "complained": 80,
	"failed": 90, "suppressed": 90,
}

// Terminal statuses are terminal knowledge states (they count towards
// send-job completion). outcome_unknown is terminal but NOT a success.
var Terminal = map[string]bool{
	"outcome_unknown": true, "remote_accepted": true, "soft_bounced": true,
	"hard_bounced": true, "complained": true, "failed": true, "suppressed": true,
}

// SetsStatus maps an event type to the status it moves a message to ("" for
// informational events).
var SetsStatus = map[string]string{
	"message_created": "created", "message_queued": "queued", "message_suppressed": "suppressed",
	"submission_failed": "failed", "submitted_to_postfix": "submitted", "postfix_queued": "",
	"delivery_attempt": "", "deferred": "deferred", "connection_failure": "deferred",
	"remote_accepted": "remote_accepted", "soft_bounce": "soft_bounced", "hard_bounce": "hard_bounced",
	"complaint": "complained", "transport_outcome_unknown": "outcome_unknown",
	"open_recorded": "", "click_recorded": "", "dsn_unmatched": "",
}

// Next returns the status after applying an event's target status to current,
// and whether it changed.
func Next(current, target string) (string, bool) {
	if target == "" || target == current {
		return current, false
	}
	if (target == "failed" || target == "suppressed") && current != "created" && current != "queued" {
		return current, false
	}
	if Rank[target] <= Rank[current] {
		return current, false
	}
	return target, true
}

// Unresolved statuses keep a dispatched send job from completing.
var Unresolved = []string{"created", "queued", "submitted", "deferred"}
