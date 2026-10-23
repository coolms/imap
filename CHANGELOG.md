# Changelog

All notable changes to `coolms/imap` are recorded here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[Semantic Versioning](https://semver.org/). Before 1.0.0, a minor version may break the API.

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
