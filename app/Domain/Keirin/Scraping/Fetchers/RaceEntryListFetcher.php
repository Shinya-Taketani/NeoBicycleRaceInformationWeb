<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Fetchers;

use App\Domain\Keirin\Scraping\DTO\FetchedResponseDto;
use App\Domain\Keirin\Scraping\Http\KeirinHttpClient;

class RaceEntryListFetcher
{
    public function __construct(private readonly KeirinHttpClient $client) {}

    public function fetch(string $encryptedParameter, ?int $sleepMs = null): FetchedResponseDto
    {
        return $this->client->get((string) config('keirin.routes.race_json'), [
            'encp' => $encryptedParameter,
            'type' => 'JSJ017',
        ], $sleepMs, [
            'Accept' => 'application/json, text/javascript, */*; q=0.01',
            'Referer' => rtrim((string) config('keirin.base_url'), '/').(string) config('keirin.routes.race_list'),
        ]);
    }
}
