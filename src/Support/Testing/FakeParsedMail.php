<?php

namespace Notifiable\ReceiveEmail\Support\Testing;

use Carbon\CarbonImmutable;
use Notifiable\ReceiveEmail\Contracts\ParsedMail;
use Notifiable\ReceiveEmail\Data\Address;
use Notifiable\ReceiveEmail\Data\Mail;
use Notifiable\ReceiveEmail\Data\Recipients;
use PhpMimeMailParser\Parser;

class FakeParsedMail implements ParsedMail
{
    private array $fakeData;

    public function fake(array $data): self
    {
        $this->fakeData = $data;

        return $this;
    }

    public function parser(): Parser
    {
        return new Parser;
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

    public function from(): Address
    {
        $from = $this->fakeData['from'];

        return $from instanceof Address
            ? $from
            : Address::from($from);
    }

    public function to(): ?array
    {
        $to = $this->fakeData['to'];

        if ($to === []) {
            return null;
        }

        return $to[0] instanceof Address
            ? $to
            : Address::fromMany($to);
    }

    public function cc(): ?array
    {
        $cc = $this->fakeData['cc'];

        if ($cc === []) {
            return null;
        }

        return $cc[0] instanceof Address
            ? $cc
            : Address::fromMany($cc);
    }

    public function bcc(): ?array
    {
        $bcc = $this->fakeData['bcc'];

        if ($bcc === []) {
            return null;
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
                $this->from(),
                $this->recipients(),
                $this->subject(),
                $this->text(),
                $this->html()
            );
    }
}
