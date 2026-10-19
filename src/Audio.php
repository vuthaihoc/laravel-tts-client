<?php

namespace Vuthaihoc\TtsClient;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/**
 * A complete synthesized file returned by POST /api/tts.
 */
final class Audio implements Responsable
{
    public function __construct(
        public readonly string $content,
        public readonly string $contentType = 'audio/wav',
        public readonly ?string $voice = null,
        public readonly ?float $duration = null,
        public readonly bool $cached = false,
    ) {}

    /**
     * Store the audio on a filesystem disk and return the path.
     */
    public function save(string $path, ?string $disk = null): string
    {
        Storage::disk($disk)->put($path, $this->content);

        return $path;
    }

    /**
     * Return the audio from a controller: `return Tts::synthesize('...');`
     */
    public function toResponse($request): Response
    {
        return new Response($this->content, 200, [
            'Content-Type' => $this->contentType,
            'Content-Length' => strlen($this->content),
        ]);
    }
}
