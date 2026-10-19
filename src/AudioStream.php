<?php

namespace Vuthaihoc\TtsClient;

use Generator;
use Illuminate\Contracts\Support\Responsable;
use IteratorAggregate;
use Psr\Http\Message\StreamInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * An open POST /api/tts/stream response. Iterate it to read chunks as the
 * server produces them (one sentence at a time), or return it from a
 * controller to relay the audio to the browser.
 *
 * The request is already sent and its status checked when this object
 * exists, so errors surface before any byte is relayed. It can be iterated
 * once.
 *
 * @implements IteratorAggregate<int, string>
 */
final class AudioStream implements IteratorAggregate, Responsable
{
    public function __construct(
        private readonly StreamInterface $body,
        public readonly string $contentType,
        public readonly ?int $sampleRate = null,
        public readonly ?string $voice = null,
        private readonly int $chunkSize = 8192,
    ) {}

    /**
     * @return Generator<int, string>
     */
    public function getIterator(): Generator
    {
        while (! $this->body->eof()) {
            $chunk = $this->body->read($this->chunkSize);

            if ($chunk !== '') {
                yield $chunk;
            }
        }
    }

    /**
     * For format=ndjson: one decoded line per sentence,
     * ['i' => int, 'sample_rate' => int, 'duration' => float, 'last' => bool, 'pcm' => base64 string].
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function sentences(): Generator
    {
        $buffer = '';

        foreach ($this as $chunk) {
            $buffer .= $chunk;

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);

                if ($line !== '') {
                    yield json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                }
            }
        }

        if (trim($buffer) !== '') {
            yield json_decode($buffer, true, 512, JSON_THROW_ON_ERROR);
        }
    }

    /**
     * Relay to the browser as it arrives. Uses echo + flush, which streams on
     * PHP-FPM and Octane/Swoole (Octane/RoadRunner buffers it until the end).
     */
    public function toResponse($request): StreamedResponse
    {
        return new StreamedResponse(function () {
            foreach ($this as $chunk) {
                echo $chunk;

                if (ob_get_level() > 0) {
                    ob_flush();
                }

                flush();
            }
        }, 200, array_filter([
            'Content-Type' => $this->contentType,
            'X-Sample-Rate' => $this->sampleRate,
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]));
    }
}
