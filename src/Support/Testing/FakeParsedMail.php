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
    private array $fakeData;

    /**
     * @param  array<string, mixed>  $data
     */
    public function fake(array $data): self
    {
        $this->fakeData = $data;

        return $this;
    }

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
        return $this->fakeData['id'];
    }

    public function date(): CarbonImmutable
    {
        $date = $this->fakeData['date'];

        return is_string($date)
            ? CarbonImmutable::parse($date)->utc()
            : $date;
    }

    public function sender(): Address
    {
        $sender = $this->fakeData['sender'] ?? $this->fakeData['from'];

        return $sender instanceof Address
            ? $sender
            : Address::from($sender);
    }

    /**
     * @return Address[]
     */
    public function to(): array
    {
        $to = $this->fakeData['to'];

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
        $cc = $this->fakeData['cc'];

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
        $recipients = $this->fakeData['recipients'];

        return $recipients instanceof Recipients
            ? $recipients
            : new Recipients($this->to(), $this->cc(), $this->bcc());
    }

    public function subject(): ?string
    {
        return $this->fakeData['subject'];
    }

    public function text(): ?string
    {
        return $this->fakeData['text'];
    }

    public function html(): ?string
    {
        return $this->fakeData['html'];
    }

    public function toMail(): Mail
    {
        $mail = $this->fakeData['mail'];

        return $mail instanceof Mail
            ? $mail
            : new Mail(
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
