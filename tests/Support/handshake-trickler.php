<?php

declare(strict_types=1);

// A server whose TLS handshake never comes whole, run as its own process: it listens on 127.0.0.1, writes its port to
// $argv[1], and for one client sends the first bytes of a TLS record one every 100 ms, then holds the connection for
// $argv[3] ms. With $argv[2] "starttls" it first greets with STARTTLS offered and answers the client's STARTTLS, so the
// trickle is STARTTLS's handshake; with "implicit", the trickle is the first thing on the socket.

[, $portFile, $mode, $holdMs] = $_SERVER['argv'];
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (false === $server) {
    exit(1);
}
file_put_contents($portFile, (string) parse_url('tcp://' . stream_socket_get_name($server, false), PHP_URL_PORT));
$peer = stream_socket_accept($server, 10);
if (false === $peer) {
    exit(1);
}
if ('starttls' === $mode) {
    fwrite($peer, "* OK [CAPABILITY IMAP4rev1 STARTTLS] ready\r\n");
    fgets($peer);
    fwrite($peer, "a1 OK begin TLS\r\n");
}
foreach (str_split("\x16\x03\x03\x00\x40") as $byte) {
    if (false === @fwrite($peer, $byte)) {
        exit(0);
    }
    usleep(100_000);
}
usleep((int) $holdMs * 1000);
