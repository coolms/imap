<?php

declare(strict_types=1);

// A server for IDLE's tests, on the other end of a socket pair as STDIN and STDOUT: it answers IDLE with "+",
// sends what $argv[2] says after $argv[3] ms, waits for DONE, completes, and writes every line it read to $argv[1].
// It has to run as its own process: what is tested is a client waiting on a server that is really silent.

[, $record, $send, $afterMs] = $_SERVER['argv'];
$read = [];
$read[] = rtrim((string) fgets(STDIN), "\r\n");
fwrite(STDOUT, "+ idling\r\n");
usleep((int) $afterMs * 1000);
if ('' !== $send) {
    fwrite(STDOUT, str_replace('\n', "\r\n", $send));
}
$read[] = rtrim((string) fgets(STDIN), "\r\n");
fwrite(STDOUT, "a1 OK IDLE terminated\r\n");
file_put_contents($record, implode("\n", $read));
