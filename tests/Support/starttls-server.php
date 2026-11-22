<?php

declare(strict_types=1);

// A STARTTLS server for the opening's tests, run as its own process: it listens on 127.0.0.1 over TCP, writes its port
// to $argv[2], greets one client with STARTTLS offered, answers its STARTTLS, runs the TLS handshake with the
// certificate in $argv[1], and then answers CAPABILITY until the client leaves.

[, $cert, $portFile] = $_SERVER['argv'];
$server = stream_socket_server(
    'tcp://127.0.0.1:0',
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
fwrite($client, "* OK [CAPABILITY IMAP4rev1 STARTTLS] ready\r\n");
$line = (string) fgets($client);
$tag = strtok($line, ' ');
fwrite($client, "{$tag} OK begin TLS\r\n");
if (true !== @stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_SERVER)) {
    exit(0);
}
while (false !== ($line = fgets($client))) {
    $tag = strtok($line, ' ');
    fwrite($client, "* CAPABILITY IMAP4rev1 IDLE\r\n{$tag} OK capabilities\r\n");
}
