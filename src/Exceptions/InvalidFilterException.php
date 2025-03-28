<?php

namespace Notifiable\ReceiveEmail\Exceptions;

use Exception;
use Notifiable\ReceiveEmail\Contracts\EmailFilterContract;

class InvalidFilterException extends Exception
{
    public static function filter(string $class): InvalidFilterException
    {
        return new InvalidFilterException("[{$class}] does not implement ".EmailFilterContract::class);
    }
}
