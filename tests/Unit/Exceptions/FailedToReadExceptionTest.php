<?php

use Notifiable\ReceiveEmail\Exceptions\FailedToReadException;

it('creates exception with path in message', function () {
    $path = 'emails/test.eml';
    $exception = FailedToReadException::path($path);

    expect($exception)
        ->toBeInstanceOf(FailedToReadException::class)
        ->and($exception->getMessage())->toContain($path)
        ->and($exception->getMessage())->toContain('Failed to read');
});
