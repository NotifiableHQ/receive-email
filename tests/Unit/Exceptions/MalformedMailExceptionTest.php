<?php

use Notifiable\ReceiveEmail\Exceptions\MalformedMailException;

it('creates exception for missing header', function () {
    $headerKey = 'message-id';
    $exception = MalformedMailException::missingHeader($headerKey);
    
    expect($exception)
        ->toBeInstanceOf(MalformedMailException::class)
        ->and($exception->getMessage())->toContain($headerKey)
        ->and($exception->getMessage())->toContain('header is missing');
});

it('creates exception for missing sender', function () {
    $exception = MalformedMailException::missingSender();
    
    expect($exception)
        ->toBeInstanceOf(MalformedMailException::class)
        ->and($exception->getMessage())->toContain('Missing sender email address');
});

it('creates exception for missing recipient', function () {
    $exception = MalformedMailException::missingRecipient();
    
    expect($exception)
        ->toBeInstanceOf(MalformedMailException::class)
        ->and($exception->getMessage())->toContain('Missing recipient email address');
}); 