<?php

namespace Notifiable\ReceiveEmail\Data;

/**
 * The SMTP envelope of one accepted delivery, as Postfix hands it to the
 * pipe command: the Envelope Sender (`MAIL FROM`), every Envelope Recipient
 * (`RCPT TO`), the connecting client's address, and the Postfix queue ID.
 */
readonly class Envelope
{
    /**
     * Null for the null Envelope Sender (`MAIL FROM:<>`, DSNs).
     */
    public ?string $sender;

    /** @var string[] */
    public array $recipients;

    public ?string $clientAddress;

    public ?string $queueId;

    /**
     * Postfix passes the null Envelope Sender as an empty string (the pipe's
     * `null_sender=` attribute) and expands unavailable macros to empty
     * strings, so empty values normalize to null — `MAIL FROM:<>` is
     * recorded honestly, never as a literal address.
     *
     * @param  string[]  $recipients
     */
    public function __construct(
        ?string $sender = null,
        array $recipients = [],
        ?string $clientAddress = null,
        ?string $queueId = null,
    ) {
        $this->sender = $sender === '' ? null : $sender;
        $this->recipients = array_values(array_filter($recipients, fn (string $recipient): bool => $recipient !== ''));
        $this->clientAddress = $clientAddress === '' ? null : $clientAddress;
        $this->queueId = $queueId === '' ? null : $queueId;
    }
}
