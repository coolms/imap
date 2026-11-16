<?php

declare(strict_types=1);

namespace CoolMS\Imap\Value;

use CoolMS\Imap\Exception\ProtocolError;
use CoolMS\Imap\Protocol\Token;
use CoolMS\Imap\Protocol\TokenKind;

use function count;

/**
 * A message's ENVELOPE (RFC 9051 s.7.5.2): the header fields the server parsed for it, without the message's body.
 *
 * Strings are kept as sent: a subject or a display name may still be RFC 2047 encoded words, and the date is the
 * Date header's text, not the time the server received the message (that is INTERNALDATE). An address list the
 * server sent as NIL is empty. A group ("undisclosed-recipients:;") contributes its members, never the group's name
 * as an address.
 */
final readonly class Envelope
{
    /**
     * @param list<Address> $from
     * @param list<Address> $sender
     * @param list<Address> $replyTo
     * @param list<Address> $to
     * @param list<Address> $cc
     * @param list<Address> $bcc
     */
    public function __construct(
        public ?string $date,
        public ?string $subject,
        public array $from,
        public array $sender,
        public array $replyTo,
        public array $to,
        public array $cc,
        public array $bcc,
        public ?string $inReplyTo,
        public ?string $messageId,
    ) {
    }

    public static function fromToken(Token $value): self
    {
        $items = TokenKind::List === $value->kind ? $value->items() : [];
        if (10 !== count($items)) {
            throw new ProtocolError('FETCH', 0, 'an ENVELOPE is a list of ten fields');
        }

        return new self(
            self::nstring($items[0]),
            self::nstring($items[1]),
            self::addresses($items[2]),
            self::addresses($items[3]),
            self::addresses($items[4]),
            self::addresses($items[5]),
            self::addresses($items[6]),
            self::addresses($items[7]),
            self::nstring($items[8]),
            self::nstring($items[9]),
        );
    }

    /** NIL, or a string the server sent quoted, as a literal or (leniently) as an atom. */
    public static function nstring(Token $value): ?string
    {
        return match ($value->kind) {
            TokenKind::Nil => null,
            TokenKind::Quoted, TokenKind::Literal, TokenKind::Atom, TokenKind::Number => $value->string(),
            default => throw new ProtocolError('FETCH', 0, 'a string or NIL where a list was sent'),
        };
    }

    /** @return list<Address> */
    private static function addresses(Token $value): array
    {
        if (TokenKind::Nil === $value->kind) {
            return [];
        }
        if (TokenKind::List !== $value->kind) {
            throw new ProtocolError('FETCH', 0, 'an address list is a list or NIL');
        }
        $out = [];
        foreach ($value->items() as $address) {
            $fields = TokenKind::List === $address->kind ? $address->items() : [];
            if (4 !== count($fields)) {
                throw new ProtocolError('FETCH', 0, 'an address is a list of four fields');
            }
            $mailbox = self::nstring($fields[2]);
            $host = self::nstring($fields[3]);
            // A group's start names the group in the mailbox field with no host; its end is all NIL.
            if (null === $host) {
                continue;
            }
            $out[] = new Address(self::nstring($fields[0]), $mailbox, $host);
        }

        return $out;
    }
}
