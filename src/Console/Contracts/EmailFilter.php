<?php

namespace Notifiable\Console\Contracts;

use PhpMimeMailParser\Parser;

interface EmailFilter
{
    public function filter(Parser $email): bool;
}
