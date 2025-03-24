<?php

namespace Notifiable\ReceiveEmail\Contracts;

use Carbon\CarbonImmutable;
use Notifiable\ReceiveEmail\Data\Address;
use Notifiable\ReceiveEmail\Data\Mail;
use Notifiable\ReceiveEmail\Data\Recipients;
use PhpMimeMailParser\Parser;

interface ParsedMail
{
    public function parser(Parser $parser): ParsedMail;

    public function getParser(): Parser;

    public function id(): string;

    public function date(): CarbonImmutable;

    public function from(): Address;

    /**
     * @return Address[]
     */
    public function to(): ?array;

    /**
     * @return Address[]
     */
    public function cc(): ?array;

    /**
     * @return Address[]
     */
    public function bcc(): ?array;

    public function recipients(): Recipients;

    public function subject(): ?string;

    public function text(): ?string;

    public function html(): ?string;

    public function toMail(): Mail;
}
