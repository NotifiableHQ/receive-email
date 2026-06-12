<?php

namespace Notifiable\ReceiveEmail\Exceptions;

use Exception;

class FailedToStoreException extends Exception
{
    public static function path(string $path): FailedToStoreException
    {
        return new FailedToStoreException("Failed to store: {$path}");
    }
}
