<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Fetchers;

use App\Domain\Keirin\Scraping\DTO\FetchedResponseDto;
use App\Domain\Keirin\Scraping\Http\KeirinHttpClient;

class RaceScheduleFetcher
{
    public function __construct(private readonly KeirinHttpClient $client) {}

    public function fetch(int $year, int $month, ?int $sleepMs = null): FetchedResponseDto
    {
        return $this->client->get((string) config('keirin.routes.race_schedule'), [
            'scyy' => (string) $year,
            'scym' => str_pad((string) $month, 2, '0', STR_PAD_LEFT),
        ], $sleepMs);
    }
}
