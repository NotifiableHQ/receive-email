<?php

namespace Notifiable\ReceiveEmail\Facades;

use Illuminate\Support\Facades\Facade;
use Notifiable\ReceiveEmail\Support\Testing\FakeParsedMail;

/**
 * @mixin \Notifiable\ReceiveEmail\Contracts\ParsedMail
 */
class ParsedMail extends Facade
{
    protected static function getFacadeAccessor()
    {
        return static::class;
    }

    public static function fake(): FakeParsedMail
    {
        $fakeParsedMail = new FakeParsedMail;

        static::swap($fakeParsedMail);

        return $fakeParsedMail;
    }
}
