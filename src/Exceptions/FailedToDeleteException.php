<?php

namespace Notifiable\ReceiveEmail\Exceptions;

use Exception;

class FailedToDeleteException extends Exception
{
    public static function path(string $path): self
    {
        return new FailedToDeleteException("Failed to delete: {$path}");
    }
}
