<?php

namespace Vuthaihoc\TtsClient\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Vuthaihoc\TtsClient\TtsServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [TtsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('tts.base_url', 'https://tts.test/');
        $app['config']->set('tts.client_id', 'acme.k3f9');
        $app['config']->set('tts.client_secret', 'Xb3dLw0b0m3NfY8k1t2uQ9zP4rA7sE6vH5jC0xG2iKo');
    }
}
