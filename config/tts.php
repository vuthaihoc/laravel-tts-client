<?php

return [

    // Base URL of the TTS server, e.g. https://tts.example.com
    'base_url' => env('TTS_BASE_URL'),

    // Credentials issued by `php artisan tts:client <name>` on the server.
    // Keep the secret on the backend: it signs the temporary tokens.
    'client_id' => env('TTS_CLIENT_ID'),
    'client_secret' => env('TTS_CLIENT_SECRET'),

    // Lifetime of the signed tokens; the server rejects more than 300 seconds.
    'token_ttl' => (int) env('TTS_TOKEN_TTL', 300),

    // A cached token is re-signed once it has less than this many seconds left.
    'token_refresh_before' => 60,

    // Voice used when none is given; null lets the server pick its default.
    'voice' => env('TTS_VOICE'),

    // Audio defaults for every call; null = let the server decide
    // (normalize on, volume 1, 0.2 s between sentences, the voice's noise).
    'normalize' => env('TTS_NORMALIZE') === null ? null : filter_var(env('TTS_NORMALIZE'), FILTER_VALIDATE_BOOL),
    'volume' => is_numeric(env('TTS_VOLUME')) ? (float) env('TTS_VOLUME') : null,
    'sentence_silence' => is_numeric(env('TTS_SENTENCE_SILENCE')) ? (float) env('TTS_SENTENCE_SILENCE') : null,
    'noise_scale' => is_numeric(env('TTS_NOISE_SCALE')) ? (float) env('TTS_NOISE_SCALE') : null,
    'noise_w' => is_numeric(env('TTS_NOISE_W')) ? (float) env('TTS_NOISE_W') : null,

    // Seconds; long texts can take a while to synthesize.
    'timeout' => (int) env('TTS_TIMEOUT', 120),

    // Seconds to cache GET /api/tts/voices (0 disables).
    'voices_cache_ttl' => (int) env('TTS_VOICES_CACHE_TTL', 300),

];
