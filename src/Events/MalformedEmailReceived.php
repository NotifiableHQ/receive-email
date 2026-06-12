<?php

namespace Notifiable\ReceiveEmail\Events;

use Illuminate\Queue\SerializesModels;
use Notifiable\ReceiveEmail\Models\Email;

/**
 * Announces Malformed Mail: accepted mail whose headers could not be parsed.
 * The raw message and its envelope row are kept (`parsed_at` stays null);
 * EmailReceived keeps its parseable-mail contract and never fires for it.
 */
class MalformedEmailReceived
{
    use SerializesModels;

    public function __construct(
        public Email $email
    ) {}
}
