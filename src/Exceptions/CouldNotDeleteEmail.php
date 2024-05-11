<?php

namespace Notifiable\ReceiveEmail\Exceptions;

use Exception;

class CouldNotDeleteEmail extends Exception
{
    public static function path(string $path): self
    {
        return new CouldNotDeleteEmail("Could not delete email: `{$path}`");
    }
}
