<?php

namespace Vuthaihoc\TtsClient\Exceptions;

/**
 * 422: invalid parameters (text too long, unknown voice or speaker, ...).
 */
class TtsValidationException extends TtsException
{
    /**
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->response?->json('errors') ?? [];
    }
}
