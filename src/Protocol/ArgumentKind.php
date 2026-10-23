<?php

declare(strict_types=1);

namespace CoolMS\Imap\Protocol;

enum ArgumentKind
{
    case Atom;
    case Number;
    case Text;
    case Literal;
    case Raw;
    case List;
}
