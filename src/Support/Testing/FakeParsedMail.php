<?php

namespace Notifiable\ReceiveEmail\Support\Testing;

use Carbon\CarbonImmutable;
use Notifiable\ReceiveEmail\Contracts\ParsedMailContract;
use Notifiable\ReceiveEmail\Data\Address;
use Notifiable\ReceiveEmail\Data\Mail;
use Notifiable\ReceiveEmail\Data\Recipients;
use Notifiable\ReceiveEmail\Enums\Source;

class FakeParsedMail implements ParsedMailContract
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
    public static function source($source, Source $type = Source::Stream): ParsedMailContract
    {
        return new FakeParsedMail;
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
        $date = $this->fakeData['date'] ?? null;

        if ($date === null) {
            return CarbonImmutable::now()->utc();
        }

        return is_string($date)
            ? CarbonImmutable::parse($date)->utc()
            : $date;
    }

    public function sender(): Address
    {
        $sender = $this->fakeData['sender'] ?? $this->fakeData['from'] ?? null;

        if ($sender === null) {
            return Address::from(['display' => 'Fake Sender', 'address' => 'fake@example.com']);
        }

        return $sender instanceof Address
            ? $sender
            : Address::from($sender);
    }

    /**
     * @return Address[]|null
     */
    public function to(): ?array
    {
        if (! isset($this->fakeData['to'])) {
            return [];
        }

        $to = $this->fakeData['to'];

        if ($to === []) {
            return [];
        }

        return $to[0] instanceof Address
            ? $to
            : Address::fromMany($to);
    }

    /**
     * @return Address[]|null
     */
    public function cc(): ?array
    {
        if (! isset($this->fakeData['cc'])) {
            return [];
        }

        $cc = $this->fakeData['cc'];

        if ($cc === []) {
            return [];
        }

        return $cc[0] instanceof Address
            ? $cc
            : Address::fromMany($cc);
    }

    /**
     * @return Address[]|null
     */
    public function bcc(): ?array
    {
        if (! isset($this->fakeData['bcc'])) {
            return [];
        }

        $bcc = $this->fakeData['bcc'];

        if ($bcc === []) {
            return [];
        }

        return $bcc[0] instanceof Address
            ? $bcc
            : Address::fromMany($bcc);
    }

    public function recipients(): Recipients
    {
        $recipients = $this->fakeData['recipients'] ?? null;

        if ($recipients instanceof Recipients) {
            return $recipients;
        }

        return new Recipients($this->to() ?? [], $this->cc() ?? [], $this->bcc() ?? []);
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
        $mail = $this->fakeData['mail'] ?? null;

        if ($mail instanceof Mail) {
            return $mail;
        }

        return new Mail(
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
