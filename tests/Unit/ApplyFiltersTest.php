<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Notifiable\ReceiveEmail\ApplyFilters;
use Notifiable\ReceiveEmail\Contracts\EmailFilterContract;
use Notifiable\ReceiveEmail\Contracts\ParsedMailContract;
use Notifiable\ReceiveEmail\Events\EmailRejected;
use Notifiable\ReceiveEmail\Exceptions\InvalidFilterException;
use Notifiable\ReceiveEmail\Facades\ParsedMail;
use Notifiable\ReceiveEmail\Filters\SenderAddressBlacklistFilter;
use Notifiable\ReceiveEmail\Filters\SenderAddressWhitelistFilter;
use Notifiable\ReceiveEmail\Filters\SenderDomainBlacklistFilter;
use Notifiable\ReceiveEmail\Filters\SenderDomainWhitelistFilter;

beforeEach(function () {
    Event::fake();
});

it('passes when no filters are configured', function () {
    // Configure no filters
    Config::set('receive_email.email-filters', []);

    // Set up ParsedMail
    ParsedMail::fake([
        'to' => [['address' => 'test@example.com', 'display' => 'Test User']],
    ]);

    $applyFilters = new ApplyFilters;
    $result = $applyFilters->handle(ParsedMail::getFacadeRoot());

    expect($result)->toBeTrue();
    Event::assertNotDispatched(EmailRejected::class);
});

it('passes when all filters pass', function () {
    // Create a mock filter that passes
    $mockFilter = new class implements EmailFilterContract
    {
        public function filter(ParsedMailContract $parsedMail): bool
        {
            return true;
        }
    };

    // Register the mock filter
    $filterClass = get_class($mockFilter);
    app()->instance($filterClass, $mockFilter);
    Config::set('receive_email.email-filters', [$filterClass]);

    // Set up ParsedMail
    ParsedMail::fake([
        'to' => [['address' => 'test@example.com', 'display' => 'Test User']],
    ]);

    $applyFilters = new ApplyFilters;
    $result = $applyFilters->handle(ParsedMail::getFacadeRoot());

    expect($result)->toBeTrue();
    Event::assertNotDispatched(EmailRejected::class);
});

it('fails when a filter fails', function () {
    // Create a mock filter that fails
    $mockFilter = new class implements EmailFilterContract
    {
        public function filter(ParsedMailContract $parsedMail): bool
        {
            return false;
        }
    };

    // Register the mock filter
    $filterClass = get_class($mockFilter);
    app()->instance($filterClass, $mockFilter);
    Config::set('receive_email.email-filters', [$filterClass]);

    // Set up ParsedMail
    $fakeMail = ParsedMail::fake([
        'subject' => 'Test Email',
        'to' => [['address' => 'test@example.com', 'display' => 'Test User']],
    ]);

    $applyFilters = new ApplyFilters;
    $result = $applyFilters->handle($fakeMail);

    expect($result)->toBeFalse();
    Event::assertDispatched(function (EmailRejected $event) use ($filterClass) {
        return $event->filterClass === $filterClass
            && $event->mail->subject === 'Test Email';
    });
});

it('stops at the first failing filter', function () {
    // Create a mock filter that fails
    $failingFilter = new class implements EmailFilterContract
    {
        public function filter(ParsedMailContract $parsedMail): bool
        {
            return false;
        }
    };

    // Create a mock filter that would pass
    $passingFilter = new class implements EmailFilterContract
    {
        public function filter(ParsedMailContract $parsedMail): bool
        {
            return true;
        }
    };

    // Register the filters
    $failingClass = get_class($failingFilter);
    $passingClass = get_class($passingFilter);
    app()->instance($failingClass, $failingFilter);
    app()->instance($passingClass, $passingFilter);

    // Configure failing filter first, then passing filter
    Config::set('receive_email.email-filters', [$failingClass, $passingClass]);

    // Set up ParsedMail
    $fakeMail = ParsedMail::fake([
        'to' => [['address' => 'test@example.com', 'display' => 'Test User']],
    ]);

    $applyFilters = new ApplyFilters;
    $result = $applyFilters->handle($fakeMail);

    expect($result)->toBeFalse();
    Event::assertDispatched(EmailRejected::class, 1);
    Event::assertDispatched(function (EmailRejected $event) use ($failingClass) {
        return $event->filterClass === $failingClass;
    });
});

it('skips the built-in list filters in the pipe', function () {
    Config::set('receive_email.email-filters', [
        SenderDomainWhitelistFilter::class,
        SenderDomainBlacklistFilter::class,
        SenderAddressWhitelistFilter::class,
        SenderAddressBlacklistFilter::class,
    ]);

    // Every built-in filter would reject this sender if it were evaluated:
    // the address is blacklisted and no whitelist matches it.
    Config::set('receive_email.sender-address-blacklist', ['blocked@example.com']);
    Config::set('receive_email.sender-domain-blacklist', ['example.com']);
    Config::set('receive_email.sender-address-whitelist', ['other@trusted.test']);
    Config::set('receive_email.sender-domain-whitelist', ['trusted.test']);

    $fakeMail = ParsedMail::fake([
        'sender' => ['address' => 'blocked@example.com', 'display' => 'Blocked Sender'],
        'to' => [['address' => 'test@example.com', 'display' => 'Test User']],
    ]);

    $applyFilters = new ApplyFilters;

    expect($applyFilters->handle($fakeMail))->toBeTrue();
    Event::assertNotDispatched(EmailRejected::class);
});

it('still runs custom filters when built-in filters are also configured', function () {
    $customFilter = new class implements EmailFilterContract
    {
        public function filter(ParsedMailContract $parsedMail): bool
        {
            return false;
        }
    };

    $customClass = get_class($customFilter);
    app()->instance($customClass, $customFilter);

    Config::set('receive_email.email-filters', [
        SenderAddressBlacklistFilter::class,
        $customClass,
    ]);

    $fakeMail = ParsedMail::fake([
        'to' => [['address' => 'test@example.com', 'display' => 'Test User']],
    ]);

    $applyFilters = new ApplyFilters;

    expect($applyFilters->handle($fakeMail))->toBeFalse();
    Event::assertDispatched(function (EmailRejected $event) use ($customClass) {
        return $event->filterClass === $customClass;
    });
});

it('throws exception when filter is invalid', function () {
    // Create a class that does not implement EmailFilterContract
    $invalidFilter = new class {};

    // Register the invalid filter
    $invalidClass = get_class($invalidFilter);
    app()->instance($invalidClass, $invalidFilter);
    Config::set('receive_email.email-filters', [$invalidClass]);

    // Set up ParsedMail
    ParsedMail::fake([
        'to' => [['address' => 'test@example.com', 'display' => 'Test User']],
    ]);

    $applyFilters = new ApplyFilters;

    expect(fn () => $applyFilters->handle(ParsedMail::getFacadeRoot()))
        ->toThrow(InvalidFilterException::class);
});
