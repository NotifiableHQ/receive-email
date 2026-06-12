<?php

namespace Notifiable\ReceiveEmail;

use Illuminate\Support\Facades\DB;
use Notifiable\ReceiveEmail\Contracts\ParsedMailContract;
use Notifiable\ReceiveEmail\Contracts\PipeCommandContract;
use Notifiable\ReceiveEmail\Data\Envelope;
use Notifiable\ReceiveEmail\Events\EmailReceived;
use Notifiable\ReceiveEmail\Events\MalformedEmailReceived;
use Notifiable\ReceiveEmail\Exceptions\FailedToStoreException;
use Notifiable\ReceiveEmail\Exceptions\MalformedMailException;
use Notifiable\ReceiveEmail\Models\Email;
use Notifiable\ReceiveEmail\Models\Sender;
use Throwable;

class StoreAndDispatch implements PipeCommandContract
{
    public function handle(ParsedMailContract $parsedMail, Envelope $envelope): void
    {
        $email = Email::fromEnvelope($envelope);

        // Raw first, outside any transaction: once the file is on disk, no
        // parser opinion about the message can lose it. A false return must
        // throw — silently ignoring it would commit a row without its file.
        if (! $parsedMail->store($email->path())) {
            throw FailedToStoreException::path($email->path());
        }

        try {
            DB::transaction(fn () => $email->save());
        } catch (Throwable $exception) {
            // The row never committed, so the just-written file has no
            // owner: delete it and tempfail — Postfix redelivers.
            storage()->delete($email->path());

            throw $exception;
        }

        try {
            $this->enrich($email, $parsedMail);
        } catch (MalformedMailException) {
            // Malformed Mail is kept, never lost: the raw file and the
            // envelope row survive with parsed_at null, announced by its
            // own event. EmailReceived fires only for parsed mail.
            event(new MalformedEmailReceived($email));

            return;
        }

        event(new EmailReceived($email));
    }

    /**
     * Best-effort header enrichment of the committed row. Every header is
     * read before the transaction opens, so a MalformedMailException can
     * never leave partial enrichment behind. The committed row owns the raw
     * file: an enrichment failure rethrows without touching either.
     */
    private function enrich(Email $email, ParsedMailContract $parsedMail): void
    {
        $messageId = $parsedMail->id();
        $sentAt = $parsedMail->date();
        $headerSender = $parsedMail->sender();

        DB::transaction(function () use ($email, $messageId, $sentAt, $headerSender) {
            /** @var Sender $sender */
            $sender = Sender::query()->updateOrCreate(
                ['address' => mb_strtolower($headerSender->address)],
                ['display' => $headerSender->display],
            );

            $email->sender()->associate($sender);

            $email->fill([
                'message_id' => $messageId,
                'sent_at' => $sentAt,
                'parsed_at' => now(),
            ])->save();
        });
    }
}
