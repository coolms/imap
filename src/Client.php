<?php

declare(strict_types=1);

namespace CoolMS\Imap;

use Closure;
use CoolMS\Imap\Exception\CommandFailed;
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
use CoolMS\Imap\Value\CopyUid;
use CoolMS\Imap\Value\FetchedItems;
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

use function array_filter;
use function array_map;
use function array_slice;
use function array_values;
use function count;
use function in_array;
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

    /** @var list<string> */
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
        $reply = $this->connection()->authenticate($mechanism, $this->capabilities->has('SASL-IR'));
        $this->signedIn('AUTHENTICATE ' . $mechanism->name(), $reply);
    }

    public function login(string $username, #[SensitiveParameter] string $password): void
    {
        $this->unauthenticated();
        if ($this->capabilities->has('LOGINDISABLED')) {
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
     * asked for at all.
     *
     * @return list<string>
     */
    public function enable(string ...$extensions): array
    {
        $this->authenticatedOnly();
        $offered = array_values(array_filter(
            $extensions,
            fn (string $extension): bool => $this->capabilities->has($extension),
        ));
        if ([] === $offered || !$this->capabilities->has('ENABLE')) {
            return [];
        }
        $reply = $this->ok(new Command('ENABLE', ...array_map(Argument::atom(...), $offered)));
        foreach ($reply->data('ENABLED') as $enabled) {
            foreach (array_slice($enabled->data, 1) as $token) {
                $this->enabled[] = strtoupper($token->string());
            }
        }

        return $this->enabled;
    }

    public function enabled(string $extension): bool
    {
        return in_array(strtoupper($extension), $this->enabled, true);
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
        if ($this->capabilities->has('SPECIAL-USE') && $this->capabilities->has('LIST-EXTENDED')) {
            $arguments[] = Argument::atom('RETURN');
            $arguments[] = Argument::list(Argument::atom('SPECIAL-USE'));
        }

        return array_map(Folder::fromList(...), $this->ok(new Command('LIST', ...$arguments))->data('LIST'));
    }

    public function counts(FolderName $folder): FolderCounts
    {
        $this->authenticatedOnly();
        $items = [Argument::atom('MESSAGES'), Argument::atom('UNSEEN'), Argument::atom('UIDNEXT'), Argument::atom('UIDVALIDITY')];
        if ($this->capabilities->has('CONDSTORE')) {
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
     * UID FETCH of the items named -- UID, FLAGS, MODSEQ, RFC822.SIZE, BODY.PEEK[...] -- with CHANGEDSINCE only the
     * messages changed after that mod-sequence (RFC 7162 s.3.1.4), and with VANISHED also the UIDs expunged since
     * (s.3.2.6; QRESYNC enabled). The server may add a FETCH for a message it was not asked for -- another client's
     * change -- so a caller goes by each message's UID, never by position.
     *
     * @param list<string> $items
     */
    public function uidFetch(UidSet $uids, array $items, ?int $changedSince = null, bool $vanished = false): FetchResult
    {
        $this->selectedOnly();
        if ($vanished && (null === $changedSince || !$this->enabled('QRESYNC'))) {
            throw new LogicException('VANISHED needs CHANGEDSINCE and QRESYNC enabled.');
        }
        $this->modseqs($changedSince);
        $arguments = [Argument::uids($uids), Argument::list(...array_map(Argument::raw(...), ['UID', ...$items]))];
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
     * UID MOVE (RFC 6851): the messages leave this folder for that one, and COPYUID -- where the server gives it --
     * says the UIDs they have there. Without MOVE it is refused: the fallback, COPY then \Deleted then EXPUNGE,
     * deletes mail, which this library does not do.
     */
    public function uidMove(UidSet $uids, FolderName $to): ?CopyUid
    {
        $this->selectedOnly();
        if (!$this->capabilities->has('MOVE')) {
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
        if (!$this->capabilities->has('IDLE')) {
            throw new LogicException('The server has no IDLE.');
        }
        $reply = $this->connection()->idle($each, $seconds);
        if (!$reply->ok()) {
            throw new CommandFailed('IDLE', $reply->completion);
        }
    }

    public function selected(): ?SelectedFolder
    {
        return $this->selected;
    }

    public function logout(): void
    {
        $this->connection()->run(new Command('LOGOUT'));
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
        return $this->capabilities->has('LITERAL+');
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
        if (null !== $modseq && !$this->capabilities->has('CONDSTORE')) {
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
