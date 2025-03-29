<?php

namespace Notifiable\ReceiveEmail\Support\Testing;

use Carbon\CarbonImmutable;
use Illuminate\Support\Testing\Fakes\Fake;
use Notifiable\ReceiveEmail\Contracts\ParsedMailContract;
use Notifiable\ReceiveEmail\Data\Address;
use Notifiable\ReceiveEmail\Data\Mail;
use Notifiable\ReceiveEmail\Data\Recipients;
use Notifiable\ReceiveEmail\Enums\Source;

class FakeParsedMail implements Fake, ParsedMailContract
{
    /**
     * @var array<string, mixed>
     */
    private array $fakeData = [];

    /**
     * @param  array<string, mixed>  $data
     */
    public function fake(array $data): self
    {
        $this->fakeData = $data;

        return $this;
    }

    /**
     * @param  string|resource  $source
     */
    public function source($source, Source $type = Source::Stream): ParsedMailContract
    {
        return $this;
    }

    public function store(string $path): bool
    {
        return $this->fakeData['stored'] ?? false;
    }

    public function id(): string
    {
        return $this->fakeData['id'] ?? 'fake-message-id-'.uniqid();
    }

    public function date(): CarbonImmutable
    {
        $date = $this->fakeData['date'] ?? CarbonImmutable::now()->utc();

        return is_string($date)
            ? CarbonImmutable::parse($date)->utc()
            : $date;
    }

    public function sender(): Address
    {
        $sender = $this->fakeData['sender']
            ?? $this->fakeData['from']
            ?? Address::from(['display' => fake()->name(), 'address' => fake()->safeEmail()]);

        return $sender instanceof Address
            ? $sender
            : Address::from($sender);
    }

    /**
     * @return Address[]
     */
    public function to(): array
    {
        $to = $this->fakeData['to'] ?? [];

        if ($to === []) {
            return [];
        }

        return $to[0] instanceof Address
            ? $to
            : Address::fromMany($to);
    }

    /**
     * @return Address[]
     */
    public function cc(): array
    {
        $cc = $this->fakeData['cc'] ?? [];

        if ($cc === []) {
            return [];
        }

        return $cc[0] instanceof Address
            ? $cc
            : Address::fromMany($cc);
    }

    /**
     * @return Address[]
     */
    public function bcc(): array
    {
        $bcc = $this->fakeData['bcc'] ?? [];

        if ($bcc === []) {
            return [];
        }

        return $bcc[0] instanceof Address
            ? $bcc
            : Address::fromMany($bcc);
    }

    public function recipients(): Recipients
    {
        return $this->fakeData['recipients']
            ?? new Recipients($this->to(), $this->cc(), $this->bcc());
    }

    public function subject(): ?string
    {
        return $this->fakeData['subject'] ?? null;
    }

    public function text(): ?string
    {
        return $this->fakeData['text'] ?? null;
    }

    public function html(): ?string
    {
        return $this->fakeData['html'] ?? null;
    }

    public function toMail(): Mail
    {
        return $this->fakeData['mail'] ?? new Mail(
            $this->id(),
            $this->date(),
            $this->sender(),
            $this->recipients(),
            $this->subject(),
            $this->text(),
            $this->html()
        );
    }

    public function getHeaderOrFail(string $key): string
    {
        return $this->fakeData['header'][$key] ?? '';
    }

    public function getHeader(string $key): ?string
    {
        return $this->fakeData['header'][$key] ?? null;
    }
}
