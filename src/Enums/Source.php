<?php

namespace Notifiable\ReceiveEmail\Enums;

enum Source
{
    case Stream;
    case Path;
    case Text;
}
