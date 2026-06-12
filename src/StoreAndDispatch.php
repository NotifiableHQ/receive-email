<?php

namespace Notifiable\ReceiveEmail;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
    /**
     * The width of the plain ->string() enrichment columns
     * (emails.message_id, senders.display). senders.address needs no bound:
     * Address validates with FILTER_VALIDATE_EMAIL, which enforces the RFC
     * length limits — well under this.
     */
    private const ENRICHMENT_STRING_LENGTH = 255;

    /**
     * The MySQL TIMESTAMP range — the narrowest among the supported
     * drivers. A forged Date outside it (spam commonly post-dates itself)
     * would fail the enrichment commit on MySQL.
     */
    private const SENT_AT_MIN = '1970-01-01 00:00:01';

    private const SENT_AT_MAX = '2038-01-19 03:14:07';

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
        } catch (Throwable $exception) {
            // The committed row was never announced, and a bare tempfail
            // would have Postfix redeliver into a fresh ULID: an orphan row
            // no event ever points at, plus a duplicate. Withdraw the row
            // and its file, then tempfail — redelivery re-runs ingestion
            // from scratch. Only transient failures reach here: every value
            // the enrichment commit writes is validated against its column
            // before the transaction opens.
            $this->withdraw($email);

            throw $exception;
        }

        event(new EmailReceived($email));
    }

    /**
     * Best-effort header enrichment of the committed row. Every header is
     * read and validated against its column before the transaction opens,
     * so a MalformedMailException can never leave partial enrichment behind
     * and the commit itself can only fail transiently.
     */
    private function enrich(Email $email, ParsedMailContract $parsedMail): void
    {
        $messageId = $parsedMail->id();
        $sentAt = $parsedMail->date();
        $headerSender = $parsedMail->sender();

        if (mb_strlen($messageId) > self::ENRICHMENT_STRING_LENGTH) {
            // The ULID is the identity and message_id is annotation, but a
            // truncated annotation would silently collide lookups: an
            // oversized Message-ID is Malformed Mail instead.
            throw MalformedMailException::oversizedHeader('message-id');
        }

        if ($sentAt->lt(CarbonImmutable::parse(self::SENT_AT_MIN, 'UTC'))
            || $sentAt->gt(CarbonImmutable::parse(self::SENT_AT_MAX, 'UTC'))) {
            throw MalformedMailException::unstorableDate();
        }

        // Display is presentation, not identity: truncating it keeps an
        // otherwise-parseable message parsed.
        $display = mb_substr($headerSender->display, 0, self::ENRICHMENT_STRING_LENGTH);

        DB::transaction(function () use ($email, $messageId, $sentAt, $headerSender, $display) {
            /** @var Sender $sender */
            $sender = Sender::query()->updateOrCreate(
                ['address' => mb_strtolower($headerSender->address)],
                ['display' => $display],
            );

            $email->sender()->associate($sender);

            $email->fill([
                'message_id' => $messageId,
                'sent_at' => $sentAt,
                'parsed_at' => now(),
            ])->save();
        });
    }

    /**
     * Deleting the row also deletes its raw file (the Email deleted hook).
     * Withdrawal is best-effort: if the database just died, this delete
     * fails too and the orphan row survives unannounced until an operator
     * reconciles — the log line makes it findable. The original failure
     * still tempfails either way.
     */
    private function withdraw(Email $email): void
    {
        try {
            $email->delete();
        } catch (Throwable $exception) {
            Log::error('Failed to withdraw the Email row after an enrichment failure; the row was never announced by an event.', [
                'ulid' => $email->ulid,
                'path' => $email->path(),
                'exception' => $exception,
            ]);
        }
    }
}
