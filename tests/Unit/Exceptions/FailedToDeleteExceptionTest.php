<?php

use Notifiable\ReceiveEmail\Exceptions\FailedToDeleteException;

it('creates exception with path in message', function () {
    $path = 'emails/test.eml';
    $exception = FailedToDeleteException::path($path);

    expect($exception)
        ->toBeInstanceOf(FailedToDeleteException::class)
        ->and($exception->getMessage())->toContain($path)
        ->and($exception->getMessage())->toContain('Failed to delete');
});
