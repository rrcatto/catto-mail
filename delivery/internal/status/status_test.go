package status

import "testing"

func TestProjectionNeverRegresses(t *testing.T) {
	cur := "created"
	// out of order and replayed: queued, deferred, submitted (late), deferred again, remote_accepted, deferred
	for _, ev := range []string{"message_queued", "deferred", "submitted_to_postfix", "deferred", "remote_accepted", "deferred", "postfix_queued"} {
		cur, _ = Next(cur, SetsStatus[ev])
	}
	if cur != "remote_accepted" {
		t.Fatalf("got %s", cur)
	}
	if _, changed := Next("submitted", "suppressed"); changed {
		t.Fatal("suppressed reachable after submission")
	}
	if _, changed := Next("deferred", "failed"); changed {
		t.Fatal("failed reachable after submission")
	}
	if s, _ := Next("queued", "failed"); s != "failed" {
		t.Fatal("failed from queued")
	}
	if s, _ := Next("outcome_unknown", "remote_accepted"); s != "remote_accepted" {
		t.Fatal("authoritative event must supersede outcome_unknown")
	}
	if s, _ := Next("outcome_unknown", "deferred"); s != "outcome_unknown" {
		t.Fatal("deferral must not reopen outcome_unknown")
	}
	if s, _ := Next("remote_accepted", "outcome_unknown"); s != "remote_accepted" {
		t.Fatal("outcome_unknown must never replace success")
	}
	if !Terminal["outcome_unknown"] || Terminal["deferred"] || Rank["outcome_unknown"] >= Rank["remote_accepted"] {
		t.Fatal("vocabulary")
	}
}
