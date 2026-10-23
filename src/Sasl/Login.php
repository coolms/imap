<?php

declare(strict_types=1);

namespace CoolMS\Imap\Sasl;

use LogicException;
use SensitiveParameter;
use SensitiveParameterValue;

/**
 * The LOGIN mechanism (draft-murchison-sasl-login): no initial response; the server asks for the user name, then the
 * password. The challenges' wording varies by server ("Username:", "User Name"), so it answers by turn, not by text.
 */
final class Login implements Mechanism
{
    private int $turn = 0;

    /** Where no dump shows it: a trace that passes this mechanism along, printed, shows nothing of the password. */
    private readonly SensitiveParameterValue $password;

    public function __construct(
        private readonly string $username,
        #[SensitiveParameter]
        string $password,
    ) {
        $this->password = new SensitiveParameterValue($password);
    }

    public function name(): string
    {
        return 'LOGIN';
    }

    public function initialResponse(): ?string
    {
        return null;
    }

    public function respond(string $challenge): string
    {
        return match ($this->turn++) {
            0 => $this->username,
            1 => $this->password->getValue(),
            default => throw new LogicException('LOGIN was asked a third time: the server is not speaking LOGIN.'),
        };
    }
}
