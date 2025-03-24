<?php

namespace Notifiable\ReceiveEmail;

use Carbon\CarbonImmutable;
use Notifiable\ReceiveEmail\Contracts\ParsedMail;
use Notifiable\ReceiveEmail\Data\Address;
use Notifiable\ReceiveEmail\Data\Mail;
use Notifiable\ReceiveEmail\Data\Recipients;
use Notifiable\ReceiveEmail\Exceptions\MalformedEmailException;
use PhpMimeMailParser\Parser;

class ParserParsedMail implements ParsedMail
{
    private string $id;

    private CarbonImmutable $date;

    private Address $from;

    /**
     * @var Address[]|null
     */
    private ?array $to;

    /**
     * @var Address[]|null
     */
    private ?array $cc;

    /**
     * @var Address[]|null
     */
    private ?array $bcc;

    private Recipients $recipients;

    private ?string $subject;

    private ?string $text;

    private ?string $html;

    public function __construct(
        protected Parser $parser,
    ) {}

    public function parser(): Parser
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
        $from = $this->parser->getAddresses('from')[0];

        if ($from === []) {
            throw MalformedEmailException::missingFromAddress();
        }

        return $this->from ??= Address::from($this->parser->getAddresses('from')[0]);
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
        $to = $this->parser->getAddresses('to');

        return $this->to ??= ($to === [] ? null : Address::fromMany($to));
    }

    /**
     * @return Address[]
     */
    public function cc(): ?array
    {
        $cc = $this->parser->getAddresses('cc');

        return $this->cc ??= ($cc === [] ? null : Address::fromMany($cc));
    }

    /**
     * @return Address[]
     */
    public function bcc(): ?array
    {
        $bcc = $this->parser->getAddresses('bcc');

        return $this->bcc ??= ($bcc === [] ? null : Address::fromMany($bcc));
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
            throw MalformedEmailException::missingHeader($key);
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
