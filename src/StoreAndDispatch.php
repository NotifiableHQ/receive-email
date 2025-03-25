<?php

namespace Notifiable\ReceiveEmail;

use Exception;
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
        DB::beginTransaction();

        try {
            /** @var Sender $sender */
            $sender = Sender::query()->updateOrCreate(
                ['address' => $parsedMail->sender()->address],
                ['display' => $parsedMail->sender()->display],
            );

            /** @var Email $email */
            $email = $sender->emails()->create([
                'message_id' => $parsedMail->id(),
                'sent_at' => $parsedMail->date(),
            ]);

            storage()->put($email->path(), $parsedMail->getParser()->getStream());

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();

            throw $e;
        }

        event(new EmailReceived($email));
    }
}
