<?php

namespace Vuthaihoc\TtsClient\Exceptions;

/**
 * 429: the client's per-minute limit is used up.
 */
class TtsRateLimitException extends TtsException
{
    /**
     * Seconds to wait before retrying, from the Retry-After header.
     */
    public function retryAfter(): ?int
    {
        $value = $this->response?->header('Retry-After');

        return is_numeric($value) ? (int) $value : null;
    }
}
