<?php

declare(strict_types=1);

namespace CoolMS\Imap;

use Closure;
use CoolMS\Imap\Exception\CommandFailed;
use CoolMS\Imap\Exception\ConnectionClosed;
use CoolMS\Imap\Exception\ProtocolError;
use CoolMS\Imap\Protocol\Response;
use CoolMS\Imap\Transport\IoPoller;
use CoolMS\Imap\Transport\Poller;
use LogicException;

use function array_keys;
use function array_map;
use function hrtime;
use function max;
use function min;
use function sprintf;

/**
 * Several clients IDLE at once, each on its own selected folder, and one wait for all of them (RFC 2177): wait()
 * returns the responses that came, by the key each client was added under.
 *
 * Before it polls, the Watch looks at what PHP already holds for each client. A TLS record can carry two responses;
 * once the first is read the second is in PHP's buffer, and a native poll() on the socket does not see it -- a wait
 * would sleep on a response already here. So: a client with buffered bytes is ready at once, no poll; and after a
 * response is read, every one still buffered is read with it.
 *
 * Each client's IDLE is renewed when its own `$renewAfter` is up (RFC 2177 asks for it at least every 29 minutes):
 * DONE, then IDLE again when the server's OK comes, without blocking and without ending the others. A wait never
 * sleeps past the next renewal, and a renewal not answered within `$answerWithin` drops that client as failed.
 *
 * A client whose connection closes, answers what IDLE cannot carry, or refuses a renewal is dropped from the Watch,
 * and named in failures(); the others go on. One client leaves with remove(), all of them with end().
 */
final class Watch
{
    /** @var array<string, Client> */
    private array $clients = [];

    /** @var array<string, float> when each client's IDLE was entered, in monotonic seconds */
    private array $since = [];

    /** @var array<string, float> when each renewal under way was started */
    private array $renewals = [];

    /** @var array<string, ConnectionClosed|ProtocolError|CommandFailed> */
    private array $failures = [];

    private readonly Closure $now;

    /** @param (Closure(): float)|null $now monotonic seconds; default hrtime */
    public function __construct(
        private readonly Poller $poller = new IoPoller(),
        private readonly float $renewAfter = 28 * 60.0,
        private readonly float $answerWithin = 30.0,
        ?Closure $now = null,
    ) {
        $this->now = $now ?? static fn (): float => hrtime(true) / 1e9;
    }

    /** The client starts to IDLE here, on its selected folder. */
    public function add(string $key, Client $client): void
    {
        if (isset($this->clients[$key])) {
            throw new LogicException('A client is already watched as "' . $key . '".');
        }
        $client->beginIdle();
        $this->clients[$key] = $client;
        $this->since[$key] = ($this->now)();
    }

    /**
     * Waits, renews what is due, and reads what came; it changes which clients are watched and renewing.
     *
     * @return array<string, list<Response>> the responses that came, by key; [] when $seconds passed first
     *
     * @phpstan-impure
     */
    public function wait(float $seconds): array
    {
        $this->tend();
        $ready = [];
        foreach ($this->clients as $key => $client) {
            if ($client->buffered()) {
                $ready[] = $key;
            }
        }
        if ([] === $ready && [] !== $this->clients) {
            $ready = $this->poller->readable(
                array_map(static fn (Client $client): mixed => $client->stream(), $this->clients),
                min($seconds, $this->untilNextDeadline()),
            );
        }
        $came = [];
        foreach ($ready as $key) {
            $responses = $this->drain($key);
            if ([] !== $responses) {
                $came[$key] = $responses;
            }
        }
        $this->tend();

        return $came;
    }

    /**
     * One client ends its IDLE (DONE) and leaves the Watch; what came after the last wait is returned. A client that
     * fails as it leaves is named in failures().
     *
     * @return list<Response>
     */
    public function remove(string $key): array
    {
        $client = $this->clients[$key] ?? throw new LogicException('No client is watched as "' . $key . '".');
        $this->forget($key);
        try {
            return $client->endIdle();
        } catch (ConnectionClosed|ProtocolError|CommandFailed $failed) {
            $this->failures[$key] = $failed;

            return [];
        }
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
            $last[$key] = $this->remove($key);
        }

        return $last;
    }

    /** @return list<string> the clients watched, by key */
    public function keys(): array
    {
        return array_keys($this->clients);
    }

    /** @return list<string> the clients whose renewal is under way, by key */
    public function renewing(): array
    {
        return array_keys($this->renewals);
    }

    /** @return array<string, ConnectionClosed|ProtocolError|CommandFailed> the clients dropped, and why */
    public function failures(): array
    {
        return $this->failures;
    }

    /** Starts the renewals that are due, and drops the clients whose renewal went unanswered too long. */
    private function tend(): void
    {
        $now = ($this->now)();
        foreach ($this->clients as $key => $client) {
            if (isset($this->renewals[$key])) {
                if ($now - $this->renewals[$key] > $this->answerWithin) {
                    $this->fail($key, new ConnectionClosed(sprintf(
                        'The IDLE renewal went unanswered for %.1f s.',
                        $now - $this->renewals[$key],
                    )));
                }
                continue;
            }
            if ($now - $this->since[$key] >= $this->renewAfter) {
                try {
                    $client->renewIdle();
                    $this->renewals[$key] = $now;
                } catch (ConnectionClosed $failed) {
                    $this->fail($key, $failed);
                }
            }
        }
    }

    /** Seconds to the nearest renewal due or renewal answer owed; never below zero. */
    private function untilNextDeadline(): float
    {
        $now = ($this->now)();
        $next = $this->renewAfter;
        foreach (array_keys($this->clients) as $key) {
            $deadline = isset($this->renewals[$key])
                ? $this->renewals[$key] + $this->answerWithin
                : $this->since[$key] + $this->renewAfter;
            $next = min($next, $deadline - $now);
        }

        return max(0.0, $next);
    }

    /** @return list<Response> one response, and every one already buffered behind it */
    private function drain(string $key): array
    {
        $client = $this->clients[$key];
        $responses = [];
        try {
            do {
                $response = $client->idleRead();
                if (null !== $response) {
                    $responses[] = $response;
                }
            } while ($client->buffered());
        } catch (ConnectionClosed|ProtocolError|CommandFailed $failed) {
            $this->fail($key, $failed);

            return [];
        }
        if (isset($this->renewals[$key]) && !$client->renewing()) {
            unset($this->renewals[$key]);
            $this->since[$key] = ($this->now)();
        }

        return $responses;
    }

    private function fail(string $key, ConnectionClosed|ProtocolError|CommandFailed $failed): void
    {
        $this->failures[$key] = $failed;
        $this->forget($key);
    }

    private function forget(string $key): void
    {
        unset($this->clients[$key], $this->since[$key], $this->renewals[$key]);
    }
}
