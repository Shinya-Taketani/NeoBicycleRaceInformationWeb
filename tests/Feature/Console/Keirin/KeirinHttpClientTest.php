<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Keirin;

use App\Domain\Keirin\Scraping\Http\KeirinHttpClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KeirinHttpClientTest extends TestCase
{
    public function test_it_fetches_successful_html(): void
    {
        config(['keirin.sleep_ms' => 0]);
        Http::fake(['keirin.jp/*' => Http::response('<html>ok</html>', 200, ['Content-Type' => 'text/html; charset=UTF-8'])]);

        $response = (new KeirinHttpClient)->get('/sp/racersearch', sleepMsOverride: 0);

        $this->assertSame(200, $response->httpStatus);
        $this->assertSame('<html>ok</html>', $response->body);
    }

    public function test_it_returns_429_response_for_raw_logging(): void
    {
        config(['keirin.sleep_ms' => 0, 'keirin.retry_times' => 0]);
        Http::fake(['keirin.jp/*' => Http::response('too many', 429)]);

        $response = (new KeirinHttpClient)->get('/sp/racersearch', sleepMsOverride: 0);

        $this->assertSame(429, $response->httpStatus);
        $this->assertSame('too many', $response->body);
    }

    public function test_it_returns_5xx_response_for_raw_logging(): void
    {
        config(['keirin.sleep_ms' => 0, 'keirin.retry_times' => 0]);
        Http::fake(['keirin.jp/*' => Http::response('error', 500)]);

        $response = (new KeirinHttpClient)->get('/sp/racersearch', sleepMsOverride: 0);

        $this->assertSame(500, $response->httpStatus);
        $this->assertSame('error', $response->body);
    }
}
