<?php

namespace Notifiable\ReceiveEmail;

use Illuminate\Support\Facades\DB;
use Notifiable\ReceiveEmail\Contracts\ParsedMail;
use Notifiable\ReceiveEmail\Contracts\PipeCommand;
use Notifiable\ReceiveEmail\Events\EmailReceived;
use Notifiable\ReceiveEmail\Models\Email;
use Notifiable\ReceiveEmail\Models\Sender;

class StoreAndDispatch implements PipeCommand
{
    public function handle(ParsedMail $parsedMail): void
    {
        DB::transaction(function () use ($parsedMail) {
            /** @var Sender $sender */
            $sender = Sender::query()->updateOrCreate(
                ['email' => $parsedMail->sender()->address],
                ['name' => $parsedMail->sender()->display],
            );

            /** @var Email $email */
            $email = $sender->emails()->create([
                'message_id' => $parsedMail->id(),
                'sent_at' => $parsedMail->date(),
            ]);

            storage()->put($email->path(), $parsedMail->getParser()->getStream());

            event(new EmailReceived($email));
        });
    }
}
