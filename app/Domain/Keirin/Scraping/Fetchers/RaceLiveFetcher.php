<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Fetchers;

use App\Domain\Keirin\Scraping\DTO\FetchedResponseDto;
use App\Domain\Keirin\Scraping\Http\KeirinHttpClient;

class RaceLiveFetcher
{
    public function __construct(private readonly KeirinHttpClient $client) {}

    public function fetchDetail(string $encryptedParameter, ?int $sleepMs = null): FetchedResponseDto
    {
        return $this->fetch('PJ0315', $encryptedParameter, $sleepMs);
    }

    public function fetchResult(string $encryptedParameter, ?int $sleepMs = null): FetchedResponseDto
    {
        return $this->fetch('PJ0326', $encryptedParameter, $sleepMs);
    }

    private function fetch(string $display, string $encryptedParameter, ?int $sleepMs): FetchedResponseDto
    {
        return $this->client->postForm((string) config('keirin.routes.race_live'), [
            'disp' => $display,
            'encp' => $encryptedParameter,
        ], $sleepMs, [
            'Referer' => rtrim((string) config('keirin.base_url'), '/').(string) config('keirin.routes.race_list'),
        ]);
    }
}
