<?php

namespace Vuthaihoc\TtsClient;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\ServiceProvider;

class TtsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/tts.php', 'tts');

        // Singletons: the signed token is reused across calls (and across
        // requests under Octane) until it is close to expiry.
        $this->app->singleton(TokenSigner::class, fn ($app) => new TokenSigner(
            $app['config']['tts.client_id'],
            $app['config']['tts.client_secret'],
            (int) $app['config']['tts.token_ttl'],
            (int) $app['config']['tts.token_refresh_before'],
        ));

        $this->app->singleton(TtsClient::class, fn ($app) => new TtsClient(
            $app->make(Http::class),
            $app->make(TokenSigner::class),
            $app['config']['tts'],
            $app->make(Cache::class),
        ));
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/tts.php' => config_path('tts.php'),
        ], 'tts-config');
    }
}
