<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Fetchers;

use App\Domain\Keirin\Scraping\DTO\FetchedResponseDto;
use App\Domain\Keirin\Scraping\Http\KeirinHttpClient;

class PlayerDetailFetcher
{
    public function __construct(private readonly KeirinHttpClient $client) {}

    public function fetch(string $externalPlayerId, ?int $sleepMs = null): FetchedResponseDto
    {
        return $this->client->get((string) config('keirin.routes.player_detail_pc'), [
            'snum' => $externalPlayerId,
        ], $sleepMs);
    }
}
