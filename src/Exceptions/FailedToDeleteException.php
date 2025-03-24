<?php

namespace Notifiable\ReceiveEmail\Exceptions;

use Exception;

class FailedToDeleteException extends Exception
{
    public static function path(string $path): FailedToDeleteException
    {
        return new FailedToDeleteException("Failed to delete: {$path}");
    }
}
