<?php

namespace Notifiable\ReceiveEmail;

use Carbon\CarbonImmutable;
use Notifiable\ReceiveEmail\Contracts\ParsedMail;
use Notifiable\ReceiveEmail\Data\Address;
use Notifiable\ReceiveEmail\Data\Mail;
use Notifiable\ReceiveEmail\Data\Recipients;
use Notifiable\ReceiveEmail\Exceptions\MalformedMailException;
use PhpMimeMailParser\Parser;

class ParserParsedMail implements ParsedMail
{
    private string $id;

    private CarbonImmutable $date;

    private Address $from;

    /**
     * @var Address[]
     */
    private array $to;

    /**
     * @var Address[]
     */
    private array $cc;

    /**
     * @var Address[]
     */
    private array $bcc;

    private Recipients $recipients;

    private ?string $subject;

    private ?string $text;

    private ?string $html;

    public function __construct(
        protected Parser $parser,
    ) {}

    public function parser(Parser $parser): ParsedMail
    {
        $this->parser = $parser;

        return $this;
    }

    public function getParser(): Parser
    {
        return $this->parser;
    }

    public function id(): string
    {
        return $this->id ??= $this->getHeader('message-id');
    }

    public function date(): CarbonImmutable
    {
        return $this->date ??= CarbonImmutable::parse($this->getHeader('date'))->utc();
    }

    public function from(): Address
    {
        $from = $this->parser->getAddresses('from');

        if ($from === []) {
            throw MalformedMailException::missingFromAddress();
        }

        return $this->from ??= Address::from($from[0]);
    }

    public function subject(): ?string
    {
        return $this->subject ??= $this->getOrNullHeader('subject');
    }

    /**
     * @return Address[]
     */
    public function to(): array
    {
        return $this->to ??= Address::fromMany($this->parser->getAddresses('to'));
    }

    /**
     * @return Address[]
     */
    public function cc(): array
    {
        return $this->cc ??= Address::fromMany($this->parser->getAddresses('cc'));
    }

    /**
     * @return Address[]
     */
    public function bcc(): array
    {
        return $this->bcc ??= Address::fromMany($this->parser->getAddresses('bcc'));
    }

    public function recipients(): Recipients
    {
        return $this->recipients ??= new Recipients($this->to(), $this->cc(), $this->bcc());
    }

    public function text(): ?string
    {
        $text = $this->parser->getMessageBody('text');

        return $this->text ??= ($text === '' ? null : $text);
    }

    public function html(): ?string
    {
        $html = $this->parser->getMessageBody('html');

        return $this->html ??= ($html === '' ? null : $html);
    }

    public function toMail(): Mail
    {
        return new Mail(
            $this->id(),
            $this->date(),
            $this->from(),
            $this->recipients(),
            $this->subject(),
            $this->text(),
            $this->html()
        );
    }

    private function getHeader(string $key): string
    {
        if (($header = $this->parser->getHeader($key)) === false) {
            throw MalformedMailException::missingHeader($key);
        }

        return $header;
    }

    private function getOrNullHeader(string $key): ?string
    {
        if (($header = $this->parser->getHeader($key)) === false) {
            return null;
        }

        return $header === '' ? null : $header;
    }
}
