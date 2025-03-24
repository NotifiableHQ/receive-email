<?php

namespace Notifiable\ReceiveEmail\Exceptions;

use Exception;

class MalformedEmailException extends Exception
{
    public static function missingHeader(string $key): self
    {
        return new static("[{$key}] header is missing.");
    }

    public static function missingFromAddress()
    {
        return new static('Missing from email address.');
    }

    public static function missingRecipient()
    {
        return new static('Missing recipient email address.');
    }
}
