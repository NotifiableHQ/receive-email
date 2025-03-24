<?php

namespace Notifiable\ReceiveEmail\Exceptions;

use Exception;
use Notifiable\ReceiveEmail\Contracts\EmailFilter;

class InvalidFilterException extends Exception
{
    public static function filter(string $class): self
    {
        return new InvalidFilterException("[{$class}] does not implement ".EmailFilter::class);
    }
}
