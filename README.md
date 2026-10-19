# Laravel TTS Client

Client Laravel cho [laravel-tts-api](https://github.com/vuthaihoc/laravel-tts-api), dịch vụ chuyển văn bản thành giọng nói chạy Piper.

- Token tạm được **ký ngay trong app** bằng client secret, không phải gọi lên server để xin token. Token được dùng lại cho tới khi gần hết hạn.
- `Tts::synthesize()` trả về một file WAV hoàn chỉnh. `Tts::stream()` trả audio theo từng câu, có thể chuyển tiếp thẳng xuống trình duyệt.
- `Tts::url()` / `Tts::token()` cấp URL hoặc token cho frontend tự gọi server, để audio không phải đi qua app.
- Hỗ trợ Laravel 11–13, PHP 8.2+.

## Cài đặt

```bash
composer require vuthaihoc/laravel-tts-client
php artisan vendor:publish --tag=tts-config   # không bắt buộc
```

`.env`:

```dotenv
TTS_BASE_URL=https://tts.example.com
TTS_CLIENT_ID=acme.k3f9
TTS_CLIENT_SECRET=...        # do admin server cấp bằng `php artisan tts:client acme`
# TTS_VOICE=vi_VN-csa-voice-piper-v3-medium   # voice mặc định, bỏ trống thì server tự chọn
# TTS_TOKEN_TTL=300          # không được vượt giới hạn của server (mặc định 300)
# TTS_TIMEOUT=120
```

`TTS_CLIENT_SECRET` chỉ nằm ở backend: ai có nó đều ký được token. Trình duyệt chỉ nhận token đã ký sẵn hoặc URL có gắn token.

## Sử dụng

```php
use Vuthaihoc\TtsClient\Facades\Tts;

// Danh sách voice và speaker (cache 5 phút)
$voices = Tts::voices();

// Một file WAV
$audio = Tts::synthesize('Xin chào!', voice: 'vi_VN-csa-voice-piper-v3-medium', speaker: 'yen_nhi');
$audio->content;     // bytes WAV
$audio->duration;    // giây
$audio->save('tts/hello.wav', 's3');

// Trả thẳng từ controller
Route::get('/hello.wav', fn () => Tts::synthesize('Xin chào!'));
```

### Stream

```php
// Chuyển tiếp xuống trình duyệt theo từng câu
Route::get('/speak', fn (Request $r) => Tts::stream($r->input('text'), voice: 'en_US-amy-medium'));

// Tự đọc từng chunk
foreach (Tts::stream($text, format: 'pcm') as $chunk) {
    // s16le mono, Tts::stream(...)->sampleRate Hz
}

// NDJSON: mỗi câu một mảng ['i', 'sample_rate', 'duration', 'last', 'pcm' => base64]
foreach (Tts::stream($text, format: 'ndjson')->sentences() as $sentence) {
    // ...
}
```

Lỗi (voice sai, token bị từ chối, ...) được ném ra ngay khi gọi `stream()`, trước khi gửi byte audio nào xuống trình duyệt. Việc chuyển tiếp dùng `echo` + `flush`, nên stream được trên PHP-FPM và Octane/Swoole. Riêng Octane/RoadRunner sẽ gom hết rồi mới gửi.

### Để frontend gọi server trực tiếp

```blade
{{-- URL dùng được trong token_ttl giây (mặc định 5 phút) --}}
<audio controls src="{{ Tts::url('Xin chào các bạn.', speaker: 'quang_huy') }}"></audio>
```

```php
// Hoặc trả token cho JavaScript, để JS tự gọi fetch(`${base}/api/tts/stream`, { headers: { Authorization: `Bearer ${token}` } })
return ['token' => Tts::token(), 'expires_at' => Tts::tokenExpiresAt()];
```

## Xử lý lỗi

Mọi lỗi đều kế thừa `Vuthaihoc\TtsClient\Exceptions\TtsException` (`getCode()` là HTTP status, `->response` là response gốc):

| Exception | Khi nào |
|---|---|
| `TtsAuthenticationException` | 401: sai client secret, server đã đổi `TTS_SECRET`, đồng hồ lệch quá 60 s, `TTS_TOKEN_TTL` lớn hơn giới hạn của server |
| `TtsValidationException` | 422: `->errors()` trả lỗi theo từng trường (text quá dài, voice hoặc speaker không tồn tại, ...) |
| `TtsRateLimitException` | 429: `->retryAfter()` là số giây phải chờ |
| `TtsConnectionException` | Không kết nối được server hoặc quá `TTS_TIMEOUT` |
| `TtsConfigurationException` | Thiếu `TTS_BASE_URL`, `TTS_CLIENT_ID` hoặc `TTS_CLIENT_SECRET` |

## Test

```bash
composer test                                  # unit test, dùng Http::fake
TTS_LIVE_BASE_URL=http://127.0.0.1:8088 TTS_LIVE_CLIENT_ID=... TTS_LIVE_CLIENT_SECRET=... \
  vendor/bin/phpunit --testsuite Live          # chạy với server thật
```

Trong app của bạn, chỉ cần `Http::fake(['*/api/tts*' => Http::response('RIFF...')])` là gọi được `Tts` mà không cần server.

## License

MIT
