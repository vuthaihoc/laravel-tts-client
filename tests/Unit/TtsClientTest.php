<?php

namespace Vuthaihoc\TtsClient\Tests\Unit;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Vuthaihoc\TtsClient\Exceptions\TtsAuthenticationException;
use Vuthaihoc\TtsClient\Exceptions\TtsConfigurationException;
use Vuthaihoc\TtsClient\Exceptions\TtsConnectionException;
use Vuthaihoc\TtsClient\Exceptions\TtsException;
use Vuthaihoc\TtsClient\Exceptions\TtsRateLimitException;
use Vuthaihoc\TtsClient\Exceptions\TtsValidationException;
use Vuthaihoc\TtsClient\Facades\Tts;
use Vuthaihoc\TtsClient\Tests\TestCase;

class TtsClientTest extends TestCase
{
    private function assertSignedToken(string $token): void
    {
        $payload = JWT::decode($token, new Key(config('tts.client_secret'), 'HS256'));
        $this->assertLessThanOrEqual(300, $payload->exp - $payload->iat);
    }

    public function test_synthesize_sends_token_and_params(): void
    {
        Http::fake(['tts.test/api/tts' => Http::response('RIFFdata', 200, [
            'Content-Type' => 'audio/wav', 'X-Voice' => 'en_US-amy-medium', 'X-Audio-Duration' => '1.25', 'X-Cache' => 'HIT',
        ])]);

        $audio = Tts::synthesize('Hello', voice: 'en_US-amy-medium', speaker: 'quang_huy', speed: 1.2);

        $this->assertSame('RIFFdata', $audio->content);
        $this->assertSame('en_US-amy-medium', $audio->voice);
        $this->assertSame(1.25, $audio->duration);
        $this->assertTrue($audio->cached);

        Http::assertSent(function (Request $request) {
            $this->assertSignedToken(substr($request->header('Authorization')[0], 7));

            return $request->method() === 'POST'
                && $request['text'] === 'Hello'
                && $request['voice'] === 'en_US-amy-medium'
                && $request['speaker'] === 'quang_huy'
                && $request['speed'] === 1.2;
        });
    }

    public function test_uses_configured_default_voice_and_omits_unset_params(): void
    {
        config(['tts.voice' => 'en_GB-alan-medium']);
        Http::fake(['*' => Http::response('RIFF')]);

        Tts::synthesize('Hello');

        Http::assertSent(fn (Request $request) => $request->data() === ['text' => 'Hello', 'voice' => 'en_GB-alan-medium']);
    }

    public function test_sends_audio_options_including_zero_values(): void
    {
        Http::fake(['*' => Http::response('RIFF')]);

        Tts::synthesize('Hello', normalize: false, volume: 0.8, sentenceSilence: 0.5, noiseScale: 0.0, noiseW: 0.3);

        Http::assertSent(fn (Request $request) => $request->data() === [
            'text' => 'Hello',
            'normalize' => 0,
            'volume' => 0.8,
            'sentence_silence' => 0.5,
            'noise_scale' => 0.0,
            'noise_w' => 0.3,
        ]);
    }

    public function test_audio_options_fall_back_to_config(): void
    {
        config(['tts.normalize' => true, 'tts.sentence_silence' => 0.0, 'tts.noise_w' => 0.4]);
        Http::fake(['*' => Http::response('RIFF')]);

        Tts::synthesize('Hello', noiseW: 0.1);

        Http::assertSent(fn (Request $request) => $request->data() === [
            'text' => 'Hello',
            'normalize' => 1,
            'sentence_silence' => 0.0,
            'noise_w' => 0.1,
        ]);
    }

    public function test_stream_and_url_carry_audio_options(): void
    {
        Http::fake(['*' => Http::response('RIFF')]);

        Tts::stream('Hello', format: 'pcm', volume: 0.5, noiseScale: 0.2);
        Http::assertSent(fn (Request $request) => $request['format'] === 'pcm' && $request['volume'] === 0.5 && $request['noise_scale'] === 0.2);

        parse_str(parse_url(Tts::url('Hello', normalize: false, sentenceSilence: 1.0), PHP_URL_QUERY), $query);
        $this->assertSame('0', $query['normalize']);
        $this->assertSame('1', $query['sentence_silence']);
    }

    public function test_maps_error_responses_to_exceptions(): void
    {
        $cases = [
            [401, ['message' => 'Unauthenticated: Expired token.'], [], TtsAuthenticationException::class],
            [422, ['message' => 'Invalid.', 'errors' => ['voice' => ['The selected voice is not installed.']]], [], TtsValidationException::class],
            [429, ['message' => 'Too Many Attempts.'], ['Retry-After' => '17'], TtsRateLimitException::class],
            [500, ['message' => 'Server Error'], [], TtsException::class],
        ];

        $sequence = Http::sequence();
        foreach ($cases as [$status, $body, $headers]) {
            $sequence->push($body, $status, $headers);
        }
        Http::fake(['*' => $sequence]);

        foreach ($cases as [$status, , , $class]) {
            try {
                Tts::synthesize('Hello');
                $this->fail("No exception for {$status}");
            } catch (TtsException $e) {
                $this->assertInstanceOf($class, $e);
                $this->assertSame($status, $e->getCode());

                match ($status) {
                    422 => $this->assertSame(['voice' => ['The selected voice is not installed.']], $e->errors()),
                    429 => $this->assertSame(17, $e->retryAfter()),
                    401 => $this->assertSame('Unauthenticated: Expired token.', $e->getMessage()),
                    default => null,
                };
            }
        }
    }

    public function test_wraps_connection_errors(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 7'));

        $this->expectException(TtsConnectionException::class);

        Tts::synthesize('Hello');
    }

    public function test_stream_yields_body_and_parses_ndjson_sentences(): void
    {
        $lines = json_encode(['i' => 0, 'last' => false, 'pcm' => 'AAA='])."\n".json_encode(['i' => 1, 'last' => true, 'pcm' => ''])."\n";

        Http::fake([
            'tts.test/api/tts/stream' => Http::sequence()
                ->push(str_repeat('x', 20000), 200, ['Content-Type' => 'audio/wav', 'X-Sample-Rate' => '22050'])
                ->push($lines, 200, ['Content-Type' => 'application/x-ndjson']),
        ]);

        $stream = Tts::stream('Hello');
        $this->assertSame(22050, $stream->sampleRate);
        $this->assertSame(str_repeat('x', 20000), implode('', iterator_to_array($stream, false)));

        $sentences = iterator_to_array(Tts::stream('Hello', format: 'ndjson')->sentences(), false);
        $this->assertCount(2, $sentences);
        $this->assertTrue($sentences[1]['last']);

        Http::assertSent(fn (Request $request) => $request['format'] === 'ndjson');
    }

    public function test_stream_and_audio_can_be_returned_from_a_controller(): void
    {
        Http::fake(['*' => Http::response('RIFFdata', 200, ['Content-Type' => 'audio/wav'])]);

        $response = Tts::stream('Hello')->toResponse(request());
        // toResponse() flushes each chunk to the enclosing buffer, as it would to the client.
        ob_start();
        ob_start();
        $response->sendContent();
        ob_end_clean();
        $this->assertSame('RIFFdata', ob_get_clean());
        $this->assertSame('audio/wav', $response->headers->get('Content-Type'));

        $this->assertSame('RIFFdata', Tts::synthesize('Hello')->toResponse(request())->getContent());
    }

    public function test_audio_can_be_saved_to_a_disk(): void
    {
        Storage::fake('local');
        Http::fake(['*' => Http::response('RIFFdata')]);

        Tts::synthesize('Hello')->save('tts/hello.wav', 'local');

        Storage::disk('local')->assertExists('tts/hello.wav');
    }

    public function test_url_carries_a_signed_token(): void
    {
        $url = Tts::url('Xin chào', voice: 'vi_VN-csa-voice-piper-v3-medium', speaker: 'yen_nhi');

        $this->assertStringStartsWith('https://tts.test/api/tts/stream?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('Xin chào', $query['text']);
        $this->assertSame('yen_nhi', $query['speaker']);
        $this->assertArrayNotHasKey('format', $query);
        $this->assertSignedToken($query['token']);

        $this->assertStringStartsWith('https://tts.test/api/tts?', Tts::url('Hi', stream: false));
    }

    public function test_voices_are_public_and_cached(): void
    {
        Http::fake(['tts.test/api/tts/voices' => Http::response(['default' => 'vi', 'voices' => []])]);

        $this->assertSame('vi', Tts::voices()['default']);
        Tts::voices();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => ! $request->hasHeader('Authorization'));
    }

    public function test_requires_base_url(): void
    {
        config(['tts.base_url' => null]);
        $this->app->forgetInstance(\Vuthaihoc\TtsClient\TtsClient::class);
        Tts::clearResolvedInstances();

        $this->expectException(TtsConfigurationException::class);

        Tts::url('Hello');
    }
}
