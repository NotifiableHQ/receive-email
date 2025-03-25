<?php

namespace Notifiable\ReceiveEmail\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Config;

/**
 * @property string $ulid
 * @property string $address
 * @property string $display
 * @property-read Collection<int, Email> $emails
 * @property CarbonImmutable $updated_at
 * @property CarbonImmutable $created_at
 */
class Sender extends Model
{
    use HasUlids;

    protected $primaryKey = 'ulid';

    protected $guarded = ['ulid', 'created_at'];

    protected $casts = [
        'updated_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
    ];

    public function getTable(): string
    {
        return Config::string('receive_email.sender-table');
    }

    /** @return HasMany<Email, $this> */
    public function emails(): HasMany
    {
        return $this->hasMany(Email::class);
    }
}
