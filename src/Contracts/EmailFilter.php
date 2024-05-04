<?php

namespace Notifiable\Contracts;

use PhpMimeMailParser\Parser;

interface EmailFilter
{
    public function filter(Parser $email): bool;
}
