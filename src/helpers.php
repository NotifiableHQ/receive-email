<?php

namespace Notifiable\ReceiveEmail;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

function storage(): Filesystem
{
    /** @var ?string $disk */
    $disk = config('notifiable.storage-disk');

    return Storage::disk($disk);
}
