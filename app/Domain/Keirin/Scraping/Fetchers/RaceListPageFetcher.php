<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Fetchers;

use App\Domain\Keirin\Scraping\DTO\FetchedResponseDto;
use App\Domain\Keirin\Scraping\Http\KeirinHttpClient;

class RaceListPageFetcher
{
    public function __construct(private readonly KeirinHttpClient $client) {}

    public function fetch(string $encryptedParameter, ?int $sleepMs = null): FetchedResponseDto
    {
        return $this->client->postForm((string) config('keirin.routes.race_list'), [
            'disp' => 'PJ0301',
            'encp' => $encryptedParameter,
        ], $sleepMs, [
            'Referer' => rtrim((string) config('keirin.base_url'), '/').(string) config('keirin.routes.race_schedule'),
        ]);
    }
}
