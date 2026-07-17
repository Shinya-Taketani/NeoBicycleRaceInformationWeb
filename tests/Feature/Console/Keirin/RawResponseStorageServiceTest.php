<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Keirin;

use App\Domain\Keirin\Scraping\DTO\FetchedResponseDto;
use App\Domain\Keirin\Scraping\Services\RawResponseStorageService;
use App\Models\ScrapingFetchLog;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
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
}
