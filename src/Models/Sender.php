<?php

namespace Notifiable\ReceiveEmail\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Config;

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
