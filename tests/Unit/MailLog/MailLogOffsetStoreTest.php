<?php

use Notifiable\ReceiveEmail\MailLog\MailLogOffsetStore;
use Notifiable\ReceiveEmail\MailLog\MailLogPosition;

beforeEach(function () {
    $this->directory = sys_get_temp_dir().'/offset-store-'.uniqid();
    $this->path = $this->directory.'/offset.json';
});

afterEach(function () {
    @chmod($this->directory, 0755);
    @unlink($this->path);
    @unlink($this->path.'.tmp');
    @rmdir($this->directory);
    @unlink($this->directory);
});

it('round-trips a position and creates the offset directory', function () {
    $store = new MailLogOffsetStore($this->path);

    $store->put(new MailLogPosition(12345, 678));

    $position = $store->get();

    expect($position)->not->toBeNull()
        ->and($position->inode)->toBe(12345)
        ->and($position->offset)->toBe(678);
});

it('returns null when the state file is missing', function () {
    expect((new MailLogOffsetStore($this->path))->get())->toBeNull();
});

it('returns null when the state file is corrupt', function ($contents) {
    mkdir($this->directory, 0755, true);
    file_put_contents($this->path, $contents);

    expect((new MailLogOffsetStore($this->path))->get())->toBeNull();
})->with([
    'not json',
    '{"inode": 123, "offset":',
    '{"inode": 123}',
    '{"inode": 123, "offset": "42"}',
]);

it('replaces the state in place and leaves no temporary file behind', function () {
    $store = new MailLogOffsetStore($this->path);

    $store->put(new MailLogPosition(111, 100));
    $store->put(new MailLogPosition(222, 200));

    $position = $store->get();

    expect($position->inode)->toBe(222)
        ->and($position->offset)->toBe(200)
        ->and(is_file($this->path.'.tmp'))->toBeFalse();
});

it('throws when the offset directory cannot be created', function () {
    // A file where the directory should be makes mkdir fail.
    file_put_contents($this->directory, 'not a directory');

    $store = new MailLogOffsetStore($this->path);

    expect(fn () => $store->put(new MailLogPosition(123, 456)))
        ->toThrow(RuntimeException::class, 'Could not create offset directory');
});

it('throws and keeps the previous state when the offset file cannot be written', function () {
    $store = new MailLogOffsetStore($this->path);

    $store->put(new MailLogPosition(111, 100));

    chmod($this->directory, 0555);

    expect(fn () => $store->put(new MailLogPosition(222, 200)))
        ->toThrow(RuntimeException::class);

    chmod($this->directory, 0755);

    $position = $store->get();

    expect($position->inode)->toBe(111)
        ->and($position->offset)->toBe(100);
});
