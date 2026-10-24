<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\PrestartCapture;

/** No HTTP client, redirects, retries, DB, queues or scheduling. */
final class FixtureTransport implements OfflineTransport
{
    public int $calls = 0;

    public function __construct(private readonly CaptureResponse $response) {}

    public function exchange(CaptureRequest $request): CaptureResponse
    {
        $this->calls++;

        return $this->response;
    }
}
