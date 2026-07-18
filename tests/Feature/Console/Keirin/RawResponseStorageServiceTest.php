<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Keirin;

use App\Domain\Keirin\Scraping\DTO\FetchedResponseDto;
use App\Domain\Keirin\Scraping\Enums\FetchErrorType;
use App\Domain\Keirin\Scraping\Services\RawResponseStorageService;
use App\Models\ScrapingFetchLog;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class RawResponseStorageServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_stores_raw_html_and_fetch_log(): void
    {
        Storage::fake('local');

        $response = new FetchedResponseDto(
            source: 'keirin_jp',
            method: 'GET',
            url: 'https://keirin.jp/sp/racersearch',
            requestKey: 'player-search',
            httpStatus: 200,
            body: '<html><meta charset="utf-8">競輪</html>',
            contentType: 'text/html; charset=UTF-8',
            fetchedAt: new DateTimeImmutable('2026-07-18 06:00:00'),
            retryCount: 1,
        );

        $stored = app(RawResponseStorageService::class)->store($response);

        Storage::disk('local')->assertExists($stored->rawFilePath);
        $this->assertSame(hash('sha256', $response->body), $stored->sha256);
        $this->assertSame(1, ScrapingFetchLog::query()->count());
        $this->assertDatabaseHas('scraping_fetch_logs', [
            'request_url' => 'https://keirin.jp/sp/racersearch',
            'sha256' => $stored->sha256,
            'utf8_conversion_succeeded' => true,
        ]);
    }

    public function test_it_fails_before_logging_when_raw_file_cannot_be_written(): void
    {
        Storage::shouldReceive('disk')
            ->once()
            ->with('local')
            ->andReturn(new class
            {
                public function put(string $path, string $contents): bool
                {
                    return false;
                }
            });

        $this->expectException(RuntimeException::class);

        try {
            app(RawResponseStorageService::class)->store($this->response());
        } finally {
            $this->assertSame(0, ScrapingFetchLog::query()->count());
        }
    }

    public function test_it_logs_http_error_metadata_with_raw_response(): void
    {
        Storage::fake('local');

        $response = $this->response(
            status: 429,
            body: 'too many requests',
            contentType: 'text/plain; charset=UTF-8',
        );

        $stored = app(RawResponseStorageService::class)->store(
            $response,
            errorType: FetchErrorType::TooManyRequests,
            errorMessage: 'KEIRIN.JP returned HTTP 429.',
        );

        Storage::disk('local')->assertExists($stored->rawFilePath);
        $this->assertDatabaseHas('scraping_fetch_logs', [
            'request_url' => 'https://keirin.jp/sp/racersearch',
            'http_status' => 429,
            'error_type' => FetchErrorType::TooManyRequests->value,
            'error_message' => 'KEIRIN.JP returned HTTP 429.',
            'utf8_conversion_succeeded' => true,
        ]);
    }

    public function test_it_stores_json_with_json_extension_and_request_parameters(): void
    {
        Storage::fake('local');
        $response = new FetchedResponseDto(
            source: 'keirin_jp',
            method: 'GET',
            url: 'https://keirin.jp/pc/json?type=JSJ001',
            requestKey: 'race-json',
            httpStatus: 200,
            body: '{"resultCd":0}',
            contentType: 'application/json; charset=UTF-8',
            fetchedAt: new DateTimeImmutable('2026-07-18 06:00:00'),
            requestParameters: ['type' => 'JSJ001', 'encp' => 'encrypted-value'],
        );

        $stored = app(RawResponseStorageService::class)->store($response);

        $this->assertStringEndsWith('.json', $stored->rawFilePath);
        $this->assertStringStartsWith('scraping/raw/', $stored->rawFilePath);
        $this->assertSame(['type' => 'JSJ001', 'encp' => 'encrypted-value'], ScrapingFetchLog::query()->firstOrFail()->request_parameters);
    }

    private function response(int $status = 200, string $body = '<html><meta charset="utf-8">競輪</html>', ?string $contentType = 'text/html; charset=UTF-8'): FetchedResponseDto
    {
        return new FetchedResponseDto(
            source: 'keirin_jp',
            method: 'GET',
            url: 'https://keirin.jp/sp/racersearch',
            requestKey: 'player-search',
            httpStatus: $status,
            body: $body,
            contentType: $contentType,
            fetchedAt: new DateTimeImmutable('2026-07-18 06:00:00'),
            retryCount: 1,
        );
    }
}
