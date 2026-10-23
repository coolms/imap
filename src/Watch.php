<?php

declare(strict_types=1);

namespace CoolMS\Imap;

use CoolMS\Imap\Exception\ConnectionClosed;
use CoolMS\Imap\Exception\ProtocolError;
use CoolMS\Imap\Protocol\Response;
use CoolMS\Imap\Transport\IoPoller;
use CoolMS\Imap\Transport\Poller;
use LogicException;

use function array_keys;
use function array_map;

/**
 * Several clients IDLE at once, each on its own selected folder, and one wait for all of them (RFC 2177): wait()
 * returns the responses that came, by the key each client was added under.
 *
 * Before it polls, the Watch looks at what PHP already holds for each client. A TLS record can carry two responses;
 * once the first is read the second is in PHP's buffer, and a native poll() on the socket does not see it -- a wait
 * would sleep on a response already here. So: a client with buffered bytes is ready at once, no poll; and after a
 * response is read, every one still buffered is read with it.
 *
 * A client whose connection closes or answers what IDLE cannot carry is dropped from the Watch, and named in
 * failures(); the others go on. RFC 2177 asks for IDLE to be re-issued at least every 29 minutes: end() and add()
 * again, under that.
 */
final class Watch
{
    /** @var array<string, Client> */
    private array $clients = [];

    /** @var array<string, ConnectionClosed|ProtocolError> */
    private array $failures = [];

    public function __construct(private readonly Poller $poller = new IoPoller())
    {
    }

    /** The client starts to IDLE here, on its selected folder. */
    public function add(string $key, Client $client): void
    {
        if (isset($this->clients[$key])) {
            throw new LogicException('A client is already watched as "' . $key . '".');
        }
        $client->beginIdle();
        $this->clients[$key] = $client;
    }

    /** @return array<string, list<Response>> the responses that came, by key; [] when $seconds passed first */
    public function wait(float $seconds): array
    {
        $ready = [];
        foreach ($this->clients as $key => $client) {
            if ($client->buffered()) {
                $ready[] = $key;
            }
        }
        if ([] === $ready && [] !== $this->clients) {
            $ready = $this->poller->readable(
                array_map(static fn (Client $client): mixed => $client->stream(), $this->clients),
                $seconds,
            );
        }
        $came = [];
        foreach ($ready as $key) {
            $responses = $this->drain($key);
            if ([] !== $responses) {
                $came[$key] = $responses;
            }
        }

        return $came;
    }

    /**
     * Each client ends its IDLE (DONE) and leaves the Watch; what came after the last wait is returned by key.
     *
     * @return array<string, list<Response>>
     */
    public function end(): array
    {
        $last = [];
        foreach (array_keys($this->clients) as $key) {
            $last[$key] = $this->clients[$key]->endIdle();
            unset($this->clients[$key]);
        }

        return $last;
    }

    /** @return array<string, ConnectionClosed|ProtocolError> the clients dropped, and why */
    public function failures(): array
    {
        return $this->failures;
    }

    /** @return list<Response> one response, and every one already buffered behind it */
    private function drain(string $key): array
    {
        $client = $this->clients[$key];
        try {
            $responses = [$client->idleNext()];
            while ($client->buffered()) {
                $responses[] = $client->idleNext();
            }
        } catch (ConnectionClosed|ProtocolError $failed) {
            $this->failures[$key] = $failed;
            unset($this->clients[$key]);

            return [];
        }

        return $responses;
    }
}
