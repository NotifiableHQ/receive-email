<?php

namespace Notifiable\ReceiveEmail\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Config;
use Notifiable\ReceiveEmail\Contracts\ParsedMailContract;
use Notifiable\ReceiveEmail\Data\Envelope;
use Notifiable\ReceiveEmail\Enums\Source;
use Notifiable\ReceiveEmail\Exceptions\FailedToDeleteException;
use Notifiable\ReceiveEmail\Exceptions\FailedToReadException;
use Notifiable\ReceiveEmail\Facades\ParsedMail;
use RuntimeException;

use function Notifiable\ReceiveEmail\storage;

/**
 * @property string $ulid
 * @property string|null $envelope_sender Envelope Sender (SMTP `MAIL FROM`); null for the null sender (`MAIL FROM:<>`)
 * @property array<int, string> $envelope_recipients Envelope Recipients (SMTP `RCPT TO`)
 * @property string|null $client_address
 * @property string|null $queue_id
 * @property string|null $message_id Header enrichment; null for Malformed Mail
 * @property string|null $sender_ulid
 * @property-read  Sender|null $sender Header Sender; null for Malformed Mail
 * @property CarbonImmutable|null $sent_at Header enrichment; null for Malformed Mail
 * @property CarbonImmutable|null $parsed_at Null until header parsing succeeds
 * @property CarbonImmutable|null $created_at
 */
class Email extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $primaryKey = 'ulid';

    protected $guarded = ['ulid', 'created_at'];

    protected $with = ['sender'];

    protected $attributes = [
        'envelope_recipients' => '[]',
    ];

    protected $casts = [
        'envelope_recipients' => 'array',
        'sent_at' => 'immutable_datetime',
        'parsed_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
    ];

    public function getTable(): string
    {
        return Config::string('receive_email.email-table');
    }

    protected static function booted(): void
    {
        static::deleted(function (self $email) {
            $email->deleteFile();
        });
    }

    /** @return BelongsTo<Sender, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(Sender::class);
    }

    /**
     * Build an unsaved Email from the SMTP envelope with its ULID and
     * created_at pre-generated, so the storage path is derivable before the
     * row exists: raw-first ingestion stores the file, then commits the row.
     */
    public static function fromEnvelope(Envelope $envelope): self
    {
        $email = new self([
            'envelope_sender' => $envelope->sender,
            'envelope_recipients' => $envelope->recipients,
            'client_address' => $envelope->clientAddress,
            'queue_id' => $envelope->queueId,
        ]);

        $email->ulid = $email->newUniqueId();
        $email->created_at = CarbonImmutable::now();

        return $email;
    }

    public function path(): string
    {
        if ($this->created_at === null) {
            throw new RuntimeException('Cannot generate a path before the Email has its ULID and created_at.');
        }

        $date = $this->created_at->format('Ymd');

        return "emails/$date/{$this->ulid}";
    }

    /**
     * @throws FailedToDeleteException
     */
    public function deleteFile(): void
    {
        $path = $this->path();

        if (! storage()->delete($path)) {
            throw FailedToDeleteException::path($path);
        }
    }

    /**
     * Parse the stored raw message, read back as a stream so any configured
     * disk works — a remote disk (S3) re-downloads the message on every call.
     *
     * @throws FailedToReadException
     */
    public function parsedMail(): ParsedMailContract
    {
        $path = $this->path();

        $stream = storage()->readStream($path);

        if (! is_resource($stream)) {
            throw FailedToReadException::path($path);
        }

        return ParsedMail::source($stream, Source::Stream);
    }

    /**
     * @return string[]
     */
    public function mailboxes(bool $includeCcBcc = true): array
    {
        $recipients = $this->parsedMail()->recipients();

        return $includeCcBcc
            ? $recipients->allAddresses()
            : $recipients->toAddresses();
    }

    public function wasSentTo(string $email): bool
    {
        return in_array(
            mb_strtolower($email),
            array_map('mb_strtolower', $this->mailboxes()),
            true
        );
    }
}
