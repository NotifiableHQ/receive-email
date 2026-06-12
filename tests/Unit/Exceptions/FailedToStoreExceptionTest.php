<?php

use Notifiable\ReceiveEmail\Exceptions\FailedToStoreException;

it('creates exception with path in message', function () {
    $path = 'emails/test.eml';
    $exception = FailedToStoreException::path($path);

    expect($exception)
        ->toBeInstanceOf(FailedToStoreException::class)
        ->and($exception->getMessage())->toContain($path)
        ->and($exception->getMessage())->toContain('Failed to store');
});
