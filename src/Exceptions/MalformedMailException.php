<?php

namespace Notifiable\ReceiveEmail\Exceptions;

use Exception;

class MalformedMailException extends Exception
{
    public static function missingHeader(string $key): MalformedMailException
    {
        return new MalformedMailException("[{$key}] header is missing.");
    }

    public static function invalidHeader(string $key): MalformedMailException
    {
        return new MalformedMailException("[{$key}] header cannot be parsed.");
    }

    public static function missingSender(): MalformedMailException
    {
        return new MalformedMailException('Missing sender email address.');
    }

    public static function invalidSender(): MalformedMailException
    {
        return new MalformedMailException('Sender email address cannot be parsed.');
    }

    public static function oversizedHeader(string $key): MalformedMailException
    {
        return new MalformedMailException("[{$key}] header is too long to store.");
    }

    public static function unstorableDate(): MalformedMailException
    {
        return new MalformedMailException('[date] header is outside the storable timestamp range.');
    }

    public static function missingRecipient(): MalformedMailException
    {
        return new MalformedMailException('Missing recipient email address.');
    }
}
