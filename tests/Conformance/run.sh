#!/usr/bin/env bash
# The conformance suite: start Dovecot and GreenMail (compose.yaml), wait until both greet, copy Dovecot's certificate
# out for the TLS tests, run the suite, and stop the servers again. KEEP=1 leaves them running.
#   tests/Conformance/run.sh [phpunit arguments]
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
root="$(cd "$here/../.." && pwd)"
compose=(docker compose -f "$here/compose.yaml")

"${compose[@]}" up -d > /dev/null
[ "${KEEP:-0}" = 1 ] || trap '"${compose[@]}" down -v > /dev/null 2>&1' EXIT

# Each server greets with "* OK" once it is ready; wait for both, up to a minute.
for port in 41143 43143; do
    for attempt in $(seq 1 60); do
        if php -r '$s = @fsockopen("127.0.0.1", (int) $argv[1], $e, $m, 1.0); exit($s && str_starts_with((string) fgets($s), "* OK") ? 0 : 1);' "$port"; then
            break
        fi
        [ "$attempt" -lt 60 ] || { echo "the server on 127.0.0.1:$port never greeted" >&2; exit 2; }
        sleep 1
    done
done

certs="$(mktemp -d)"
trap '"${compose[@]}" down -v > /dev/null 2>&1; rm -rf "$certs"' EXIT
[ "${KEEP:-0}" = 1 ] && trap 'rm -rf "$certs"' EXIT
"${compose[@]}" cp -L dovecot:/etc/dovecot/ssl/tls.crt "$certs/dovecot.crt" > /dev/null

cd "$root"
DOVECOT_TEST_CA="$certs/dovecot.crt" vendor/bin/phpunit --testsuite conformance "$@"
