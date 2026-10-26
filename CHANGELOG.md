# Changelog

All notable changes to `coolms/imap` are recorded here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[Semantic Versioning](https://semver.org/). Before 1.0.0, a minor version may break the API.

## Unreleased

### Added

- `Client::create()`, `rename()`, `subscribe()` and `unsubscribe()` (RFC 9051 s.6.3.4, 6.3.6, 6.3.7, 6.3.8). A server's
  refusal is a `CommandFailed` carrying its code, such as `[ALREADYEXISTS]` or `[NONEXISTENT]`. The INBOX is never
  renamed: that moves its mail to a new folder.
- `Client::deleteEmpty()`: a folder is deleted only when it holds nothing, since a server deletes a folder's mail
  with it. The folder is first renamed to a name only this client knows, so no other client can put mail in it while
  it is looked at, and deleted when it holds no message and no folder there. Otherwise it is put back under its own
  name and `FolderNotEmpty` says what it holds (and, if it could not be put back, where it is).

## 0.1.0-alpha.4

### Added

- `Watch` renews and removes one client at a time. Each client's IDLE is renewed when its own `$renewAfter` is up
  (28 minutes by default; RFC 2177 asks for at least every 29): DONE, then IDLE again when the OK comes, without
  blocking and without ending the others. A wait never sleeps past the next renewal, and a renewal not answered within
  `$answerWithin` (30 s by default) drops that client into `failures()`. `remove($key)` ends one client's IDLE and
  lets it go while the others go on; `keys()` and `renewing()` say which are watched and which are renewing.
- `Client::renewIdle()`, `renewing()` and `idleRead()`: an IDLE renewed in pieces, for `Watch`. A client removed while
  its DONE is unanswered sends no second DONE, and one removed while its new IDLE is asked for is let in first.

### Changed

- `Watch::failures()` may also hold a `CommandFailed` (a server that refuses a renewal) or a `TimedOut` (one that
  stopped answering, met by `remove()`). A BYE while idling drops that client as a `ConnectionClosed` instead of
  coming back from `wait()` as news.

## 0.1.0-alpha.3

### Added

- `Watch`: several clients IDLE at once, each on its own selected folder, with one wait for all of them. Before it
  polls, it reads what PHP already holds for each connection: a TLS record can carry two responses, and once the first
  is read the second waits in PHP's buffer, where a poll() on the socket never sees it. A client whose connection fails
  is dropped from the watch and named in `failures()`; the others go on.
- `Client::beginIdle()`, `idleNext()`, `endIdle()`, `buffered()` and `stream()`: IDLE in pieces, for `Watch`.
- `Transport\Poller` and `Transport\IoPoller` (`Io\Poll`): the wait `Watch` makes, and the seam a test replaces.
- Gmail's extensions (`Capability::GmailExtensions`, X-GM-EXT-1): `FetchItem::gmailMessageId()`, `::gmailThreadId()`
  and `::gmailLabels()`, read into `FetchedItems::$gmailMessageId`, `$gmailThreadId` (digits, as strings: an
  unsigned 64-bit id may pass `PHP_INT_MAX`) and `$gmailLabels` (as sent). `Client::uidStoreLabels()` adds, removes
  or replaces labels: a system label by its backslash name, any other by its `FolderName`. Every Gmail item is
  refused where the server does not name X-GM-EXT-1.

### Changed

- Two runtime dependencies: `symfony/polyfill-io-poll` and `symfony/polyfill-time` (`Io\Poll` and `Time\Duration`,
  both in PHP 8.6 core). The library takes Symfony components, never a framework.

## 0.1.0-alpha.2

### Changed (breaking)

- `Client::enable()` and `Client::enabled()` take `Capability` cases, and `enable()` returns them. An ENABLED
  response naming an extension that was not asked for, or one with no case, is passed over.
- `Client::uidFetch()` takes `FetchItem` objects: `FetchItem::flags()`, `::modseq()`, `::size()`,
  `::internalDate()`, and `::body(Section, from, length)`. A body is always fetched with BODY.PEEK, and the UID is
  asked for once.
- `Argument::$value` holds a secret as a `SensitiveParameterValue`.

### Added

- `Capability`, an enum. `Capabilities::has()` takes a case, or a name where no case fits (AUTH=..., X-...).
- `Section`: the whole message, HEADER, HEADER.FIELDS[.NOT], TEXT, a part, and a part's MIME header. Field names and
  part numbers the grammar cannot carry are refused where the section is built. `FetchedItems::section()` and
  `hasSection()` take a `Section`.
- `ResponseCode`, an enum of the codes the library knows. Use `Response::knownCode()` and
  `CommandFailed::knownCode()` to read one; an unknown code is null there, its raw name stays in `codeName()` and
  `code()`, and it never throws.

### Fixed

- A password or a token no longer reaches a stack trace. Before this, it could show up as an argument: the bytes
  sent, or the NUL check of a secret argument. It could also show up inside an object passed along (a command or a
  SASL mechanism) when a logger printed the trace.

## 0.1.0-alpha.1

The first release.

### Added

- Connections over implicit TLS or STARTTLS, the certificate verified against the server's name (or a peer name
  given apart from the address connected to), with connect and read timeouts.
- Sign-in by LOGIN or SASL: PLAIN, LOGIN, XOAUTH2, OAUTHBEARER, with SASL-IR.
- ENABLE; LIST with SPECIAL-USE roles; STATUS; SELECT and EXAMINE with CONDSTORE.
- APPEND with APPENDUID; UID SEARCH; UID FETCH with CHANGEDSINCE and VANISHED; UID STORE with UNCHANGEDSINCE and
  MODIFIED; UID MOVE with COPYUID; IDLE.
- Folder names in modified UTF-7, both ways.
