<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Keirin;

use App\Domain\Keirin\Scraping\Enums\FetchErrorType;
use App\Domain\Keirin\Scraping\Exceptions\KeirinHttpException;
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

    public function test_it_classifies_429(): void
    {
        config(['keirin.sleep_ms' => 0, 'keirin.retry_times' => 0]);
        Http::fake(['keirin.jp/*' => Http::response('too many', 429)]);

        try {
            (new KeirinHttpClient)->get('/sp/racersearch', sleepMsOverride: 0);
            $this->fail('Exception was not thrown.');
        } catch (KeirinHttpException $exception) {
            $this->assertSame(FetchErrorType::TooManyRequests, $exception->errorType);
        }
    }

    public function test_it_classifies_5xx(): void
    {
        config(['keirin.sleep_ms' => 0, 'keirin.retry_times' => 0]);
        Http::fake(['keirin.jp/*' => Http::response('error', 500)]);

        try {
            (new KeirinHttpClient)->get('/sp/racersearch', sleepMsOverride: 0);
            $this->fail('Exception was not thrown.');
        } catch (KeirinHttpException $exception) {
            $this->assertSame(FetchErrorType::ServerError, $exception->errorType);
        }
    }
}
