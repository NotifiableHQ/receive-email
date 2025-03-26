<?php

namespace Notifiable\ReceiveEmail\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Config;
use Notifiable\ReceiveEmail\Contracts\ParsedMail;
use Notifiable\ReceiveEmail\Exceptions\FailedToDeleteException;
use Notifiable\ReceiveEmail\ParserParsedMail;
use PhpMimeMailParser\Parser;

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

    private Parser $parser;

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
     * @throws FailedToDeleteException
     */
    public function deleteFile(): void
    {
        $path = $this->path();

        if (! storage()->delete($path)) {
            throw FailedToDeleteException::path($path);
        }
    }

    public function parse(): Parser
    {
        // Add fake parser for testing purposes.
        // instead of (new Parser) call on a parser facade
        // Add wrapper to parser to make it easier to fetch common data like subject, to, and from addresses and names
        // This will also make it easier to swap parsers, like what beyond code is using.
        // Pass the ulid to the parser instead of the path so it's more sensible when faking

        if (! isset($this->parser)) {
            $this->parser = (new Parser)->setPath(
                storage()->path($this->path())
            );
        }

        return $this->parser;
    }

    public function parsedMail(): ParsedMail
    {
        return new ParserParsedMail($this->parse());
    }

    public function path(): string
    {
        $date = $this->created_at->format('Ymd');

        return "emails/$date/{$this->ulid}";
    }

    /**
     * @return string[]
     */
    public function mailboxes(bool $includeCc = true, bool $includeBcc = true): array
    {
        /** @var array<string> $addresses */
        $addresses = $this->parsedMail()->recipients()->toAddresses();

        if ($includeCc) {
            /** @var array<string> ccAddresses */
            $ccAddresses = $this->parsedMail()->recipients()->ccAddresses();

            $addresses = array_merge($addresses, $ccAddresses);
        }

        if ($includeBcc) {
            /** @var array<string> bccAddresses */
            $bccAddresses = $this->parsedMail()->recipients()->bccAddresses();

            $addresses = array_merge($addresses, $bccAddresses);
        }

        return $addresses;
    }

    public function wasSentTo(string $email): bool
    {
        return in_array($email, $this->mailboxes());
    }
}
