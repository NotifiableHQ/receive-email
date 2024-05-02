<?php

namespace Notifiable\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use PhpMimeMailParser\Parser;

/**
 * @property string $ulid
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon $deleted_at
 */
class ReceivedEmail extends Model
{
    protected $fillable = ['ulid'];

    public function parse(): Parser
    {
        $path = storage_path("app/{$this->path()}");
        $parser = new Parser();
        $parser->setPath($path);

        return $parser;
    }

    public function path(): string
    {
        $date = $this->created_at->format('Ymd');

        return "emails/$date/{$this->ulid}";
    }
}
