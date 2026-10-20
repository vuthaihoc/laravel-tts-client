<?php

namespace Vuthaihoc\TtsClient\Facades;

use Illuminate\Support\Facades\Facade;
use Vuthaihoc\TtsClient\TtsClient;

/**
 * @method static array voices()
 * @method static \Vuthaihoc\TtsClient\Audio synthesize(string $text, ?string $voice = null, string|int|null $speaker = null, ?float $speed = null, ?bool $normalize = null, ?float $volume = null, ?float $sentenceSilence = null, ?float $noiseScale = null, ?float $noiseW = null)
 * @method static \Vuthaihoc\TtsClient\AudioStream stream(string $text, ?string $voice = null, string|int|null $speaker = null, ?float $speed = null, string $format = 'wav', ?bool $normalize = null, ?float $volume = null, ?float $sentenceSilence = null, ?float $noiseScale = null, ?float $noiseW = null)
 * @method static string url(string $text, ?string $voice = null, string|int|null $speaker = null, ?float $speed = null, bool $stream = true, string $format = 'wav', ?bool $normalize = null, ?float $volume = null, ?float $sentenceSilence = null, ?float $noiseScale = null, ?float $noiseW = null)
 * @method static string token()
 * @method static int tokenExpiresAt()
 *
 * @see TtsClient
 */
class Tts extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return TtsClient::class;
    }
}
