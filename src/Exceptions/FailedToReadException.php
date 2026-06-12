<?php

namespace Notifiable\ReceiveEmail\Exceptions;

use Exception;

class FailedToReadException extends Exception
{
    public static function path(string $path): FailedToReadException
    {
        return new FailedToReadException("Failed to read: {$path}");
    }
}
