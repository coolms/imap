<?php

declare(strict_types=1);

// A server that sends one byte at a time, on the other end of a socket pair as STDOUT: the bytes of $argv[1] ("\n"
// written as \n), each $argv[2] ms after the last, then it holds the connection open for $argv[3] ms. Each byte comes
// well inside any per-wait timeout, so only a deadline over the whole response can end the read. It runs as its own
// process: what is tested is a client reading from a server that is really slow.

[, $bytes, $everyMs, $holdMs] = $_SERVER['argv'];
foreach (str_split(str_replace('\n', "\r\n", $bytes)) as $byte) {
    if (false === @fwrite(STDOUT, $byte)) {
        exit(0);
    }
    usleep((int) $everyMs * 1000);
}
usleep((int) $holdMs * 1000);
