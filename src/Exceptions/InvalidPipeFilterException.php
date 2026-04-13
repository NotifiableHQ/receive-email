<?php

namespace Notifiable\ReceiveEmail\Exceptions;

use Exception;
use Notifiable\ReceiveEmail\Contracts\PipeFilterContract;

class InvalidPipeFilterException extends Exception
{
    public static function invalidClass(string $class): self
    {
        return new self("[{$class}] does not implement ".PipeFilterContract::class);
    }
}
