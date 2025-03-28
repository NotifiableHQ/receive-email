<?php

namespace Notifiable\ReceiveEmail;

use Exception;
use Illuminate\Support\Facades\DB;
use Notifiable\ReceiveEmail\Contracts\ParsedMailContract;
use Notifiable\ReceiveEmail\Contracts\PipeCommandContract;
use Notifiable\ReceiveEmail\Events\EmailReceived;
use Notifiable\ReceiveEmail\Models\Email;
use Notifiable\ReceiveEmail\Models\Sender;

class StoreAndDispatch implements PipeCommandContract
{
    public function handle(ParsedMailContract $parsedMail): void
    {
        DB::beginTransaction();

        try {
            /** @var Sender $sender */
            $sender = Sender::query()->updateOrCreate(
                ['address' => mb_strtolower($parsedMail->sender()->address)],
                ['display' => $parsedMail->sender()->display],
            );

            /** @var Email $email */
            $email = $sender->emails()->create([
                'message_id' => $parsedMail->id(),
                'sent_at' => $parsedMail->date(),
            ]);

            $parsedMail->store($email->path());

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();

            throw $e;
        }

        event(new EmailReceived($email));
    }
}
