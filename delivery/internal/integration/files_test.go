//go:build integration

package integration

import (
	"compress/gzip"
	"os"
	"testing"
	"time"
)

func appendRaw(t *testing.T, path, s string) {
	t.Helper()
	f, err := os.OpenFile(path, os.O_APPEND|os.O_WRONLY, 0o640)
	if err != nil {
		t.Fatal(err)
	}
	defer f.Close()
	_, _ = f.WriteString(s)
}

// rotateGzip mimics `postfix logrotate`: rename the active file with a
// timestamp suffix, compress it immediately (new inode) and remove the
// uncompressed copy; the caller then writes a new active file.
func rotateGzip(t *testing.T, path string, at time.Time) {
	t.Helper()
	data, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	gz := path + "." + at.Format("20060102-150405") + ".gz"
	f, _ := os.Create(gz)
	z := gzip.NewWriter(f)
	_, _ = z.Write(data)
	z.Close()
	f.Close()
	_ = os.Remove(path)
}
