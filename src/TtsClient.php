<?php

namespace Vuthaihoc\TtsClient;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Vuthaihoc\TtsClient\Exceptions\TtsAuthenticationException;
use Vuthaihoc\TtsClient\Exceptions\TtsConfigurationException;
use Vuthaihoc\TtsClient\Exceptions\TtsConnectionException;
use Vuthaihoc\TtsClient\Exceptions\TtsException;
use Vuthaihoc\TtsClient\Exceptions\TtsRateLimitException;
use Vuthaihoc\TtsClient\Exceptions\TtsValidationException;

/**
 * Client of the Piper TTS API (vuthaihoc/laravel-tts-api).
 */
class TtsClient
{
    /**
     * @param  array<string, mixed>  $config  the "tts" config
     */
    public function __construct(
        private readonly Http $http,
        private readonly TokenSigner $signer,
        private readonly array $config,
        private readonly ?Cache $cache = null,
    ) {}

    /**
     * Installed voices: ['default' => key, 'voices' => [['key', 'language', 'language_code', 'speakers', ...], ...]].
     *
     * @return array<string, mixed>
     */
    public function voices(): array
    {
        $fetch = fn () => $this->send(fn () => $this->request(auth: false)->get('/api/tts/voices'))->json();
        $ttl = (int) ($this->config['voices_cache_ttl'] ?? 0);

        return $this->cache && $ttl > 0
            ? $this->cache->remember('tts-client:voices:'.md5($this->baseUrl()), $ttl, $fetch)
            : $fetch();
    }

    /**
     * Synthesize the whole text into one WAV file.
     *
     * Audio options left null take config('tts.*'), or the server's default
     * when that is not set either: normalize each sentence to full scale,
     * volume multiplier, seconds of silence between sentences, and the
     * model's noise_scale / noise_w (0 = no sampling noise, deterministic).
     */
    public function synthesize(string $text, ?string $voice = null, string|int|null $speaker = null, ?float $speed = null, ?bool $normalize = null, ?float $volume = null, ?float $sentenceSilence = null, ?float $noiseScale = null, ?float $noiseW = null): Audio
    {
        $params = $this->params($text, $voice, $speaker, $speed, compact('normalize', 'volume', 'sentenceSilence', 'noiseScale', 'noiseW'));
        $response = $this->send(fn () => $this->request()->post('/api/tts', $params));

        return new Audio(
            content: $response->body(),
            contentType: $response->header('Content-Type') ?: 'audio/wav',
            voice: $response->header('X-Voice') ?: null,
            duration: is_numeric($response->header('X-Audio-Duration')) ? (float) $response->header('X-Audio-Duration') : null,
            cached: $response->header('X-Cache') === 'HIT',
        );
    }

    /**
     * Open a stream that delivers the audio one sentence at a time.
     *
     * Audio options: see synthesize().
     *
     * @param  'wav'|'pcm'|'ndjson'  $format
     */
    public function stream(string $text, ?string $voice = null, string|int|null $speaker = null, ?float $speed = null, string $format = 'wav', ?bool $normalize = null, ?float $volume = null, ?float $sentenceSilence = null, ?float $noiseScale = null, ?float $noiseW = null): AudioStream
    {
        $params = $this->params($text, $voice, $speaker, $speed, compact('normalize', 'volume', 'sentenceSilence', 'noiseScale', 'noiseW')) + ['format' => $format];
        $response = $this->send(fn () => $this->request()
            ->withOptions(['stream' => true])
            ->post('/api/tts/stream', $params));

        return new AudioStream(
            body: $response->toPsrResponse()->getBody(),
            contentType: $response->header('Content-Type') ?: 'audio/wav',
            sampleRate: is_numeric($response->header('X-Sample-Rate')) ? (int) $response->header('X-Sample-Rate') : null,
            voice: $response->header('X-Voice') ?: null,
        );
    }

    /**
     * A URL the browser can load directly (e.g. <audio src>), carrying a
     * temporary token: it works for the token's lifetime (token_ttl).
     *
     * Audio options: see synthesize().
     *
     * @param  'wav'|'pcm'|'ndjson'  $format  only used with $stream
     */
    public function url(string $text, ?string $voice = null, string|int|null $speaker = null, ?float $speed = null, bool $stream = true, string $format = 'wav', ?bool $normalize = null, ?float $volume = null, ?float $sentenceSilence = null, ?float $noiseScale = null, ?float $noiseW = null): string
    {
        $params = $this->params($text, $voice, $speaker, $speed, compact('normalize', 'volume', 'sentenceSilence', 'noiseScale', 'noiseW')) + ($stream && $format !== 'wav' ? ['format' => $format] : []);

        return $this->baseUrl().($stream ? '/api/tts/stream' : '/api/tts').'?'.http_build_query($params + ['token' => $this->token()]);
    }

    /**
     * IPA of a word, a sentence or several (POST /api/tts/ipa).
     *
     * @param  ?string  $language  en-GB, en-US or vi-VN; null takes config('tts.ipa_language')
     * @param  'espeak'|'dictionary'  $engine  espeak: what the voices say; dictionary:
     *                                         CMUdict / Britfone per word (English only)
     */
    public function ipa(string $text, ?string $language = null, string $engine = 'espeak'): Ipa
    {
        $params = ['text' => $text, 'language' => $language ?? $this->config['ipa_language'] ?? 'en-US', 'engine' => $engine];

        return Ipa::fromResponse($this->send(fn () => $this->request()->post('/api/tts/ipa', $params))->json());
    }

    /**
     * A temporary token to hand to a browser or app, which then calls the
     * server itself with `Authorization: Bearer <token>`.
     */
    public function token(): string
    {
        return $this->signer->token();
    }

    /**
     * Unix time the token returned by token() expires at.
     */
    public function tokenExpiresAt(): int
    {
        $this->signer->token();

        return $this->signer->expiresAt();
    }

    private function request(bool $auth = true): PendingRequest
    {
        $request = $this->http->baseUrl($this->baseUrl())->timeout((int) ($this->config['timeout'] ?? 120));

        return $auth ? $request->withToken($this->token()) : $request;
    }

    /**
     * @param  callable(): Response  $send
     */
    private function send(callable $send): Response
    {
        try {
            $response = $send();
        } catch (ConnectionException $e) {
            throw new TtsConnectionException("Cannot reach the TTS server: {$e->getMessage()}", previous: $e);
        }

        if ($response->successful()) {
            return $response;
        }

        $message = $response->json('message') ?? $response->reason();

        throw match ($response->status()) {
            401 => new TtsAuthenticationException($message, $response),
            422 => new TtsValidationException($message, $response),
            429 => new TtsRateLimitException($message, $response),
            default => new TtsException("TTS server returned {$response->status()}: {$message}", $response),
        };
    }

    /**
     * Request parameters; nulls fall back to config('tts.*') and are left
     * out when that is unset too, so the server's defaults apply.
     *
     * @param  array{normalize: ?bool, volume: ?float, sentenceSilence: ?float, noiseScale: ?float, noiseW: ?float}  $audio
     * @return array<string, string|int|float>
     */
    private function params(string $text, ?string $voice, string|int|null $speaker, ?float $speed, array $audio): array
    {
        $normalize = $audio['normalize'] ?? $this->config['normalize'] ?? null;

        return array_filter([
            'text' => $text,
            'voice' => $voice ?? $this->config['voice'] ?? null,
            'speaker' => $speaker,
            'speed' => $speed,
            'normalize' => $normalize === null ? null : (int) (bool) $normalize,
            'volume' => $audio['volume'] ?? $this->config['volume'] ?? null,
            'sentence_silence' => $audio['sentenceSilence'] ?? $this->config['sentence_silence'] ?? null,
            'noise_scale' => $audio['noiseScale'] ?? $this->config['noise_scale'] ?? null,
            'noise_w' => $audio['noiseW'] ?? $this->config['noise_w'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    private function baseUrl(): string
    {
        $url = $this->config['base_url'] ?? null;

        if (blank($url)) {
            throw new TtsConfigurationException('TTS_BASE_URL must be set.');
        }

        return rtrim($url, '/');
    }
}
