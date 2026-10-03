<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\Formatter\FormatterInterface;
use Monolog\LogRecord;

/**
 * Log line format from docs/architecture/conventions.md: JSON objects with the
 * required fields ts, level, service and msg, plus context (client_id, job_id, ...).
 * SMARTHOST_LOG_FORMAT=text gives a single readable line for local debugging.
 * Never pass raw API keys, secrets or message bodies in the context.
 */
final class ContractFormatter implements FormatterInterface
{
    public function __construct(private readonly string $format = 'json')
    {
        if (!\in_array($format, ['json', 'text'], true)) {
            throw new \InvalidArgumentException('SMARTHOST_LOG_FORMAT must be "json" or "text".');
        }
    }

    public function format(LogRecord $record): string
    {
        $fields = [
            'ts' => $record->datetime->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z'),
            'level' => strtolower($record->level->getName()),
            'service' => 'symfony-app',
            'msg' => $record->message,
            'channel' => $record->channel,
        ] + $this->normalise($record->context) + $this->normalise($record->extra);

        if ('text' === $this->format) {
            $rest = array_slice($fields, 4);

            return \sprintf("%s %s %s%s\n", $fields['ts'], strtoupper($fields['level']), $fields['msg'],
                [] === $rest ? '' : ' '.json_encode($rest, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
        }

        return json_encode($fields, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_PARTIAL_OUTPUT_ON_ERROR)."\n";
    }

    public function formatBatch(array $records): string
    {
        return implode('', array_map($this->format(...), $records));
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function normalise(array $values): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            $out[(string) $key] = match (true) {
                $value instanceof \Throwable => ['class' => $value::class, 'message' => $value->getMessage(),
                    'file' => $value->getFile().':'.$value->getLine()],
                $value instanceof \DateTimeInterface => $value->format(\DATE_RFC3339_EXTENDED),
                $value instanceof \Stringable => (string) $value,
                \is_object($value) => $value::class,
                \is_array($value) => $this->normalise($value),
                default => $value,
            };
        }

        return $out;
    }
}
