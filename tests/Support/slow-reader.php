<?php

declare(strict_types=1);

// A peer that starts reading late, on the other end of a socket pair as STDIN: it waits $argv[1] ms, then reads
// everything sent, and writes how many bytes it read to $argv[2]. A client writing more than the socket's buffer
// holds must wait for it that long. It runs as its own process so that the wait is real.

[, $afterMs, $record] = $_SERVER['argv'];
usleep((int) $afterMs * 1000);
file_put_contents($record, (string) strlen((string) stream_get_contents(STDIN)));
