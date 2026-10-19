<?php

namespace Vuthaihoc\TtsClient\Tests\Unit;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PHPUnit\Framework\TestCase;
use stdClass;
use Vuthaihoc\TtsClient\Exceptions\TtsConfigurationException;
use Vuthaihoc\TtsClient\TokenSigner;

class TokenSignerTest extends TestCase
{
    private const SECRET = 'Xb3dLw0b0m3NfY8k1t2uQ9zP4rA7sE6vH5jC0xG2iKo';

    public function test_signs_hs256_with_kid_and_lifetime(): void
    {
        $token = (new TokenSigner('acme.k3f9', self::SECRET, ttl: 300))->token();

        $headers = new stdClass;
        $payload = JWT::decode($token, new Key(self::SECRET, 'HS256'), $headers);

        $this->assertSame('acme.k3f9', $headers->kid);
        $this->assertSame('HS256', $headers->alg);
        $this->assertSame(300, $payload->exp - $payload->iat);
        $this->assertEqualsWithDelta(time(), $payload->iat, 2);
    }

    public function test_reuses_token_until_close_to_expiry(): void
    {
        $fresh = new TokenSigner('acme.k3f9', self::SECRET, ttl: 300, refreshBefore: 60);
        $this->assertSame($fresh->token(), $fresh->token());

        // ttl <= refreshBefore: every call signs a new token.
        $short = new TokenSigner('acme.k3f9', self::SECRET, ttl: 60, refreshBefore: 60);
        $first = $short->token();
        sleep(1);
        $this->assertNotSame($first, $short->token());
    }

    public function test_requires_credentials(): void
    {
        $this->expectException(TtsConfigurationException::class);

        (new TokenSigner(null, self::SECRET))->token();
    }
}
