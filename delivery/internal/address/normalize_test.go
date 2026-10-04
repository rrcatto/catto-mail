package address

import (
	"encoding/json"
	"os"
	"path/filepath"
	"testing"
)

// vectorFile is the shared D-32 contract (copied into the test image; the
// default path works from a repository checkout).
func vectorFile() string {
	if p := os.Getenv("SMARTHOST_NORMALIZATION_VECTORS"); p != "" {
		return p
	}
	return filepath.Join("..", "..", "..", "docs", "contracts", "address-normalization-vectors.json")
}

type vector struct {
	Input      string  `json:"input"`
	Normalized *string `json:"normalized"`
	Note       string  `json:"note"`
}

func TestSharedNormalizationVectors(t *testing.T) {
	raw, err := os.ReadFile(vectorFile())
	if err != nil {
		t.Fatalf("read vectors: %v", err)
	}
	var doc struct {
		Vectors []vector `json:"vectors"`
	}
	if err := json.Unmarshal(raw, &doc); err != nil {
		t.Fatal(err)
	}
	if len(doc.Vectors) < 50 {
		t.Fatalf("only %d vectors", len(doc.Vectors))
	}
	failed := 0
	for _, v := range doc.Vectors {
		got, ok := Normalize(v.Input)
		switch {
		case v.Normalized == nil && ok:
			failed++
			t.Errorf("%q: expected no normalised form, got %q (%s)", v.Input, got, v.Note)
		case v.Normalized != nil && !ok:
			failed++
			t.Errorf("%q: expected %q, got no normalised form (%s)", v.Input, *v.Normalized, v.Note)
		case v.Normalized != nil && got != *v.Normalized:
			failed++
			t.Errorf("%q: expected %q, got %q (%s)", v.Input, *v.Normalized, got, v.Note)
		}
	}
	t.Logf("%d vectors, %d failed", len(doc.Vectors), failed)
}

func TestLocalPartIsNeverRewritten(t *testing.T) {
	for _, in := range []string{"John.Smith@Example.COM", "ÜNÏ@Example.com", `"Quoted Local"@x.example`} {
		got, ok := Normalize(in)
		if !ok {
			t.Fatalf("%q: no form", in)
		}
		if got[:len(got)-len(Domain(got))-1] != in[:len(in)-len(Domain(in))-1] {
			t.Errorf("local part changed: %q -> %q", in, got)
		}
	}
}
