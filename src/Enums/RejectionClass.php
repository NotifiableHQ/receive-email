<?php

namespace Notifiable\ReceiveEmail\Enums;

enum RejectionClass: string
{
    case EnvelopeList = 'envelope-list';
    case Spf = 'spf';
    case Helo = 'helo';
    case RateLimit = 'rate-limit';
    case Postscreen = 'postscreen';
    case Other = 'other';
}
