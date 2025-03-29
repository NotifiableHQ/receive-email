<?php

use Notifiable\ReceiveEmail\Facades\ParsedMail;
use Notifiable\ReceiveEmail\Support\Testing\FakeParsedMail;

it('can be faked for testing', function () {
    // Fake the facade
    $fakeParsedMail = ParsedMail::fake([
        'subject' => 'Fake Subject',
    ]);

    // Assert the fake is returned
    expect($fakeParsedMail)
        ->toBeInstanceOf(FakeParsedMail::class)
        ->and($fakeParsedMail->subject())->toBe('Fake Subject');

    // Assert the facade is using the fake
    expect(ParsedMail::subject())->toBe('Fake Subject');
});
