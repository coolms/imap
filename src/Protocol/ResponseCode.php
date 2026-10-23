<?php

declare(strict_types=1);

namespace CoolMS\Imap\Protocol;

/**
 * The response codes this library knows by name -- RFC 9051 s.7.1, RFC 5530, UIDPLUS (RFC 4315), CONDSTORE and QRESYNC
 * (RFC 7162), SPECIAL-USE (RFC 6154), OBJECTID (RFC 8474), and a few more servers send. A server may send any other:
 * it is read with tryFrom, so an unknown code is null here and its raw name is still there (Response::codeName()),
 * never an error.
 */
enum ResponseCode: string
{
    case Alert = 'ALERT';
    case AlreadyExists = 'ALREADYEXISTS';
    case AppendUid = 'APPENDUID';
    case AuthenticationFailed = 'AUTHENTICATIONFAILED';
    case AuthorizationFailed = 'AUTHORIZATIONFAILED';
    case BadCharset = 'BADCHARSET';
    case Cannot = 'CANNOT';
    case Capability = 'CAPABILITY';
    case ClientBug = 'CLIENTBUG';
    case Closed = 'CLOSED';
    case CompressionActive = 'COMPRESSIONACTIVE';
    case ContactAdmin = 'CONTACTADMIN';
    case CopyUid = 'COPYUID';
    case Corruption = 'CORRUPTION';
    case Expired = 'EXPIRED';
    case ExpungeIssued = 'EXPUNGEISSUED';
    case HasChildren = 'HASCHILDREN';
    case HighestModseq = 'HIGHESTMODSEQ';
    case InUse = 'INUSE';
    case Limit = 'LIMIT';
    case MailboxId = 'MAILBOXID';
    case Modified = 'MODIFIED';
    case NoModseq = 'NOMODSEQ';
    case Nonexistent = 'NONEXISTENT';
    case NoPerm = 'NOPERM';
    case NotSaved = 'NOTSAVED';
    case OverQuota = 'OVERQUOTA';
    case Parse = 'PARSE';
    case PermanentFlags = 'PERMANENTFLAGS';
    case PrivacyRequired = 'PRIVACYREQUIRED';
    case ReadOnly = 'READ-ONLY';
    case ReadWrite = 'READ-WRITE';
    case Referral = 'REFERRAL';
    case ServerBug = 'SERVERBUG';
    case TooBig = 'TOOBIG';
    case TryCreate = 'TRYCREATE';
    case UidNext = 'UIDNEXT';
    case UidNotSticky = 'UIDNOTSTICKY';
    case UidValidity = 'UIDVALIDITY';
    case Unavailable = 'UNAVAILABLE';
    case UnknownCte = 'UNKNOWN-CTE';
    case UseAttr = 'USEATTR';
}
