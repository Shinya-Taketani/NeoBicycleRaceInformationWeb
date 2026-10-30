<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Keirin;

use App\Domain\Keirin\Scraping\Enums\FetchErrorType;
use App\Domain\Keirin\Scraping\Exceptions\KeirinHttpException;
use App\Domain\Keirin\Scraping\Http\KeirinHttpClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Http\Client\ConnectionException;
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

    public function test_it_posts_form_data_with_headers_and_records_parameters(): void
    {
        config(['keirin.sleep_ms' => 0]);
        Http::fake(['keirin.jp/*' => Http::response('<html>ok</html>', 200, ['Content-Type' => 'text/html; charset=UTF-8'])]);

        $response = (new KeirinHttpClient)->postForm('/pc/racelist', [
            'disp' => 'PJ0301',
            'encp' => 'encrypted-value',
        ], 0, ['Referer' => 'https://keirin.jp/pc/racelist']);

        $this->assertSame('POST', $response->method);
        $this->assertSame(['disp' => 'PJ0301', 'encp' => 'encrypted-value'], $response->requestParameters);
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request['disp'] === 'PJ0301'
            && $request['encp'] === 'encrypted-value'
            && $request->hasHeader('Referer', 'https://keirin.jp/pc/racelist'));
    }

    public function test_connection_exception_recovers_within_the_configured_http_attempts(): void
    {
        config([
            'keirin.sleep_ms' => 0,
            'keirin.retry_times' => 2,
            'keirin.retry_base_sleep_ms' => 0,
        ]);
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            if ($attempts === 1) {
                throw $this->dnsException();
            }

            return Http::response('<html>ok</html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        });

        $response = (new KeirinHttpClient)->get('/sp/racersearch', sleepMsOverride: 0);

        $this->assertSame(2, $attempts);
        $this->assertSame(1, $response->retryCount);
        $this->assertSame(200, $response->httpStatus);
    }

    public function test_exhausted_connection_attempts_report_dns_failure_and_actual_retry_count(): void
    {
        config([
            'keirin.sleep_ms' => 0,
            'keirin.retry_times' => 2,
            'keirin.retry_base_sleep_ms' => 0,
        ]);
        $attempts = 0;
        Http::fake(function () use (&$attempts): never {
            $attempts++;

            throw $this->dnsException();
        });

        try {
            (new KeirinHttpClient)->get('/sp/racersearch', sleepMsOverride: 0);
            $this->fail('KeirinHttpException was not thrown.');
        } catch (KeirinHttpException $exception) {
            $this->assertSame(FetchErrorType::DnsFailure, $exception->errorType);
            $this->assertSame(1, $exception->response?->retryCount);
            $this->assertSame(2, $attempts);
        }
    }

    private function dnsException(): ConnectionException
    {
        return new ConnectionException(
            'cURL error 6: Could not resolve host: keirin.jp',
            0,
            new ConnectException(
                'DNS failure',
                new Request('GET', 'https://keirin.jp/test'),
                null,
                ['errno' => 6],
            ),
        );
    }
}
