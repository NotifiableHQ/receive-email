<?php

namespace Notifiable\ReceiveEmail\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Config;
use Notifiable\ReceiveEmail\Contracts\ParsedMailContract;
use Notifiable\ReceiveEmail\Enums\Source;
use Notifiable\ReceiveEmail\Exceptions\FailedToDeleteException;
use Notifiable\ReceiveEmail\Facades\ParsedMail;

use function Notifiable\ReceiveEmail\storage;

/**
 * @property string $ulid
 * @property string $message_id
 * @property-read  Sender $sender
 * @property CarbonImmutable $sent_at
 * @property CarbonImmutable $created_at
 */
class Email extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $primaryKey = 'ulid';

    protected $guarded = ['ulid', 'created_at'];

    protected $with = ['sender'];

    protected $casts = [
        'sent_at' => 'immutable_datetime',
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

    public function path(): string
    {
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

    public function parsedMail(): ParsedMailContract
    {
        return ParsedMail::source($this->path(), Source::Path);
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
            array_map('mb_strtolower', $this->mailboxes())
        );
    }
}
