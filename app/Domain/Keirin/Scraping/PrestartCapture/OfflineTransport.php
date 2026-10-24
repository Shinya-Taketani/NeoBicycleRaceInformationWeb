<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\PrestartCapture;

interface OfflineTransport
{
    public function exchange(CaptureRequest $request): CaptureResponse;
}
