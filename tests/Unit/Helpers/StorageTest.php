<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

use function Notifiable\ReceiveEmail\storage;

it('returns the configured disk', function () {
    // Setup
    $diskName = 'test-disk';
    $testDisk = Storage::fake($diskName);

    Config::set('receive_email.storage-disk', $diskName);

    // Assert
    expect(storage())->toBe($testDisk);
});
