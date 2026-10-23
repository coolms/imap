# Changelog

All notable changes to `coolms/imap` are recorded here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[Semantic Versioning](https://semver.org/). Before 1.0.0, a minor version may break the API.

## Unreleased

### Added

- `Watch`: several clients IDLE at once, each on its own selected folder, with one wait for all of them. Before it
  polls, it reads what PHP already holds for each connection: a TLS record can carry two responses, and once the first
  is read the second waits in PHP's buffer, where a poll() on the socket never sees it. A client whose connection fails
  is dropped from the watch and named in `failures()`; the others go on.
- `Client::beginIdle()`, `idleNext()`, `endIdle()`, `buffered()` and `stream()`: IDLE in pieces, for `Watch`.
- `Transport\Poller` and `Transport\IoPoller` (`Io\Poll`): the wait `Watch` makes, and the seam a test replaces.

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
