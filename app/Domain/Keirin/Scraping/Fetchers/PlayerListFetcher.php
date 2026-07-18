<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Fetchers;

use App\Domain\Keirin\Scraping\DTO\FetchedResponseDto;
use App\Domain\Keirin\Scraping\Http\KeirinHttpClient;

class PlayerListFetcher
{
    public function __construct(private readonly KeirinHttpClient $client) {}

    public function fetch(int $page = 1, ?string $gradeCode = null, ?string $prefectureCode = null, ?int $sleepMs = null): FetchedResponseDto
    {
        return $this->client->get((string) config('keirin.routes.player_search_result'), array_filter([
            'dppg' => (string) $page,
            'seibetuCD' => '1',
            'kyuhanCD' => $gradeCode,
            'hukenCD' => $prefectureCode,
            'stgt' => '1',
        ], fn (?string $value): bool => $value !== null && $value !== ''), $sleepMs);
    }
}
