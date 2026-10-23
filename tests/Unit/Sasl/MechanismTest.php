<?php

declare(strict_types=1);

namespace CoolMS\Imap\Tests\Unit\Sasl;

use CoolMS\Imap\Sasl\Login;
use CoolMS\Imap\Sasl\OAuthBearer;
use CoolMS\Imap\Sasl\Plain;
use CoolMS\Imap\Sasl\XOAuth2;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function base64_encode;

/** The vectors are the specifications' own examples, computed independently (Python's base64). */
final class MechanismTest extends TestCase
{
    #[Test]
    public function plainIsRfc4616sExample(): void
    {
        self::assertSame(
            'AHRpbQB0YW5zdGFhZnRhbnN0YWFm',
            base64_encode(new Plain('tim', 'tanstaaftanstaaf')->initialResponse()),
        );
    }

    #[Test]
    public function xoauth2IsGooglesExample(): void
    {
        $mechanism = new XOAuth2('someuser@example.com', 'ya29.vF9dft4qmTc2Nvb3RlckBhdHRhdmlzdGEuY29tCg');

        self::assertSame(
            'dXNlcj1zb21ldXNlckBleGFtcGxlLmNvbQFhdXRoPUJlYXJlciB5YTI5LnZGOWRmdDRxbVRjMk52YjNSbGNrQmhkSFJoZG1semRHRXVZMjl0Q2cBAQ==',
            base64_encode($mechanism->initialResponse()),
        );
        self::assertSame('', $mechanism->respond('{"status":"401"}'), 'a refusal is answered with an empty response');
        self::assertSame('{"status":"401"}', $mechanism->error, 'and its reason kept');
    }

    #[Test]
    public function oauthBearerIsRfc7628sExample(): void
    {
        $mechanism = new OAuthBearer('user@example.com', 'vF9dft4qmTc2Nvb3RlckBhdHRhdmlzdGEuY29tCg==', 'server.example.com', 143);

        self::assertSame(
            'bixhPXVzZXJAZXhhbXBsZS5jb20sAWhvc3Q9c2VydmVyLmV4YW1wbGUuY29tAXBvcnQ9MTQzAWF1dGg9QmVhcmVyIHZGOWRmdDRxbVRjMk52YjNSbGNrQmhkSFJoZG1semRHRXVZMjl0Q2c9PQEB',
            base64_encode($mechanism->initialResponse()),
        );
        self::assertSame("\x01", $mechanism->respond('{"status":"invalid_token"}'));
    }

    #[Test]
    public function oauthBearerEscapesACommaAndAnEqualsInTheName(): void
    {
        $mechanism = new OAuthBearer('a,b=c', 't', 'h', 993);

        self::assertStringStartsWith("n,a=a=2Cb=3Dc,\x01", $mechanism->initialResponse());
    }

    #[Test]
    public function loginAnswersByTurnNotByWording(): void
    {
        $mechanism = new Login('tim', 'secret');

        self::assertNull($mechanism->initialResponse());
        self::assertSame('tim', $mechanism->respond('User Name'));
        self::assertSame('secret', $mechanism->respond('Password:'));
        $this->expectException(LogicException::class);
        $mechanism->respond('again?');
    }

    #[Test]
    public function aSeparatorInsideACredentialIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new XOAuth2('user', "tok\x01en");
    }

    #[Test]
    public function aNulInsideAPlainCredentialIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Plain("ti\0m", 'x');
    }
}
