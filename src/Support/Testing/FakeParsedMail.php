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
        $isStored = $this->fakeData['stored'] ?? false;

        return is_callable($isStored) ? $isStored() : $isStored;
    }

    public function id(): string
    {
        $id = $this->fakeData['id'] ?? 'fake-message-id-'.uniqid();

        return is_callable($id) ? $id() : $id;
    }

    public function date(): CarbonImmutable
    {
        $date = $this->fakeData['date'] ?? CarbonImmutable::now()->utc();

        $date = is_callable($date) ? $date() : $date;

        return is_string($date)
            ? CarbonImmutable::parse($date)->utc()
            : $date;
    }

    public function sender(): Address
    {
        $sender = $this->fakeData['sender']
            ?? $this->fakeData['from']
            ?? Address::from(['display' => fake()->name(), 'address' => fake()->safeEmail()]);

        $sender = is_callable($sender) ? $sender() : $sender;

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

        $to = is_callable($to) ? $to() : $to;

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

        $cc = is_callable($cc) ? $cc() : $cc;

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

        $bcc = is_callable($bcc) ? $bcc() : $bcc;

        if ($bcc === []) {
            return [];
        }

        return $bcc[0] instanceof Address
            ? $bcc
            : Address::fromMany($bcc);
    }

    public function recipients(): Recipients
    {
        $recipients = $this->fakeData['recipients']
            ?? new Recipients($this->to(), $this->cc(), $this->bcc());

        return is_callable($recipients) ? $recipients() : $recipients;
    }

    public function subject(): ?string
    {
        $subject = $this->fakeData['subject'] ?? null;

        return is_callable($subject) ? $subject() : $subject;
    }

    public function text(): ?string
    {
        $text = $this->fakeData['text'] ?? null;

        return is_callable($text) ? $text() : $text;
    }

    public function html(): ?string
    {
        $html = $this->fakeData['html'] ?? null;

        return is_callable($html) ? $html() : $html;
    }

    public function toMail(): Mail
    {
        $mail = $this->fakeData['mail'] ?? new Mail(
            $this->id(),
            $this->date(),
            $this->sender(),
            $this->recipients(),
            $this->subject(),
            $this->text(),
            $this->html()
        );

        return is_callable($mail) ? $mail() : $mail;
    }

    public function getHeaderOrFail(string $key): string
    {
        $header = $this->fakeData['header'][$key] ?? '';

        return is_callable($header) ? $header() : $header;
    }

    public function getHeader(string $key): ?string
    {
        $header = $this->fakeData['header'][$key] ?? null;

        return is_callable($header) ? $header() : $header;
    }
}
