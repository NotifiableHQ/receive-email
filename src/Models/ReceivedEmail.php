<?php

namespace Notifiable\ReceiveEmail\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Notifiable\ReceiveEmail\Exceptions\CouldNotDeleteEmail;
use PhpMimeMailParser\Parser;

use function Notifiable\ReceiveEmail\storage;

/**
 * @property string $ulid
 * @property bool $is_read
 * @property ?Carbon $read_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property ?Carbon $deleted_at
 */
class ReceivedEmail extends Model
{
    use HasUlids;

    protected $primaryKey = 'ulid';

    protected $fillable = ['ulid', 'message_id', 'mailbox'];

    private Parser $parser;

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
            $this->parser = (new Parser())->setPath(
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
}
