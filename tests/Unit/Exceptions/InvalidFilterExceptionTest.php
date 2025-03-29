<?php

use Notifiable\ReceiveEmail\Contracts\EmailFilterContract;
use Notifiable\ReceiveEmail\Exceptions\InvalidFilterException;

it('creates exception with filter class in message', function () {
    $filterClass = 'TestFilter';
    $exception = InvalidFilterException::filter($filterClass);
    
    expect($exception)
        ->toBeInstanceOf(InvalidFilterException::class)
        ->and($exception->getMessage())->toContain($filterClass)
        ->and($exception->getMessage())->toContain(EmailFilterContract::class);
}); 