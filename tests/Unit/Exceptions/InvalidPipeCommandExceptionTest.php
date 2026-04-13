<?php

use Notifiable\ReceiveEmail\Contracts\PipeCommandContract;
use Notifiable\ReceiveEmail\Exceptions\InvalidPipeCommandException;

it('can be instantiated', function () {
    $exception = new InvalidPipeCommandException;

    expect($exception)->toBeInstanceOf(InvalidPipeCommandException::class);
});

it('creates exception with class name via factory method', function () {
    $class = 'App\Commands\CustomCommand';
    $exception = InvalidPipeCommandException::invalidClass($class);

    expect($exception)
        ->toBeInstanceOf(InvalidPipeCommandException::class)
        ->and($exception->getMessage())->toContain($class)
        ->and($exception->getMessage())->toContain(PipeCommandContract::class);
});
