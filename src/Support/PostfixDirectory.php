<?php

namespace Notifiable\ReceiveEmail\Support;

/**
 * Locates the Postfix configuration directory. The path is static state so
 * tests can point the setup and sync commands at a fixture directory.
 */
final class PostfixDirectory
{
    public const DEFAULT = '/etc/postfix';

    public static string $path = self::DEFAULT;

    public static function path(string $file = ''): string
    {
        return $file === '' ? self::$path : self::$path.'/'.$file;
    }
}
