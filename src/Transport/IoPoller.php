<?php

declare(strict_types=1);

namespace CoolMS\Imap\Transport;

use Io\Poll\Context;
use Io\Poll\Event;
use StreamPollHandle;
use Time\Duration;

use function in_array;
use function is_string;
use function max;

/**
 * Io\Poll (PHP 8.6; symfony/polyfill-io-poll before it, on stream_select()): one context per wait, a watcher for each
 * stream, read readiness -- a hang-up or an error counts too, since the read is what tells what happened.
 */
final class IoPoller implements Poller
{
    public function readable(array $streams, float $seconds): array
    {
        $context = new Context();
        foreach ($streams as $key => $stream) {
            $context->add(new StreamPollHandle($stream), [Event::Read], $key);
        }
        $ready = [];
        foreach ($context->wait(Duration::fromNanoseconds((int) max(0, $seconds * 1e9))) as $watcher) {
            $key = $watcher->getData();
            $events = $watcher->getTriggeredEvents();
            if (
                is_string($key)
                && (in_array(Event::Read, $events, true) || in_array(Event::HangUp, $events, true)
                    || in_array(Event::Error, $events, true))
            ) {
                $ready[] = $key;
            }
        }

        return $ready;
    }
}
