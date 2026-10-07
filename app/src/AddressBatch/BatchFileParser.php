<?php

declare(strict_types=1);

namespace App\AddressBatch;

use App\Domain\DomainRuleViolation;
use App\Sending\AddressNormalizer;

/**
 * Parses an administrator's address upload (specification 2.11): a TXT file with one
 * address per line, or a CSV file with a header row and a chosen address column.
 *
 * Nothing is silently lost. Every non-blank row becomes an entry: imported, a duplicate
 * of an earlier row (same D-18 normalised address), or malformed input that cannot be an
 * address at all (it is reported, not validated). Blank rows are only counted. Cleanup is
 * limited to what cannot change an address's meaning: surrounding whitespace (including
 * no-break and zero-width spaces), a `mailto:` prefix, and the address inside a
 * `Name <address>` form; the original row is always kept. Syntax beyond that is the
 * validator's job.
 */
final class BatchFileParser
{
    public const MAX_ROWS = 10000;
    public const MAX_BYTES = 10 * 1024 * 1024;
    private const EMAIL_HEADERS = ['email', 'e-mail', 'email address', 'e-mail address', 'emailaddress', 'email_address', 'mail', 'address'];

    /**
     * @return array{format: 'txt'|'csv', delimiter: ?string, columns: list<string>, column: ?string, sha256: string,
     *               rows: list<array{row: int, original: string, normalized: ?string, outcome: string, detail: ?string, duplicate_of_row: ?int}>,
     *               counts: array{data_rows: int, imported: int, duplicate: int, malformed: int, blank: int}}
     */
    public static function parse(string $bytes, string $filename, ?string $column = null): array
    {
        if (\strlen($bytes) > self::MAX_BYTES) {
            throw new DomainRuleViolation(\sprintf('The file is larger than %d MiB.', self::MAX_BYTES / 1048576));
        }
        if ('' === trim($bytes)) {
            throw new DomainRuleViolation('The file is empty.');
        }
        $sha256 = hash('sha256', $bytes);
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            $bytes = substr($bytes, 3);
        }
        $bytes = str_replace(["\r\n", "\r"], "\n", $bytes);
        $format = self::format($bytes, $filename);
        $columns = [];
        $delimiter = null;
        if ('csv' === $format) {
            [$delimiter, $columns, $values] = self::csvValues($bytes, $column);
            $column = $values['column'];
            $raw = $values['rows'];
        } else {
            $column = null;
            $raw = [];
            foreach (explode("\n", $bytes) as $i => $line) {
                $raw[] = [$i + 1, $line];
            }
            while ([] !== $raw && '' === trim(end($raw)[1])) {
                array_pop($raw); // the trailing newline is not a blank row
            }
        }

        $rows = [];
        $seen = [];
        $counts = ['data_rows' => 0, 'imported' => 0, 'duplicate' => 0, 'malformed' => 0, 'blank' => 0];
        foreach ($raw as [$rowNumber, $value]) {
            if ('' === self::trim($value)) {
                ++$counts['blank'];
                continue;
            }
            if (++$counts['data_rows'] > self::MAX_ROWS) {
                throw new DomainRuleViolation(\sprintf('The file has more than %s addresses; split it into batches of at most %s.',
                    number_format(self::MAX_ROWS), number_format(self::MAX_ROWS)));
            }
            [$normalized, $detail] = self::clean($value);
            if (null === $normalized) {
                $rows[] = ['row' => $rowNumber, 'original' => mb_substr($value, 0, 1000), 'normalized' => null, 'outcome' => 'malformed',
                    'detail' => $detail, 'duplicate_of_row' => null];
                ++$counts['malformed'];
            } elseif (isset($seen[$normalized])) {
                $rows[] = ['row' => $rowNumber, 'original' => mb_substr($value, 0, 1000), 'normalized' => $normalized, 'outcome' => 'duplicate',
                    'detail' => 'Same address as row '.$seen[$normalized].'.', 'duplicate_of_row' => $seen[$normalized]];
                ++$counts['duplicate'];
            } else {
                $seen[$normalized] = $rowNumber;
                $rows[] = ['row' => $rowNumber, 'original' => mb_substr($value, 0, 1000), 'normalized' => $normalized, 'outcome' => 'imported',
                    'detail' => $detail, 'duplicate_of_row' => null];
                ++$counts['imported'];
            }
        }
        if (0 === $counts['data_rows']) {
            throw new DomainRuleViolation('The file contains no addresses.');
        }

        return ['format' => $format, 'delimiter' => $delimiter, 'columns' => $columns, 'column' => $column, 'sha256' => $sha256,
            'rows' => $rows, 'counts' => $counts];
    }

    /** @return array{0: ?string, 1: ?string} the normalised address (or null) and a note on the cleanup or the problem */
    public static function clean(string $value): array
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            return [null, 'Not valid UTF-8 text.'];
        }
        $v = self::trim($value);
        $note = null;
        if (1 === preg_match('/^(?:"?([^"<>]*)"?\s*)<([^<>\s]+)>$/u', $v, $m)) {
            $v = $m[2];
            $note = 'Address taken from the form "Name <address>".';
        }
        if (0 === stripos($v, 'mailto:')) {
            $v = substr($v, 7);
            $note = 'The mailto: prefix was removed.';
        }
        if (mb_strlen($v) > 320) {
            return [null, 'Longer than 320 characters.'];
        }
        if (1 === preg_match('/[\x00-\x1F\x7F]/', $v)) {
            return [null, 'Contains control characters.'];
        }
        if (1 === preg_match('/\s/u', $v)) {
            return [null, 'Contains spaces inside the address.'];
        }
        if (!str_contains($v, '@')) {
            return [null, 'No @ sign: not an e-mail address.'];
        }
        $normalized = AddressNormalizer::normalize($v);
        if (null === $normalized) {
            return [null, 'Nothing before or after the @, or an invalid domain.'];
        }

        return [$normalized, $note];
    }

    private static function trim(string $v): string
    {
        return (string) preg_replace('/^[\s\x{00A0}\x{200B}\x{FEFF}]+|[\s\x{00A0}\x{200B}\x{FEFF}]+$/u', '', $v);
    }

    private static function format(string $bytes, string $filename): string
    {
        $ext = strtolower(pathinfo($filename, \PATHINFO_EXTENSION));
        if ('csv' === $ext) {
            return 'csv';
        }
        if ('txt' === $ext) {
            return 'txt';
        }
        $first = strtok($bytes, "\n") ?: '';

        return 1 === preg_match('/[,;\t]/', $first) && 1 !== preg_match('/^[^,;\t]*@[^,;\t]*$/', $first) ? 'csv' : 'txt';
    }

    /** @return array{0: string, 1: list<string>, 2: array{column: string, rows: list<array{0: int, 1: string}>}} */
    private static function csvValues(string $bytes, ?string $column): array
    {
        $firstLine = (string) strtok($bytes, "\n");
        $counts = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];
        arsort($counts);
        $delimiter = (string) array_key_first($counts);
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $bytes);
        rewind($stream);
        $header = fgetcsv($stream, 0, $delimiter, '"', '');
        if (!\is_array($header) || [null] === $header) {
            throw new DomainRuleViolation('The CSV file has no header row.');
        }
        $header = array_map(static fn ($h): string => self::trim((string) $h), $header);
        $records = [];
        $line = 1;
        while (false !== ($rec = fgetcsv($stream, 0, $delimiter, '"', ''))) {
            ++$line;
            $records[] = [$line, $rec];
        }
        fclose($stream);
        if (null !== $column && '' !== $column) {
            $index = array_search($column, $header, true);
            if (false === $index) {
                throw new DomainRuleViolation("The CSV file has no column \"$column\".");
            }
        } else {
            $index = self::guessColumn($header, $records);
        }
        $rows = [];
        foreach ($records as [$n, $rec]) {
            $rows[] = [$n, [null] === $rec ? '' : (string) ($rec[$index] ?? '')];
        }

        return [$delimiter, $header, ['column' => $header[$index], 'rows' => $rows]];
    }

    /** @param list<string> $header @param list<array{0: int, 1: array<int, ?string>}> $records */
    private static function guessColumn(array $header, array $records): int
    {
        foreach ($header as $i => $h) {
            if (\in_array(strtolower($h), self::EMAIL_HEADERS, true)) {
                return $i;
            }
        }
        $best = 0;
        $bestCount = -1;
        foreach ($header as $i => $h) {
            $n = 0;
            foreach (\array_slice($records, 0, 50) as [, $rec]) {
                $n += str_contains((string) ($rec[$i] ?? ''), '@') ? 1 : 0;
            }
            if ($n > $bestCount) {
                [$best, $bestCount] = [$i, $n];
            }
        }

        return $best;
    }
}
