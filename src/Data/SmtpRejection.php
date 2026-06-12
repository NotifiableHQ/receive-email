<?php

namespace Notifiable\ReceiveEmail\Data;

use Carbon\CarbonImmutable;
use Notifiable\ReceiveEmail\Enums\RejectionClass;

readonly class SmtpRejection
{
    /**
     * Fields are taken verbatim from the Postfix log line.
     *
     * $clientHost is null when the line carries only an IP (postscreen),
     * and "unknown" when Postfix could not resolve the client.
     * $envelopeSender is the empty string for the null sender (from=<>)
     * and null when the line carries no envelope information at all.
     */
    public function __construct(
        public CarbonImmutable $timestamp,
        public ?string $clientHost,
        public string $clientIp,
        public ?string $envelopeSender,
        public ?string $recipient,
        public RejectionClass $rejectionClass,
        public string $rawLine,
    ) {}
}
