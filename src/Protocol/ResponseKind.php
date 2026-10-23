<?php

declare(strict_types=1);

namespace CoolMS\Imap\Protocol;

enum ResponseKind
{
    case Continuation;
    case Tagged;
    case UntaggedStatus;
    case UntaggedData;
}
