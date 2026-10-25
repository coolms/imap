<?php

declare(strict_types=1);

// A TLS server for the Watch's tests, run as its own process (the TLS handshake needs both ends moving at once):
// listens on 127.0.0.1 with the certificate in $argv[1], writes its port to $argv[2], and for one client answers
// PREAUTH, SELECT, IDLE ("+", then -- unless $argv[3] is "silent" -- two responses in ONE write after 100 ms: one TLS
// record; with "with-plus", the "+" and one response in one record), DONE and LOGOUT. With "mute-done" it never
// answers a DONE; with "slow-done" it answers one 300 ms late; with "news-in-renewal" mail arrives inside a renewal. Every line it read goes to $argv[2] . '.read' when the
// client leaves.

[, $cert, $portFile, $mode] = $_SERVER['argv'] + [3 => 'two'];
$server = stream_socket_server(
    'tls://127.0.0.1:0',
    $errno,
    $error,
    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
    stream_context_create(['ssl' => ['local_cert' => $cert]]),
);
if (false === $server) {
    exit(2);
}
$name = (string) stream_socket_get_name($server, false);
file_put_contents($portFile, substr($name, strrpos($name, ':') + 1));
$client = stream_socket_accept($server, 10);
if (false === $client) {
    exit(2);
}
fwrite($client, "* PREAUTH [CAPABILITY IMAP4rev1 IDLE] ready\r\n");
$read = [];
$idleTag = null;
while (false !== ($line = fgets($client))) {
    $line = rtrim($line, "\r\n");
    $read[] = $line;
    if ('DONE' === $line) {
        if ('mute-done' === $mode) {
            continue;
        }
        if ('slow-done' === $mode) {
            usleep(300_000);
        }
        // "news-in-renewal": mail arrives between the DONE and its completion, and again before the new IDLE's "+".
        $news = 'news-in-renewal' === $mode ? "* 6 EXISTS\r\n" : '';
        fwrite($client, "{$news}{$idleTag} OK IDLE terminated\r\n");
        continue;
    }
    [$tag, $command] = explode(' ', $line . ' ', 3);
    match (strtoupper($command)) {
        'SELECT' => fwrite($client, "* 3 EXISTS\r\n* OK [UIDVALIDITY 1] valid\r\n{$tag} OK [READ-WRITE] selected\r\n"),
        'IDLE' => (static function () use ($client, $tag, $mode, &$idleTag): void {
            $renewal = null !== $idleTag;
            $idleTag = $tag;
            if ('with-plus' === $mode) {
                // The "+" and a response in ONE record: starting IDLE reads the "+", the response stays buffered.
                fwrite($client, "+ idling\r\n* 4 EXISTS\r\n");

                return;
            }
            if ('news-in-renewal' === $mode && $renewal) {
                fwrite($client, "* 7 EXISTS\r\n+ idling\r\n");

                return;
            }
            fwrite($client, "+ idling\r\n");
            if ('two' === $mode) {
                usleep(100_000);
                fwrite($client, "* 4 EXISTS\r\n* 5 EXISTS\r\n");
            }
        })(),
        'LOGOUT' => fwrite($client, "* BYE bye\r\n{$tag} OK\r\n"),
        default => fwrite($client, "{$tag} BAD unknown\r\n"),
    };
    if ('LOGOUT' === strtoupper($command)) {
        break;
    }
}
file_put_contents($portFile . '.read', implode("\n", $read));
