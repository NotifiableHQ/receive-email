<?php

use Notifiable\ReceiveEmail\Exceptions\InvalidPipeCommandException;

it('can be instantiated', function () {
    $exception = new InvalidPipeCommandException();
    
    expect($exception)->toBeInstanceOf(InvalidPipeCommandException::class);
}); 