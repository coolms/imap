<?php

declare(strict_types=1);

namespace CoolMS\Imap\Transport;

use CoolMS\Imap\Protocol\Response;
use CoolMS\Imap\Protocol\ResponseKind;
use CoolMS\Imap\Protocol\TokenKind;

use function count;
use function strcasecmp;

/** What one command got back: its tagged completion, and every untagged response that came before it, in order. */
final readonly class Reply
{
    /** @param list<Response> $untagged */
    public function __construct(
        public Response $completion,
        public array $untagged,
    ) {
    }

    public function ok(): bool
    {
        return 'OK' === $this->completion->status;
    }

    /**
     * The untagged data named so: "* NAME ..." -- CAPABILITY, LIST, SEARCH, ESEARCH -- or "* n NAME ..." -- EXISTS,
     * FETCH, EXPUNGE. Case does not matter; the order the server sent them in is kept.
     *
     * @return list<Response>
     */
    public function data(string $name): array
    {
        $found = [];
        foreach ($this->untagged as $response) {
            if (ResponseKind::UntaggedData !== $response->kind || [] === $response->data) {
                continue;
            }
            $first = $response->data[0];
            $named = TokenKind::Number === $first->kind && count($response->data) > 1 ? $response->data[1] : $first;
            if (TokenKind::Atom === $named->kind && 0 === strcasecmp($named->string(), $name)) {
                $found[] = $response;
            }
        }

        return $found;
    }
}
