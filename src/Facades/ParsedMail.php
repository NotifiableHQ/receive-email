<?php

namespace Notifiable\ReceiveEmail\Facades;

use Illuminate\Support\Facades\Facade;
use Notifiable\ReceiveEmail\Contracts\ParsedMailContract;
use Notifiable\ReceiveEmail\Support\Testing\FakeParsedMail;

/**
 * @mixin ParsedMailContract
 */
class ParsedMail extends Facade
{
    protected static function getFacadeAccessor()
    {
        return static::class;
    }

    /**
     * @param  array<mixed>  $data
     */
    public static function fake(array $data = []): ParsedMailContract
    {
        $fakeParsedMail = (new FakeParsedMail)->fake($data);

        static::swap($fakeParsedMail);

        return $fakeParsedMail;
    }
}
