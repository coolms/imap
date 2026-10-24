<?php

declare(strict_types=1);

namespace CoolMS\Imap\Value;

/**
 * The capabilities this library asks about or turns on, by name (RFC 9051 s.7.2.2 and each extension's RFC). A server
 * names others too: Capabilities keeps every name it sent, and has() takes a plain string -- AUTH=..., a vendor's
 * X-... -- where no case here fits.
 *
 * Those ENABLE (RFC 5161) turns on: CONDSTORE and QRESYNC (RFC 7162), UTF8=ACCEPT (RFC 6855), IMAP4rev2 (RFC 9051),
 * METADATA (RFC 5464).
 *
 * X-GM-EXT-1 is Gmail's: X-GM-MSGID, X-GM-THRID and X-GM-LABELS (developers.google.com/gmail/imap/imap-extensions).
 */
enum Capability: string
{
    case Binary = 'BINARY';
    case Condstore = 'CONDSTORE';
    case Enable = 'ENABLE';
    case GmailExtensions = 'X-GM-EXT-1';
    case Id = 'ID';
    case Idle = 'IDLE';
    case Imap4rev1 = 'IMAP4REV1';
    case Imap4rev2 = 'IMAP4REV2';
    case ListExtended = 'LIST-EXTENDED';
    case ListStatus = 'LIST-STATUS';
    case LiteralMinus = 'LITERAL-';
    case LiteralPlus = 'LITERAL+';
    case LoginDisabled = 'LOGINDISABLED';
    case Metadata = 'METADATA';
    case Move = 'MOVE';
    case Namespace = 'NAMESPACE';
    case ObjectId = 'OBJECTID';
    case Qresync = 'QRESYNC';
    case SaslIr = 'SASL-IR';
    case SpecialUse = 'SPECIAL-USE';
    case StartTls = 'STARTTLS';
    case UidPlus = 'UIDPLUS';
    case Unselect = 'UNSELECT';
    case Utf8Accept = 'UTF8=ACCEPT';
}
