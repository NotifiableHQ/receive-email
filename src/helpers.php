<?php

namespace Notifiable\ReceiveEmail;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

function storage(): Filesystem
{
    return Storage::disk(Config::string('receive_email.storage-disk'));
}
