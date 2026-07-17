<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Keirin;

use App\Domain\Keirin\Scraping\Exceptions\KeirinHttpException;
use App\Domain\Keirin\Scraping\Fetchers\PlayerDetailFetcher;
use App\Domain\Keirin\Scraping\Fetchers\PlayerListFetcher;
use App\Domain\Keirin\Scraping\Fetchers\RaceResultFetcher;
use App\Domain\Keirin\Scraping\Fetchers\RaceScheduleFetcher;
use App\Domain\Keirin\Scraping\Services\ScrapingFetchService;
use App\Models\ScrapingFetchLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ScrapingFetchServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_successful_fetches_for_supported_fetchers(): void
    {
        Storage::fake('local');
        config(['keirin.sleep_ms' => 0, 'keirin.retry_times' => 0]);
        Http::fake(['keirin.jp/*' => Http::response('<html>ok</html>', 200, ['Content-Type' => 'text/html; charset=UTF-8'])]);

        $service = app(ScrapingFetchService::class);
        $service->fetch(fn () => app(PlayerListFetcher::class)->fetch(sleepMs: 0));
        $service->fetch(fn () => app(PlayerDetailFetcher::class)->fetch('015035', 0));
        $service->fetch(fn () => app(RaceScheduleFetcher::class)->fetch(2026, 7, 0));
        $service->fetch(fn () => app(RaceResultFetcher::class)->fetchByUrl('https://keirin.jp/pc/raceresult', 0));

        $this->assertSame(4, ScrapingFetchLog::query()->where('http_status', 200)->whereNull('error_type')->count());
    }

    public function test_it_records_http_error_body_before_throwing(): void
    {
        Storage::fake('local');
        config(['keirin.sleep_ms' => 0, 'keirin.retry_times' => 0]);
        Http::fake(['keirin.jp/*' => Http::response('too many', 429, ['Content-Type' => 'text/plain; charset=UTF-8'])]);

        $this->expectException(KeirinHttpException::class);

        try {
            app(ScrapingFetchService::class)->fetch(fn () => app(PlayerListFetcher::class)->fetch(sleepMs: 0));
        } finally {
            $log = ScrapingFetchLog::query()->firstOrFail();
            $this->assertSame(429, $log->http_status);
            $this->assertSame('HTTP_429', $log->error_type);
            $this->assertSame(8, $log->response_size);
            Storage::disk('local')->assertExists($log->raw_file_path);
        }
    }

    public function test_it_records_500_and_404_errors(): void
    {
        Storage::fake('local');
        config(['keirin.sleep_ms' => 0, 'keirin.retry_times' => 0]);

        Http::fake([
            'keirin.jp/*' => Http::sequence()
                ->push('error', 500, ['Content-Type' => 'text/plain; charset=UTF-8'])
                ->push('error', 404, ['Content-Type' => 'text/plain; charset=UTF-8']),
        ]);

        foreach ([500 => 'HTTP_5XX', 404 => 'HTTP_ERROR'] as $status => $errorType) {
            try {
                app(ScrapingFetchService::class)->fetch(fn () => app(PlayerListFetcher::class)->fetch(sleepMs: 0));
            } catch (KeirinHttpException) {
            }

            $this->assertDatabaseHas('scraping_fetch_logs', [
                'http_status' => $status,
                'error_type' => $errorType,
            ]);
        }
    }

    public function test_it_records_connection_failure_without_body(): void
    {
        Storage::fake('local');
        config(['keirin.sleep_ms' => 0, 'keirin.retry_times' => 0]);
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        $this->expectException(KeirinHttpException::class);

        try {
            app(ScrapingFetchService::class)->fetch(fn () => app(PlayerListFetcher::class)->fetch(sleepMs: 0));
        } finally {
            $this->assertDatabaseHas('scraping_fetch_logs', [
                'http_status' => null,
                'response_size' => 0,
                'sha256' => null,
                'raw_file_path' => null,
                'utf8_conversion_succeeded' => false,
                'error_type' => 'CONNECTION_FAILED',
            ]);
        }
    }

    public function test_it_treats_http_200_empty_body_as_failure(): void
    {
        Storage::fake('local');
        config(['keirin.sleep_ms' => 0, 'keirin.retry_times' => 0]);
        Http::fake(['keirin.jp/*' => Http::response('', 200, ['Content-Type' => 'text/html; charset=UTF-8'])]);

        $this->expectException(KeirinHttpException::class);

        try {
            app(ScrapingFetchService::class)->fetch(fn () => app(PlayerListFetcher::class)->fetch(sleepMs: 0));
        } finally {
            $this->assertDatabaseHas('scraping_fetch_logs', [
                'http_status' => 200,
                'error_type' => 'EMPTY_RESPONSE',
            ]);
        }
    }
}
