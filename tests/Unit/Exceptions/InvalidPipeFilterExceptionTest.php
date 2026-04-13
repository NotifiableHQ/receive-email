<?php

use Notifiable\ReceiveEmail\Contracts\PipeFilterContract;
use Notifiable\ReceiveEmail\Exceptions\InvalidPipeFilterException;

it('can be instantiated', function () {
    $exception = new InvalidPipeFilterException;

    expect($exception)
        ->toBeInstanceOf(InvalidPipeFilterException::class)
        ->and($exception->getMessage())->toBe('');
});

it('can be instantiated with a message', function () {
    $message = 'Custom exception message';
    $exception = new InvalidPipeFilterException($message);

    expect($exception)
        ->toBeInstanceOf(InvalidPipeFilterException::class)
        ->and($exception->getMessage())->toBe($message);
});

it('creates exception with class name via factory method', function () {
    $class = 'App\Filters\CustomFilter';
    $exception = InvalidPipeFilterException::invalidClass($class);

    expect($exception)
        ->toBeInstanceOf(InvalidPipeFilterException::class)
        ->and($exception->getMessage())->toContain($class)
        ->and($exception->getMessage())->toContain(PipeFilterContract::class);
});
