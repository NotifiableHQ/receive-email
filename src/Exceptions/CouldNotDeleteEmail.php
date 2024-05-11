<?php

namespace Notifiable\ReceiveEmail\Exceptions;

use Exception;

class CouldNotDeleteEmail extends Exception
{
    public static function file(string $path): self
    {
        return new CouldNotDeleteEmail("Could not delete email file: `{$path}`");
    }
}
