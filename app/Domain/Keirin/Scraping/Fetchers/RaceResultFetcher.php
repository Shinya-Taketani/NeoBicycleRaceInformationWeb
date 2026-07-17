<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Fetchers;

use App\Domain\Keirin\Scraping\DTO\FetchedResponseDto;
use App\Domain\Keirin\Scraping\Http\KeirinHttpClient;

class RaceResultFetcher
{
    public function __construct(private readonly KeirinHttpClient $client) {}

    public function fetchByUrl(string $url, ?int $sleepMs = null): FetchedResponseDto
    {
        return $this->client->get($url, [], $sleepMs);
    }
}
