<?php

namespace Vuthaihoc\TtsClient;

use Firebase\JWT\JWT;
use Vuthaihoc\TtsClient\Exceptions\TtsConfigurationException;

/**
 * Signs the temporary tokens the TTS server accepts: JWT HS256 with header
 * kid = client_id and payload {iat, exp}, keyed with the client secret.
 * Signing is local; nothing is requested from the server.
 *
 * The last token is reused until it has less than `refreshBefore` seconds
 * left, so one signature serves many requests.
 */
class TokenSigner
{
    private ?string $token = null;

    private int $expiresAt = 0;

    public function __construct(
        private readonly ?string $clientId,
        #[\SensitiveParameter] private readonly ?string $clientSecret,
        private readonly int $ttl = 300,
        private readonly int $refreshBefore = 60,
    ) {}

    /**
     * A valid token, reusing the previous one while it is fresh enough.
     */
    public function token(): string
    {
        if ($this->token !== null && $this->expiresAt - time() > $this->refreshBefore) {
            return $this->token;
        }

        return $this->sign();
    }

    /**
     * Unix time the current token expires at (0 before the first one).
     */
    public function expiresAt(): int
    {
        return $this->expiresAt;
    }

    /**
     * Sign a new token, ignoring the cached one.
     */
    public function sign(?int $ttl = null): string
    {
        if (blank($this->clientId) || blank($this->clientSecret)) {
            throw new TtsConfigurationException('TTS_CLIENT_ID and TTS_CLIENT_SECRET must be set.');
        }

        $now = time();
        $this->expiresAt = $now + max(1, $ttl ?? $this->ttl);

        return $this->token = JWT::encode(
            ['iat' => $now, 'exp' => $this->expiresAt],
            $this->clientSecret,
            'HS256',
            $this->clientId,
        );
    }
}
