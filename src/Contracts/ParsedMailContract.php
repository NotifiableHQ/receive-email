<?php

namespace Notifiable\ReceiveEmail\Contracts;

use Carbon\CarbonImmutable;
use Notifiable\ReceiveEmail\Data\Address;
use Notifiable\ReceiveEmail\Data\Mail;
use Notifiable\ReceiveEmail\Data\Recipients;
use Notifiable\ReceiveEmail\Enums\Source;

interface ParsedMailContract
{
    /**
     * @param  string|resource  $source
     */
    public function source($source, Source $type = Source::Stream): ParsedMailContract;

    public function store(string $path): bool;

    public function id(): string;

    public function date(): CarbonImmutable;

    public function sender(): Address;

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

    public function getHeaderOrFail(string $key): string;

    public function getHeader(string $key): ?string;
}
