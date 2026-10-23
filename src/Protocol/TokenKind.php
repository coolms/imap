<?php

declare(strict_types=1);

namespace CoolMS\Imap\Protocol;

enum TokenKind
{
    case Atom;
    case Number;
    case Quoted;
    case Literal;
    case Nil;
    case List;
    case Code;
}
