# coolms/imap

An IMAP client for PHP, written for keeping a mailbox in sync: read what changed since last time, hear about new
mail as it arrives, and never lose a change another client made in between.

- **Sync by mod-sequence.** CONDSTORE and QRESYNC (RFC 7162): fetch only what changed since a mod-sequence, with the
  UIDs that vanished; store a flag only if nobody else changed the message first.
- **IDLE** (RFC 2177), timed on the monotonic clock.
- **Folders with roles.** SPECIAL-USE (RFC 6154) roles -- Sent, Trash, Junk, Drafts, Archive, All, Flagged -- and names
  in any script, decoded from modified UTF-7.
- **MOVE with COPYUID** (RFC 6851, RFC 4315): the UIDs the moved messages got.
- **Sign-in:** PLAIN, LOGIN, XOAUTH2 and OAUTHBEARER, with SASL-IR.
- **TLS verified, always.** Implicit TLS or STARTTLS, the certificate checked against the server's name. STARTTLS is
  never skipped when it was asked for, and bytes slipped in before the handshake are refused.
- **It never deletes mail.** No EXPUNGE is ever sent and `\Deleted` is never stored; a message leaves a folder only
  by MOVE.
- **Bounded.** Response lines and literals have limits checked before they are read into memory; UID sets are kept as
  ranges, never expanded.
- **Zero dependencies.** PHP 8.5 with `ext-ctype` and `ext-openssl`.

## Install

```bash
composer require coolms/imap
```

## Use

```php
use CoolMS\Imap\Client;
use CoolMS\Imap\Transport\Endpoint;
use CoolMS\Imap\Transport\Security;
use CoolMS\Imap\Value\FolderName;
use CoolMS\Imap\Value\UidSet;

$imap = Client::open(new Endpoint('imap.example.com', 993, Security::Tls));
$imap->login('me@example.com', $password);
$imap->enable('CONDSTORE', 'QRESYNC');

foreach ($imap->folders() as $folder) {
    echo $folder->name->display, ' ', $folder->role?->value ?? '', "\n";
}

$inbox = $imap->select(FolderName::fromWire('INBOX'));

// Everything that changed since the last sync, and what was expunged.
$changes = $imap->uidFetch(UidSet::fromOnward(1), ['FLAGS'], changedSince: $lastModseq, vanished: true);
foreach ($changes->messages as $message) {
    // $message->uid, $message->flags, $message->modseq
}
foreach ($changes->vanished as $gone) {
    // $gone->uids -- a set of UIDs, as ranges
}
$lastModseq = $inbox->highestModseq;
```

Signing in with OAuth 2.0:

```php
use CoolMS\Imap\Sasl\XOAuth2;

$imap->authenticate(new XOAuth2('me@example.com', $accessToken));
```

A command the server refuses throws `CommandFailed`, with the server's response code (`AUTHENTICATIONFAILED`,
`NONEXISTENT`, ...) on `code()`. A response that does not parse throws `ProtocolError`. A server that goes quiet
throws `TimedOut`, and a closed connection throws `ConnectionClosed`.

## Tests

```bash
composer test          # unit tests, no network
composer conformance   # against Dovecot and GreenMail in Docker (tests/Conformance)
```

The conformance suite starts its own Dovecot and GreenMail with Docker Compose and runs the client against them:
sign-in, folder roles, a message read back byte for byte, changes and deletions since a mod-sequence, a conditional
store refused over another client's change, IDLE, and TLS accepted and refused.

## License

MIT. See [LICENSE](LICENSE).
