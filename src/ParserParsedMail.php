<?php

namespace Notifiable\ReceiveEmail;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Notifiable\ReceiveEmail\Contracts\ParsedMailContract;
use Notifiable\ReceiveEmail\Data\Address;
use Notifiable\ReceiveEmail\Data\Mail;
use Notifiable\ReceiveEmail\Data\Recipients;
use Notifiable\ReceiveEmail\Enums\Source;
use Notifiable\ReceiveEmail\Exceptions\MalformedMailException;
use PhpMimeMailParser\Parser;

class ParserParsedMail implements ParsedMailContract
{
    private string $id;

    private CarbonImmutable $date;

    private Address $sender;

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
        private Parser $parser
    ) {}

    /**
     * @param  string|resource  $source
     */
    public static function source($source, Source $type = Source::Stream): ParsedMailContract
    {
        $parser = new Parser;

        return new ParserParsedMail(match ($type) {
            Source::Stream => is_string($source) ? throw new InvalidArgumentException('Source must be a resource') : $parser->setStream($source),
            Source::Path => is_string($source) ? $parser->setPath($source) : throw new InvalidArgumentException('Source must be a string'),
            Source::Text => is_string($source) ? $parser->setText($source) : throw new InvalidArgumentException('Source must be a string'),
        });
    }

    public function store(string $path): bool
    {
        return storage()->put($path, $this->parser->getStream());
    }

    public function id(): string
    {
        return $this->id ??= $this->getHeaderOrFail('message-id');
    }

    public function date(): CarbonImmutable
    {
        return $this->date ??= CarbonImmutable::parse($this->getHeaderOrFail('date'))->utc();
    }

    public function sender(): Address
    {
        // If sender is present then that is the original sender
        $sender = $this->parser->getAddresses('sender');

        if ($sender === []) {
            $sender = $this->parser->getAddresses('from');
        }

        if ($sender === []) {
            throw MalformedMailException::missingSender();
        }

        return $this->sender ??= Address::from($sender[0]);
    }

    public function subject(): ?string
    {
        return $this->subject ??= $this->getHeader('subject');
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
            $this->sender(),
            $this->recipients(),
            $this->subject(),
            $this->text(),
            $this->html()
        );
    }

    public function getHeaderOrFail(string $key): string
    {
        if (($header = $this->parser->getHeader($key)) === false) {
            throw MalformedMailException::missingHeader($key);
        }

        return $header;
    }

    public function getHeader(string $key): ?string
    {
        if (($header = $this->parser->getHeader($key)) === false) {
            return null;
        }

        return $header === '' ? null : $header;
    }
}
