<?php

namespace Notifiable\ReceiveEmail\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use PhpMimeMailParser\Parser;

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
    use SoftDeletes;

    protected $fillable = ['ulid'];

    protected static function booted(): void
    {
        static::forceDeleted(function (self $email) {
            $email->deleteFile();
        });
    }

    public function deleteFile(): void
    {
        $file = $this->path();

        if (! Storage::delete($file)) {
            throw new \RuntimeException("Could not delete email: {$file}");
        }
    }

    public function parse(): Parser
    {
        $path = storage_path("app/{$this->path()}");
        $parser = new Parser();
        $parser->setPath($path);

        return $parser;
    }

    public function getIsReadAttribute(): bool
    {
        return $this->read_at !== null;
    }

    public function markAsRead(): self
    {
        $this->read_at = now();

        if (! $this->save()) {
            throw new \RuntimeException('Could not save read_at timestamp.');
        }

        return $this;
    }

    public function path(): string
    {
        $date = $this->created_at->format('Ymd');

        return "emails/$date/{$this->ulid}";
    }
}
