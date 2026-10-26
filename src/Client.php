<?php

declare(strict_types=1);

namespace CoolMS\Imap;

use Closure;
use CoolMS\Imap\Exception\CommandFailed;
use CoolMS\Imap\Exception\FolderLeftAside;
use CoolMS\Imap\Exception\FolderNotEmpty;
use CoolMS\Imap\Exception\ProtocolError;
use CoolMS\Imap\Protocol\Argument;
use CoolMS\Imap\Protocol\Command;
use CoolMS\Imap\Protocol\Response;
use CoolMS\Imap\Protocol\TokenKind;
use CoolMS\Imap\Sasl\Mechanism;
use CoolMS\Imap\Transport\Connection;
use CoolMS\Imap\Transport\Endpoint;
use CoolMS\Imap\Transport\Opened;
use CoolMS\Imap\Transport\Opener;
use CoolMS\Imap\Transport\Reply;
use CoolMS\Imap\Value\Capabilities;
use CoolMS\Imap\Value\Capability;
use CoolMS\Imap\Value\CopyUid;
use CoolMS\Imap\Value\FetchedItems;
use CoolMS\Imap\Value\FetchItem;
use CoolMS\Imap\Value\FetchResult;
use CoolMS\Imap\Value\Folder;
use CoolMS\Imap\Value\FolderCounts;
use CoolMS\Imap\Value\FolderName;
use CoolMS\Imap\Value\SelectedFolder;
use CoolMS\Imap\Value\StoreResult;
use CoolMS\Imap\Value\UidSet;
use CoolMS\Imap\Value\Vanished;
use LogicException;
use SensitiveParameter;
use Throwable;

use function array_filter;
use function array_map;
use function array_slice;
use function array_values;
use function bin2hex;
use function count;
use function in_array;
use function max;
use function random_bytes;
use function sprintf;
use function str_starts_with;
use function strcasecmp;
use function strtoupper;

/**
 * An IMAP session (RFC 9051 s.3): opened, then authenticated, then a folder selected. Each method sends one command
 * and returns what it means; a NO or BAD is a CommandFailed, a response that does not parse a ProtocolError.
 *
 * The capabilities are read again after authentication -- a server may offer more once it knows who is asking -- from
 * the completion's [CAPABILITY ...] code when it gives one, by asking when it does not.
 *
 * It never deletes mail: no EXPUNGE is sent and \Deleted is never stored. A message leaves a folder only by MOVE.
 */
final class Client
{
    private Capabilities $capabilities;
    private bool $authenticated;
    private ?SelectedFolder $selected = null;

    /** @var list<Capability> */
    private array $enabled = [];

    public function __construct(private readonly Opened $opened)
    {
        $this->capabilities = $opened->capabilities;
        $this->authenticated = $opened->preauthenticated();
    }

    public static function open(Endpoint $endpoint, ?Opener $opener = null): self
    {
        return new self(($opener ?? new Opener())->open($endpoint));
    }

    public function capabilities(): Capabilities
    {
        return $this->capabilities;
    }

    public function authenticate(Mechanism $mechanism): void
    {
        $this->unauthenticated();
        if (!$this->capabilities->has('AUTH=' . $mechanism->name())) {
            throw new LogicException('The server does not offer AUTH=' . $mechanism->name() . '.');
        }
        $reply = $this->connection()->authenticate($mechanism, $this->capabilities->has(Capability::SaslIr));
        $this->signedIn('AUTHENTICATE ' . $mechanism->name(), $reply);
    }

    public function login(string $username, #[SensitiveParameter] string $password): void
    {
        $this->unauthenticated();
        if ($this->capabilities->has(Capability::LoginDisabled)) {
            throw new LogicException('The server refuses LOGIN on this connection (LOGINDISABLED).');
        }
        $reply = $this->connection()->run(
            new Command('LOGIN', Argument::text($username), Argument::secret($password)),
            $this->literalPlus(),
        );
        $this->signedIn('LOGIN', $reply);
    }

    /**
     * ENABLE (RFC 5161): the extensions the server turned on, of those asked -- an extension it does not offer is not
     * asked for at all. An ENABLED that names one not asked for, or one this library has no name for, is passed over.
     *
     * @return list<Capability>
     */
    public function enable(Capability ...$extensions): array
    {
        $this->authenticatedOnly();
        $offered = array_values(array_filter(
            $extensions,
            fn (Capability $extension): bool => $this->capabilities->has($extension),
        ));
        if ([] === $offered || !$this->capabilities->has(Capability::Enable)) {
            return [];
        }
        $reply = $this->ok(new Command(
            'ENABLE',
            ...array_map(static fn (Capability $extension): Argument => Argument::atom($extension->value), $offered),
        ));
        foreach ($reply->data('ENABLED') as $enabled) {
            foreach (array_slice($enabled->data, 1) as $token) {
                $named = TokenKind::Atom === $token->kind ? Capability::tryFrom(strtoupper($token->string())) : null;
                if (null !== $named && in_array($named, $offered, true) && !in_array($named, $this->enabled, true)) {
                    $this->enabled[] = $named;
                }
            }
        }

        return $this->enabled;
    }

    public function enabled(Capability $extension): bool
    {
        return in_array($extension, $this->enabled, true);
    }

    /**
     * Every folder, with its role: LIST "" "*" RETURN (SPECIAL-USE) where the server offers it (RFC 6154 s.2), a plain
     * LIST where not -- whose attributes still carry the roles on servers that send them unasked.
     *
     * @return list<Folder>
     */
    public function folders(): array
    {
        $this->authenticatedOnly();
        $arguments = [Argument::text(''), Argument::text('*')];
        if ($this->capabilities->has(Capability::SpecialUse) && $this->capabilities->has(Capability::ListExtended)) {
            $arguments[] = Argument::atom('RETURN');
            $arguments[] = Argument::list(Argument::atom('SPECIAL-USE'));
        }

        return array_map(Folder::fromList(...), $this->ok(new Command('LIST', ...$arguments))->data('LIST'));
    }

    public function counts(FolderName $folder): FolderCounts
    {
        $this->authenticatedOnly();
        $items = [Argument::atom('MESSAGES'), Argument::atom('UNSEEN'), Argument::atom('UIDNEXT'), Argument::atom('UIDVALIDITY')];
        if ($this->capabilities->has(Capability::Condstore)) {
            $items[] = Argument::atom('HIGHESTMODSEQ');
        }
        $status = $this->ok(
            new Command('STATUS', Argument::folder($folder), Argument::list(...$items)),
        )->data('STATUS');
        if ([] === $status) {
            throw new ProtocolError('STATUS', 0, 'a STATUS that was OK gave no counts');
        }

        return FolderCounts::fromStatus($status[0]);
    }

    /** SELECT, or EXAMINE when only reading; with CONDSTORE enabled the server reports HIGHESTMODSEQ. */
    public function select(FolderName $folder, bool $readOnly = false): SelectedFolder
    {
        $this->authenticatedOnly();
        $this->selected = null;
        $command = new Command($readOnly ? 'EXAMINE' : 'SELECT', Argument::folder($folder));

        return $this->selected = SelectedFolder::fromReply($this->ok($command));
    }

    /** CREATE a folder (RFC 9051 s.6.3.4); a server's refusal, such as [ALREADYEXISTS], is a CommandFailed. */
    public function create(FolderName $folder): void
    {
        $this->authenticatedOnly();
        $this->ok(new Command('CREATE', Argument::folder($folder)));
    }

    /**
     * RENAME a folder, with the folders inside it (RFC 9051 s.6.3.6). Never the INBOX: renaming it moves its mail to a
     * new folder and leaves an empty INBOX, which no caller of this library means.
     */
    public function rename(FolderName $from, FolderName $to): void
    {
        $this->authenticatedOnly();
        self::notTheInbox($from, 'renamed');
        $this->ok(new Command('RENAME', Argument::folder($from), Argument::folder($to)));
    }

    public function subscribe(FolderName $folder): void
    {
        $this->authenticatedOnly();
        $this->ok(new Command('SUBSCRIBE', Argument::folder($folder)));
    }

    public function unsubscribe(FolderName $folder): void
    {
        $this->authenticatedOnly();
        $this->ok(new Command('UNSUBSCRIBE', Argument::folder($folder)));
    }

    /**
     * DELETE a folder only when it holds nothing: a server deletes a folder's mail with it (RFC 9051 s.6.3.5), and this
     * library deletes no mail. The folder is first renamed to a name only this client knows, so no other client can put
     * mail in it while it is looked at; it is deleted when it holds no message and no folder there, and otherwise put
     * back under its own name and reported as FolderNotEmpty.
     */
    public function deleteEmpty(FolderName $folder): void
    {
        $this->authenticatedOnly();
        self::notTheInbox($folder, 'deleted');
        $aside = FolderName::fromWire('coolms-removing-' . bin2hex(random_bytes(8)));
        $this->ok(new Command('RENAME', Argument::folder($folder), Argument::folder($aside)));
        try {
            $listed = $this->ok(new Command('LIST', Argument::text(''), Argument::text($aside->wire . '*')))->data('LIST');
            $inside = max(0, count($listed) - 1);
            $messages = null;
            if (0 === $inside) {
                $status = $this->ok(new Command('STATUS', Argument::folder($aside), Argument::list(Argument::atom('MESSAGES'))))
                    ->data('STATUS');
                $messages = [] === $status ? null : FolderCounts::fromStatus($status[0])->messages;
            }
            if (0 === $inside && 0 === $messages) {
                $this->ok(new Command('DELETE', Argument::folder($aside)));

                return;
            }
        } catch (Throwable $failed) {
            // The look or the delete failed: the folder goes back under its own name, and the failure is said.
            $this->putBack($folder, $aside, $failed);

            throw $failed;
        }
        try {
            $this->ok(new Command('RENAME', Argument::folder($aside), Argument::folder($folder)));
        } catch (CommandFailed) {
            throw new FolderNotEmpty($folder, $messages ?? 0, $inside, $aside);
        }

        throw new FolderNotEmpty($folder, $messages ?? 0, $inside);
    }

    /**
     * APPEND a message to a folder (RFC 9051 s.6.3.12); with UIDPLUS (RFC 4315) the UID it got, from [APPENDUID
     * uidvalidity uid] -- null when the server did not say.
     *
     * @param list<string> $flags
     */
    public function append(FolderName $folder, string $message, array $flags = []): ?int
    {
        $this->authenticatedOnly();
        foreach ($flags as $flag) {
            if (0 === strcasecmp($flag, '\Deleted')) {
                throw new LogicException('\Deleted is never stored: this library does not delete mail.');
            }
        }
        $reply = $this->ok(new Command(
            'APPEND',
            Argument::folder($folder),
            Argument::list(...array_map(Argument::atom(...), $flags)),
            Argument::literal($message),
        ));
        $code = $reply->completion->code?->items() ?? [];
        if (
            3 === count($code)
            && 'APPENDUID' === strtoupper($code[0]->string())
            && TokenKind::Number === $code[2]->kind
        ) {
            return (int) $code[2]->value;
        }

        return null;
    }

    /**
     * UID SEARCH: the UIDs matching, in the order given. A trailing "(MODSEQ n)" (RFC 7162 s.3.1.5) is not a UID.
     *
     * @return list<int>
     */
    public function uidSearch(Argument ...$criteria): array
    {
        $this->selectedOnly();
        $uids = [];
        foreach ($this->ok(new Command('UID SEARCH', ...$criteria))->data('SEARCH') as $search) {
            foreach (array_slice($search->data, 1) as $token) {
                if (TokenKind::List === $token->kind) {
                    continue;
                }
                $uids[] = TokenKind::Number === $token->kind
                    ? (int) $token->value
                    : throw new ProtocolError('SEARCH', 0, 'a SEARCH result is a number');
            }
        }

        return $uids;
    }

    /**
     * UID FETCH of the items named -- FetchItem::flags(), ::modseq(), ::body(Section::header()), ... -- the UID always
     * among them, asked once; with CHANGEDSINCE only the
     * messages changed after that mod-sequence (RFC 7162 s.3.1.4), and with VANISHED also the UIDs expunged since
     * (s.3.2.6; QRESYNC enabled). The server may add a FETCH for a message it was not asked for -- another client's
     * change -- so a caller goes by each message's UID, never by position.
     *
     * @param list<FetchItem> $items
     */
    public function uidFetch(UidSet $uids, array $items, ?int $changedSince = null, bool $vanished = false): FetchResult
    {
        $this->selectedOnly();
        if ($vanished && (null === $changedSince || !$this->enabled(Capability::Qresync))) {
            throw new LogicException('VANISHED needs CHANGEDSINCE and QRESYNC enabled.');
        }
        $this->modseqs($changedSince);
        $wire = ['UID'];
        foreach ($items as $item) {
            if ($item->isGmail() && !$this->capabilities->has(Capability::GmailExtensions)) {
                throw new LogicException('The server has no X-GM-EXT-1: ' . $item->wire . ' is Gmail\'s.');
            }
            if (!in_array($item->wire, $wire, true)) {
                $wire[] = $item->wire;
            }
        }
        $arguments = [Argument::uids($uids), Argument::list(...array_map(Argument::raw(...), $wire))];
        if (null !== $changedSince) {
            $modifiers = [Argument::atom('CHANGEDSINCE'), Argument::number($changedSince)];
            if ($vanished) {
                $modifiers[] = Argument::atom('VANISHED');
            }
            $arguments[] = Argument::list(...$modifiers);
        }
        $reply = $this->ok(new Command('UID FETCH', ...$arguments));

        return new FetchResult(
            array_map(FetchedItems::fromFetch(...), $reply->data('FETCH')),
            array_map(Vanished::fromResponse(...), $reply->data('VANISHED')),
        );
    }

    /**
     * UID STORE of flags -- "+" adds, "-" removes, "" replaces -- silent, and with UNCHANGEDSINCE only where no other
     * client changed the message after that mod-sequence (RFC 7162 s.3.1.3).
     *
     * \Deleted is refused: this library never marks a message for EXPUNGE, and never expunges.
     *
     * @param list<string> $flags
     */
    public function uidStore(UidSet $uids, string $how, array $flags, ?int $unchangedSince = null): StoreResult
    {
        $this->selectedOnly();
        foreach ($flags as $flag) {
            if (0 === strcasecmp($flag, '\Deleted')) {
                throw new LogicException('\Deleted is never stored: this library does not delete mail.');
            }
        }
        $this->modseqs($unchangedSince);
        $item = match ($how) {
            '+', '-', '' => $how . 'FLAGS.SILENT',
            default => throw new LogicException('A store adds ("+"), removes ("-") or replaces ("").'),
        };
        $arguments = [Argument::uids($uids)];
        if (null !== $unchangedSince) {
            $arguments[] = Argument::list(Argument::atom('UNCHANGEDSINCE'), Argument::number($unchangedSince));
        }
        $arguments[] = Argument::atom($item);
        $arguments[] = Argument::list(...array_map(Argument::atom(...), $flags));
        $reply = $this->ok(new Command('UID STORE', ...$arguments));
        $code = $reply->completion->code?->items() ?? [];
        $notChanged = null;
        if ([] !== $code && 'MODIFIED' === strtoupper($code[0]->string())) {
            $notChanged = isset($code[1])
                ? UidSet::parse($code[1]->string())
                : throw new ProtocolError('MODIFIED', 0, 'MODIFIED names a set');
        }

        return new StoreResult(array_map(FetchedItems::fromFetch(...), $reply->data('FETCH')), $notChanged);
    }

    /**
     * UID STORE of Gmail labels (X-GM-EXT-1) -- "+" adds, "-" removes, "" replaces -- silent. A system label is a
     * string starting with a backslash (\Inbox, \Important, \Starred); any other label is a folder, by its name.
     * Taking \Inbox away archives the message; it stays in All Mail.
     *
     * Adding \Trash or \Spam -- or the label of either folder -- puts the message where Gmail empties it after 30 days,
     * as uidMove() into that folder does. The library's rule is the one it keeps everywhere: it never stores \Deleted
     * and never expunges; where a message goes is the caller's choice.
     *
     * @param list<FolderName|string> $labels
     */
    public function uidStoreLabels(UidSet $uids, string $how, array $labels): StoreResult
    {
        $this->selectedOnly();
        if (!$this->capabilities->has(Capability::GmailExtensions)) {
            throw new LogicException('The server has no X-GM-EXT-1: there are no labels to store.');
        }
        $item = match ($how) {
            '+', '-', '' => $how . 'X-GM-LABELS.SILENT',
            default => throw new LogicException('A store adds ("+"), removes ("-") or replaces ("").'),
        };
        $wire = [];
        foreach ($labels as $label) {
            if ($label instanceof FolderName) {
                $wire[] = Argument::folder($label);

                continue;
            }
            if (!str_starts_with($label, '\\')) {
                throw new LogicException('"' . $label . '" is no system label: name a folder by its FolderName.');
            }
            $wire[] = Argument::atom($label);
        }
        $store = new Command('UID STORE', Argument::uids($uids), Argument::atom($item), Argument::list(...$wire));
        $reply = $this->ok($store);

        return new StoreResult(array_map(FetchedItems::fromFetch(...), $reply->data('FETCH')), null);
    }

    /**
     * UID MOVE (RFC 6851): the messages leave this folder for that one, and COPYUID -- where the server gives it --
     * says the UIDs they have there. Without MOVE it is refused: the fallback, COPY then \Deleted then EXPUNGE,
     * deletes mail, which this library does not do.
     */
    public function uidMove(UidSet $uids, FolderName $to): ?CopyUid
    {
        $this->selectedOnly();
        if (!$this->capabilities->has(Capability::Move)) {
            throw new LogicException('The server has no MOVE, and this library does not delete mail to move it.');
        }
        $reply = $this->ok(new Command('UID MOVE', Argument::uids($uids), Argument::folder($to)));
        foreach ([...$reply->untagged, $reply->completion] as $response) {
            $copied = CopyUid::fromCode($response->code);
            if (null !== $copied) {
                return $copied;
            }
        }

        return null;
    }

    /**
     * IDLE on the selected folder: each response as it comes, until $each returns false or $seconds pass. RFC 2177
     * asks a client to re-issue IDLE at least every 29 minutes; the caller's $seconds should stay under that.
     *
     * @param Closure(Response): bool $each
     */
    public function idle(Closure $each, float $seconds): void
    {
        $this->selectedOnly();
        if (!$this->capabilities->has(Capability::Idle)) {
            throw new LogicException('The server has no IDLE.');
        }
        $reply = $this->connection()->idle($each, $seconds);
        if (!$reply->ok()) {
            throw new CommandFailed('IDLE', $reply->completion);
        }
    }

    /**
     * IDLE in pieces, for a Watch that waits on several clients at once (CoolMS\Imap\Watch): begin, then each
     * response as the Watch reads it, then end. Ending reads the IDLE's completion; a NO or BAD is a CommandFailed.
     */
    public function beginIdle(): void
    {
        $this->selectedOnly();
        if (!$this->capabilities->has(Capability::Idle)) {
            throw new LogicException('The server has no IDLE.');
        }
        $refused = $this->connection()->beginIdle();
        if (null !== $refused) {
            throw new CommandFailed('IDLE', $refused->completion);
        }
    }

    /** The next response while idling; it blocks until one comes -- a Watch asks only when one is there. */
    public function idleNext(): Response
    {
        return $this->connection()->idleNext();
    }

    /** @return list<Response> what came between the last response read and the IDLE's completion */
    public function endIdle(): array
    {
        $reply = $this->connection()->endIdle();
        if (!$reply->ok()) {
            throw new CommandFailed('IDLE', $reply->completion);
        }

        return $reply->untagged;
    }

    /** Renews the IDLE without waiting: DONE now, and the rest through {@see idleRead()} (see Connection::renewIdle()). */
    public function renewIdle(): void
    {
        $this->connection()->renewIdle();
    }

    /** Whether a renewal is under way: its DONE or its new IDLE not answered yet. */
    public function renewing(): bool
    {
        return $this->connection()->renewing();
    }

    /**
     * The next response while idling, or null for a step of a renewal; it blocks until something comes, so a Watch
     * asks only when something is there. A refused renewal is a CommandFailed.
     */
    public function idleRead(): ?Response
    {
        return $this->connection()->idleRead();
    }

    /** Whether a response is already read off the socket and waiting in PHP's buffer (see Connection::buffered()). */
    public function buffered(): bool
    {
        return $this->connection()->buffered();
    }

    /** @return resource the connection's stream, for a Watch to poll */
    public function stream(): mixed
    {
        return $this->connection()->stream();
    }

    public function selected(): ?SelectedFolder
    {
        return $this->selected;
    }

    public function logout(): void
    {
        $this->connection()->run(new Command('LOGOUT'));
    }

    private static function notTheInbox(FolderName $folder, string $what): void
    {
        if (0 === strcasecmp($folder->wire, 'INBOX')) {
            throw new LogicException(sprintf('The INBOX is never %s.', $what));
        }
    }

    /** Puts a folder renamed aside back under its own name; when that fails too, says where it is. */
    private function putBack(FolderName $folder, FolderName $aside, Throwable $failed): void
    {
        try {
            $this->ok(new Command('RENAME', Argument::folder($aside), Argument::folder($folder)));
        } catch (Throwable) {
            throw new FolderLeftAside($folder, $aside, $failed);
        }
    }

    private function signedIn(string $command, Reply $reply): void
    {
        if (!$reply->ok()) {
            throw new CommandFailed($command, $reply->completion);
        }
        $this->authenticated = true;
        $code = $reply->completion->code?->items() ?? [];
        $this->capabilities = [] !== $code
            && TokenKind::Atom === $code[0]->kind
            && 'CAPABILITY' === strtoupper($code[0]->string())
            ? Capabilities::fromTokens(...$code)
            : $this->askedCapabilities();
    }

    private function askedCapabilities(): Capabilities
    {
        $data = $this->ok(new Command('CAPABILITY'))->data('CAPABILITY');

        return [] === $data
            ? throw new ProtocolError('CAPABILITY', 0, 'a CAPABILITY that was OK named none') : Capabilities::fromTokens(...$data[0]->data);
    }

    private function ok(Command $command): Reply
    {
        $reply = $this->connection()->run($command, $this->literalPlus());

        return $reply->ok() ? $reply : throw new CommandFailed($command->described(), $reply->completion);
    }

    private function literalPlus(): bool
    {
        return $this->capabilities->has(Capability::LiteralPlus);
    }

    private function unauthenticated(): void
    {
        if ($this->authenticated) {
            throw new LogicException('The session is authenticated already.');
        }
    }

    private function authenticatedOnly(): void
    {
        if (!$this->authenticated) {
            throw new LogicException('Authenticate first.');
        }
    }

    /** A mod-sequence in a command needs CONDSTORE: refused here, not left to the server's BAD. */
    private function modseqs(?int $modseq): void
    {
        if (null !== $modseq && !$this->capabilities->has(Capability::Condstore)) {
            throw new LogicException('The server has no CONDSTORE: nothing can be asked by mod-sequence.');
        }
    }

    private function selectedOnly(): void
    {
        if (null === $this->selected) {
            throw new LogicException('Select a folder first.');
        }
    }

    private function connection(): Connection
    {
        return $this->opened->connection;
    }
}
