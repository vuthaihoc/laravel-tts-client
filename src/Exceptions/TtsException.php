<?php

namespace Vuthaihoc\TtsClient\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

/**
 * Base class of every error raised by the TTS client.
 */
class TtsException extends RuntimeException
{
    public function __construct(string $message, public readonly ?Response $response = null, ?Throwable $previous = null)
    {
        parent::__construct($message, $response?->status() ?? 0, $previous);
    }
}
