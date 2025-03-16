<?php

namespace Notifiable\ReceiveEmail\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Notifiable\ReceiveEmail\Exceptions\CouldNotDeleteEmail;
use PhpMimeMailParser\Parser;

use function Notifiable\ReceiveEmail\storage;

/**
 * @property string $ulid
 * @property string $message_id
 * @property string $sender_email
 * @property string $sender_name
 * @property CarbonImmutable $created_at
 */
class ReceivedEmail extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $primaryKey = 'ulid';

    protected $fillable = ['ulid', 'message_id', 'sender_email'];

    private Parser $parser;

    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
        ];
    }

    public function getTable(): string
    {
        return Config::string('notifiable.model-table');
    }

    protected static function booted(): void
    {
        static::deleted(function (self $email) {
            $email->deleteFile();
        });
    }

    /**
     * @throws CouldNotDeleteEmail
     */
    public function deleteFile(): void
    {
        $path = $this->path();

        if (! storage()->delete($path)) {
            throw CouldNotDeleteEmail::path($path);
        }
    }

    public function parse(): Parser
    {
        if (! isset($this->parser)) {
            $this->parser = (new Parser)->setPath(
                storage()->path($this->path())
            );
        }

        return $this->parser;
    }

    public function path(): string
    {
        $date = $this->created_at->format('Ymd');

        return "emails/$date/{$this->ulid}";
    }

    /**
     * @return string[]
     */
    public function mailboxes(): array
    {
        /** @var array<string> $addresses */
        $addresses = data_get($this->parse()->getAddresses('to'), '*.address', []);

        return $addresses;
    }

    public function sentTo(string $email): bool
    {
        return in_array($email, $this->mailboxes());
    }

    public function subject(): string
    {
        $subject = $this->parse()->getHeader('Subject');

        return $subject === false ? '' : $subject;
    }
}
