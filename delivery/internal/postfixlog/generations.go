package postfixlog

import (
	"bufio"
	"compress/gzip"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"io"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"syscall"
	"time"
)

// ActiveName is the file postlogd writes (maillog_file).
const ActiveName = "postfix.log"

// Generation is one log generation: the active file or a rotated file
// (postfix.log.<%Y%m%d-%H%M%S>, normally gzip-compressed at once).
//
// Its identity is the SHA-256 fingerprint of its first record (V-1): the same
// in the active file and in its compressed copy, whereas the inode changes on
// compression and inode numbers are reused.
type Generation struct {
	ID         string
	Path       string
	Compressed bool
	Active     bool
	ModTime    time.Time
	Inode      uint64 // active file only: detects a rotation
}

// List returns the retained generations, oldest first, the active one last.
// Generations without a complete first record are omitted.
func List(dir string) ([]Generation, error) {
	entries, err := os.ReadDir(dir)
	if err != nil {
		return nil, err
	}
	var rotated []string
	for _, e := range entries {
		if strings.HasPrefix(e.Name(), ActiveName+".") && !e.IsDir() {
			rotated = append(rotated, e.Name())
		}
	}
	sort.Strings(rotated) // the suffix is a sortable timestamp
	var gens []Generation
	for _, name := range append(rotated, ActiveName) {
		path := filepath.Join(dir, name)
		st, err := os.Stat(path)
		if err != nil {
			continue
		}
		g := Generation{Path: path, Compressed: strings.HasSuffix(name, ".gz"), Active: name == ActiveName, ModTime: st.ModTime()}
		if sys, ok := st.Sys().(*syscall.Stat_t); ok {
			g.Inode = sys.Ino
		}
		id, err := fingerprint(g)
		if err != nil {
			continue
		}
		g.ID = id
		gens = append(gens, g)
	}
	return gens, nil
}

var errNoFirstRecord = errors.New("no complete first record")

func fingerprint(g Generation) (string, error) {
	rd, closer, err := open(g)
	if err != nil {
		return "", err
	}
	defer closer()
	line, err := bufio.NewReader(rd).ReadString('\n')
	if err != nil {
		return "", errNoFirstRecord
	}
	sum := sha256.Sum256([]byte(strings.TrimRight(line, "\r\n")))
	return hex.EncodeToString(sum[:]), nil
}

func open(g Generation) (io.Reader, func(), error) {
	f, err := os.Open(g.Path)
	if err != nil {
		return nil, nil, err
	}
	if !g.Compressed {
		return f, func() { f.Close() }, nil
	}
	z, err := gzip.NewReader(f)
	if err != nil {
		f.Close()
		return nil, nil, err
	}
	return z, func() { z.Close(); f.Close() }, nil
}

// Line is one complete record and its byte position in the uncompressed
// generation (the postfix_log event key is "<generation id>:<position>").
type Line struct {
	Pos  int64
	Text string
}

// Reader returns the complete records of a generation from a position on.
type Reader struct {
	r      *bufio.Reader
	closer func()
	pos    int64
}

// ErrRotated means the file at the generation's path is now another
// generation (postfix logrotate ran between listing and opening).
var ErrRotated = errors.New("log rotated while opening")

// Open opens g at byte position pos of its uncompressed stream. For the active
// file it re-checks the first-record fingerprint on the opened handle, so a
// rotation between List and Open can never mix two generations' positions.
func Open(g Generation, pos int64) (*Reader, error) {
	rd, closer, err := open(g)
	if err != nil {
		return nil, err
	}
	if f, ok := rd.(*os.File); ok {
		first, err := bufio.NewReader(f).ReadString('\n')
		sum := sha256.Sum256([]byte(strings.TrimRight(first, "\r\n")))
		if err != nil || hex.EncodeToString(sum[:]) != g.ID {
			closer()
			return nil, ErrRotated
		}
		if _, err := f.Seek(pos, io.SeekStart); err != nil {
			closer()
			return nil, err
		}
	} else if _, err := io.CopyN(io.Discard, rd, pos); err != nil {
		closer()
		return nil, err
	}
	return &Reader{r: bufio.NewReaderSize(rd, 64*1024), closer: closer, pos: pos}, nil
}

// Next returns the next complete record. ok is false at the end of the data;
// a trailing partial record (no newline yet) is never returned or consumed.
func (r *Reader) Next() (Line, bool, error) {
	text, err := r.r.ReadString('\n')
	if err == io.EOF {
		return Line{}, false, nil
	}
	if err != nil {
		return Line{}, false, err
	}
	l := Line{Pos: r.pos, Text: strings.TrimRight(text, "\r\n")}
	r.pos += int64(len(text))
	return l, true, nil
}

// Pos is the position after the last returned record.
func (r *Reader) Pos() int64 { return r.pos }

// Close releases the file.
func (r *Reader) Close() { r.closer() }
