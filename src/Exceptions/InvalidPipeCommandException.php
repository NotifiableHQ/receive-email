<?php

namespace Notifiable\ReceiveEmail\Exceptions;

use Exception;
use Notifiable\ReceiveEmail\Contracts\PipeCommandContract;

class InvalidPipeCommandException extends Exception
{
    public static function invalidClass(string $class): self
    {
        return new self("[{$class}] does not implement ".PipeCommandContract::class);
    }
}
