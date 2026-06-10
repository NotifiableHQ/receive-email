<?php

namespace Notifiable\ReceiveEmail\MailLog;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Notifiable\ReceiveEmail\Data\SmtpRejection;
use Notifiable\ReceiveEmail\Enums\RejectionClass;

class RejectionLineParser
{
    /**
     * Matches both rsyslog timestamp formats found on Ubuntu: traditional
     * BSD syslog ("Jun  9 12:34:56", no year) and RFC 3339.
     */
    private const TIMESTAMP_PATTERN = '(?<timestamp>\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:[.,]\d+)?(?:Z|[+-]\d{2}:?\d{2})?|[A-Z][a-z]{2}\s+\d{1,2}\s\d{2}:\d{2}:\d{2})';

    private const SMTPD_REJECT_PATTERN = '/^'.self::TIMESTAMP_PATTERN.'\s\S+\spostfix\/smtpd\[\d+\]:\sNOQUEUE:\sreject:\s\S+\sfrom\s(?<host>[^\[]+)\[(?<ip>[^\]]+)\]:\s(?<reason>.*?);\sfrom=<(?<sender>[^>]*)>(?:\sto=<(?<recipient>[^>]*)>)?/';

    private const POSTSCREEN_REJECT_PATTERN = '/^'.self::TIMESTAMP_PATTERN.'\s\S+\spostfix\/postscreen\[\d+\]:\sNOQUEUE:\sreject:\s\S+\sfrom\s\[(?<ip>[^\]]+)\]:\d+:\s(?<reason>.*?);\sfrom=<(?<sender>[^>]*)>,\sto=<(?<recipient>[^>]*)>/';

    private const RATE_LIMIT_WARNING_PATTERN = '/^'.self::TIMESTAMP_PATTERN.'\s\S+\spostfix\/smtpd\[\d+\]:\swarning:\sConnection\s(?:rate|concurrency)\slimit\sexceeded:\s\d+\sfrom\s(?<host>[^\[]+)\[(?<ip>[^\]]+)\]/';

    /**
     * Whether the line claims to be a rejection. A line that does but fails
     * parse() is counted as unparseable; anything else is ordinary log noise.
     */
    public function isRejectionLine(string $line): bool
    {
        return str_contains($line, 'NOQUEUE: reject:')
            || (str_contains($line, 'warning: Connection') && str_contains($line, 'limit exceeded'));
    }

    public function parse(string $line): ?SmtpRejection
    {
        if (preg_match(self::SMTPD_REJECT_PATTERN, $line, $matches, PREG_UNMATCHED_AS_NULL) === 1) {
            return $this->makeRejection($line, $matches, $this->classify($matches['reason']));
        }

        if (preg_match(self::POSTSCREEN_REJECT_PATTERN, $line, $matches, PREG_UNMATCHED_AS_NULL) === 1) {
            return $this->makeRejection($line, $matches, RejectionClass::Postscreen);
        }

        if (preg_match(self::RATE_LIMIT_WARNING_PATTERN, $line, $matches, PREG_UNMATCHED_AS_NULL) === 1) {
            return $this->makeRejection($line, $matches, RejectionClass::RateLimit);
        }

        return null;
    }

    /**
     * @param  array<int|string, string|null>  $matches
     */
    private function makeRejection(string $line, array $matches, RejectionClass $rejectionClass): ?SmtpRejection
    {
        $timestamp = $this->parseTimestamp($matches['timestamp'] ?? null);
        $clientIp = $matches['ip'] ?? null;

        if ($timestamp === null || $clientIp === null) {
            return null;
        }

        return new SmtpRejection(
            timestamp: $timestamp,
            clientHost: $matches['host'] ?? null,
            clientIp: $clientIp,
            envelopeSender: $matches['sender'] ?? null,
            recipient: $matches['recipient'] ?? null,
            rejectionClass: $rejectionClass,
            rawLine: $line,
        );
    }

    private function classify(string $reason): RejectionClass
    {
        $reason = strtolower($reason);

        return match (true) {
            str_contains($reason, 'spf') => RejectionClass::Spf,
            str_contains($reason, 'helo command rejected') => RejectionClass::Helo,
            str_contains($reason, 'sender address rejected') => RejectionClass::EnvelopeList,
            str_contains($reason, 'rate limit'),
            str_contains($reason, 'too many connections') => RejectionClass::RateLimit,
            default => RejectionClass::Other,
        };
    }

    private function parseTimestamp(?string $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            if (ctype_digit($value[0])) {
                return CarbonImmutable::parse($value);
            }

            $timestamp = CarbonImmutable::parse((string) preg_replace('/\s+/', ' ', $value));
        } catch (InvalidFormatException) {
            return null;
        }

        // Traditional syslog timestamps carry no year and default to the
        // current one; a future date means the line is from last year.
        return $timestamp->isFuture() ? $timestamp->subYear() : $timestamp;
    }
}
