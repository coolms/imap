<?php

declare(strict_types=1);

namespace CoolMS\Imap\Value;

use CoolMS\Imap\Exception\ProtocolError;
use CoolMS\Imap\Protocol\Token;
use CoolMS\Imap\Protocol\TokenKind;

use function array_map;
use function count;
use function explode;
use function implode;
use function preg_match;
use function rawurldecode;
use function strtolower;

/**
 * One part of a message's BODYSTRUCTURE (RFC 9051 s.7.5.2), and through its parts the whole tree: what each part is,
 * how large, how it is encoded, and its section -- the name BODY.PEEK[<section>] fetches it by -- without fetching a
 * byte of it.
 *
 * Sections are numbered as the RFC numbers them: a message that is not multipart has one part, "1"; a multipart's
 * parts are "1", "2", ..., a nested one's "2.1", "2.2"; the message inside a message/rfc822 part "3" has its parts
 * under "3.1". A multipart itself is never fetched by its number here, so its section is that of its place in the
 * tree ("" at the top).
 *
 * Type, subtype, encoding, disposition and parameter names are lower case; parameter values are kept as sent (a
 * file name may be RFC 2047 encoded words; RFC 2231's "filename*" is decoded by filename()).
 */
final readonly class BodyPart
{
    /**
     * @param array<string, string> $params
     * @param array<string, string> $dispositionParams
     * @param list<BodyPart>        $parts
     */
    public function __construct(
        public string $section,
        public string $type,
        public string $subtype,
        public array $params,
        public ?string $id,
        public ?string $description,
        public ?string $encoding,
        public int $size,
        public ?int $lines,
        public ?string $disposition,
        public array $dispositionParams,
        public array $parts,
        public ?Envelope $envelope = null,
        public ?self $message = null,
    ) {
    }

    public static function fromToken(Token $value): self
    {
        return self::parse($value, '', true);
    }

    /** The section that fetches this part's bytes (FetchItem::body()); the whole text for a multipart at the top. */
    public function fetchSection(): Section
    {
        return '' === $this->section
            ? Section::text()
            : Section::part(...array_map('intval', explode('.', $this->section)));
    }

    public function isMultipart(): bool
    {
        return 'multipart' === $this->type;
    }

    public function mimeType(): string
    {
        return $this->type . '/' . $this->subtype;
    }

    /** The part's file name: the disposition's, else the content type's "name"; RFC 2231 continuations decoded. */
    public function filename(): ?string
    {
        return self::named($this->dispositionParams, 'filename') ?? self::named($this->params, 'name');
    }

    /** A part the sender attached: disposition "attachment", or a named part that is not text to read inline. */
    public function isAttachment(): bool
    {
        if ($this->isMultipart()) {
            return false;
        }
        if ('attachment' === $this->disposition) {
            return true;
        }

        return 'inline' !== $this->disposition && null !== $this->filename() && 'text' !== $this->type;
    }

    /** @return list<BodyPart> every part that is not a multipart, in the order the message holds them */
    public function leaves(): array
    {
        if (!$this->isMultipart()) {
            return [$this];
        }
        $out = [];
        foreach ($this->parts as $part) {
            foreach ($part->leaves() as $leaf) {
                $out[] = $leaf;
            }
        }

        return $out;
    }

    /** The first text/<subtype> part that is not an attachment (a forwarded message's own parts are not looked in). */
    public function firstText(string $subtype): ?self
    {
        foreach ($this->leaves() as $leaf) {
            if ('text' === $leaf->type && $subtype === $leaf->subtype && !$leaf->isAttachment()) {
                return $leaf;
            }
        }

        return null;
    }

    /** @return list<BodyPart> the attachments, in order (a forwarded message counts as one) */
    public function attachments(): array
    {
        $out = [];
        foreach ($this->leaves() as $leaf) {
            if ($leaf->isAttachment() || 'message/rfc822' === $leaf->mimeType()) {
                $out[] = $leaf;
            }
        }

        return $out;
    }

    private static function parse(Token $value, string $section, bool $top): self
    {
        $items = TokenKind::List === $value->kind ? $value->items() : [];
        if ([] === $items) {
            throw new ProtocolError('FETCH', 0, 'a body structure is a non-empty list');
        }
        if (TokenKind::List === $items[0]->kind) {
            return self::multipart($items, $section);
        }

        return self::single($items, $top ? '1' : $section);
    }

    /** @param list<Token> $items */
    private static function multipart(array $items, string $section): self
    {
        $parts = [];
        $i = 0;
        while ($i < count($items) && TokenKind::List === $items[$i]->kind) {
            $number = (string) ($i + 1);
            $parts[] = self::parse($items[$i], '' === $section ? $number : $section . '.' . $number, false);
            ++$i;
        }
        $subtype = isset($items[$i]) ? self::lower($items[$i], 'a multipart subtype') : 'mixed';
        $params = isset($items[$i + 1]) ? self::params($items[$i + 1]) : [];
        [$disposition, $dispositionParams] = isset($items[$i + 2]) ? self::disposition($items[$i + 2]) : [null, []];

        return new self(
            $section,
            'multipart',
            $subtype,
            $params,
            null,
            null,
            null,
            0,
            null,
            $disposition,
            $dispositionParams,
            $parts,
        );
    }

    /** @param list<Token> $items */
    private static function single(array $items, string $section): self
    {
        if (count($items) < 7) {
            throw new ProtocolError('FETCH', 0, 'a body part has at least seven fields');
        }
        $type = self::lower($items[0], 'a media type');
        $subtype = self::lower($items[1], 'a media subtype');
        $size = TokenKind::Number === $items[6]->kind ? (int) $items[6]->value
            : throw new ProtocolError('FETCH', 0, 'a body part size is a number');
        $next = 7;
        $lines = null;
        $envelope = null;
        $message = null;
        if ('message' === $type && ('rfc822' === $subtype || 'global' === $subtype) && count($items) >= 10) {
            $envelope = Envelope::fromToken($items[7]);
            $inner = TokenKind::List === $items[8]->kind ? $items[8]->items() : [];
            if ([] === $inner) {
                throw new ProtocolError('FETCH', 0, 'a message/rfc822 part carries the body structure of its message');
            }
            // The inner message's parts are numbered under this part: a multipart's as 3.1, 3.2; a single one as 3.1.
            $message = TokenKind::List === $inner[0]->kind
                ? self::multipart($inner, $section)
                : self::single($inner, $section . '.1');
            $lines = self::number($items[9]);
            $next = 10;
        } elseif ('text' === $type && isset($items[7])) {
            $lines = self::number($items[7]);
            $next = 8;
        }
        // body-ext-1part: md5, then the disposition.
        [$disposition, $dispositionParams] = isset($items[$next + 1]) ? self::disposition($items[$next + 1]) : [null, []];

        return new self(
            $section,
            $type,
            $subtype,
            self::params($items[2]),
            Envelope::nstring($items[3]),
            Envelope::nstring($items[4]),
            null === ($e = Envelope::nstring($items[5])) ? null : strtolower($e),
            $size,
            $lines,
            $disposition,
            $dispositionParams,
            [],
            $envelope,
            $message,
        );
    }

    /** @return array<string, string> */
    private static function params(Token $value): array
    {
        if (TokenKind::Nil === $value->kind) {
            return [];
        }
        $items = TokenKind::List === $value->kind ? $value->items() : null;
        if (null === $items || 0 !== count($items) % 2) {
            throw new ProtocolError('FETCH', 0, 'body parameters are pairs of strings, or NIL');
        }
        $out = [];
        for ($i = 0; $i < count($items); $i += 2) {
            $out[strtolower($items[$i]->string())] = (string) Envelope::nstring($items[$i + 1]);
        }

        return $out;
    }

    /** @return array{?string, array<string, string>} */
    private static function disposition(Token $value): array
    {
        if (TokenKind::Nil === $value->kind) {
            return [null, []];
        }
        $items = TokenKind::List === $value->kind ? $value->items() : [];
        if (2 !== count($items)) {
            // Some servers send a bare string here; read what can be read rather than refuse the message.
            return TokenKind::Quoted === $value->kind ? [strtolower($value->string()), []] : [null, []];
        }

        return [strtolower($items[0]->string()), self::params($items[1])];
    }

    private static function number(Token $value): ?int
    {
        return TokenKind::Number === $value->kind ? (int) $value->value : null;
    }

    private static function lower(Token $value, string $what): string
    {
        $text = Envelope::nstring($value);

        return null === $text ? throw new ProtocolError('FETCH', 0, $what . ' is a string') : strtolower($text);
    }

    /**
     * A parameter by name: as sent, or from its RFC 2231 forms -- "name*" (charset''percent-encoded) or the
     * continuations "name*0", "name*1*", ... joined in order.
     *
     * @param array<string, string> $params
     */
    private static function named(array $params, string $name): ?string
    {
        if (isset($params[$name])) {
            return $params[$name];
        }
        if (isset($params[$name . '*'])) {
            return self::rfc2231($params[$name . '*'], true);
        }
        $pieces = [];
        for ($n = 0; isset($params[$name . '*' . $n]) || isset($params[$name . '*' . $n . '*']); ++$n) {
            $encoded = isset($params[$name . '*' . $n . '*']);
            $piece = $params[$name . '*' . $n . ($encoded ? '*' : '')];
            $pieces[] = $encoded ? self::rfc2231($piece, 0 === $n) : $piece;
        }

        return [] === $pieces ? null : implode('', $pieces);
    }

    /** Percent-decodes an RFC 2231 value; the first piece carries "charset'language'" ahead of it, which is dropped. */
    private static function rfc2231(string $value, bool $first): string
    {
        if ($first && 1 === preg_match("/\\A[^']*'[^']*'(.*)\\z/s", $value, $m)) {
            $value = $m[1];
        }

        return rawurldecode($value);
    }
}
