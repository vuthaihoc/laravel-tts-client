<?php

namespace Vuthaihoc\TtsClient\Tests\Live;

use Vuthaihoc\TtsClient\Exceptions\TtsAuthenticationException;
use Vuthaihoc\TtsClient\Exceptions\TtsValidationException;
use Vuthaihoc\TtsClient\Facades\Tts;
use Vuthaihoc\TtsClient\Tests\TestCase;

/**
 * Against a running server. Set TTS_LIVE_BASE_URL, TTS_LIVE_CLIENT_ID and
 * TTS_LIVE_CLIENT_SECRET (from `php artisan tts:client` on the server).
 */
class LiveServerTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        if (! getenv('TTS_LIVE_BASE_URL')) {
            $this->markTestSkipped('TTS_LIVE_BASE_URL not set');
        }

        $app['config']->set('tts.base_url', getenv('TTS_LIVE_BASE_URL'));
        $app['config']->set('tts.client_id', getenv('TTS_LIVE_CLIENT_ID'));
        $app['config']->set('tts.client_secret', getenv('TTS_LIVE_CLIENT_SECRET'));
        $app['config']->set('tts.voices_cache_ttl', 0);
    }

    public function test_voices(): void
    {
        $voices = Tts::voices();

        $this->assertNotEmpty($voices['voices']);
        $this->assertContains($voices['default'], array_column($voices['voices'], 'key'));
    }

    public function test_synthesize_returns_wav(): void
    {
        $audio = Tts::synthesize('Xin chào, đây là thử nghiệm từ package client.', speaker: 'yen_nhi');

        $this->assertStringStartsWith('RIFF', $audio->content);
        $this->assertGreaterThan(1, $audio->duration);
    }

    public function test_stream_formats(): void
    {
        $wav = implode('', iterator_to_array(Tts::stream('Câu một. Câu hai.'), false));
        $this->assertStringStartsWith('RIFF', $wav);

        $sentences = iterator_to_array(Tts::stream('Câu một. Câu hai.', format: 'ndjson')->sentences(), false);
        $this->assertTrue(end($sentences)['last']);
    }

    public function test_url_works_without_headers(): void
    {
        $body = file_get_contents(Tts::url('Hello there.', voice: 'en_US-amy-medium'));

        $this->assertStringStartsWith('RIFF', $body);
    }

    private function peak(string $wav): int
    {
        $samples = unpack('s*', substr($wav, 44));

        return max(max($samples), -min($samples));
    }

    public function test_audio_options_change_the_audio(): void
    {
        $text = 'Hôm nay trời rất đẹp.';

        $this->assertGreaterThan(32000, $this->peak(Tts::synthesize($text, normalize: true)->content));
        $this->assertLessThan(24000, $this->peak(Tts::synthesize($text, normalize: false)->content));

        // No sampling noise: the same request gives the same audio.
        $quiet = fn () => Tts::synthesize('Same text.', voice: 'en_US-amy-medium', noiseScale: 0, noiseW: 0)->content;
        $this->assertSame($quiet(), $quiet());
    }

    public function test_ipa_blocks(): void
    {
        $words = Tts::synthesize('I like tomato sauce.', voice: 'en_US-amy-medium', noiseW: 0)->duration;
        $ipa = Tts::synthesize('I like [[təmˈeɪɾoʊ]] sauce.', voice: 'en_US-amy-medium', noiseW: 0)->duration;

        // A server without IPA support spells the symbols out, several times longer.
        $this->assertEqualsWithDelta(1.0, $ipa / $words, 0.4);
    }

    public function test_ipa_with_alternatives_and_dictionary_engine(): void
    {
        $either = Tts::ipa('either', 'en-GB');
        $this->assertSame('/ˈaɪ.ðə/', $either->cambridge());
        $this->assertContains('/ˈiː.ðə/', array_column($either->alternatives(), 'cambridge'));

        $text = Tts::ipa('The vegetable recipe. Is it good?', 'en-US', 'dictionary');
        $this->assertCount(2, $text->sentences);
        $this->assertStringContainsString('ˈvedʒ.tə.bəl', $text->cambridge());
        $this->assertSame('cmudict', $text->words()[1]['source']);
    }

    public function test_server_errors_map_to_exceptions(): void
    {
        try {
            Tts::synthesize('Hello', voice: 'no-such-voice');
            $this->fail('expected validation error');
        } catch (TtsValidationException $e) {
            $this->assertArrayHasKey('voice', $e->errors());
        }

        config(['tts.client_secret' => str_repeat('x', 43)]);
        $this->app->forgetInstance(\Vuthaihoc\TtsClient\TokenSigner::class);
        $this->app->forgetInstance(\Vuthaihoc\TtsClient\TtsClient::class);
        Tts::clearResolvedInstances();

        $this->expectException(TtsAuthenticationException::class);
        Tts::synthesize('Hello');
    }
}
