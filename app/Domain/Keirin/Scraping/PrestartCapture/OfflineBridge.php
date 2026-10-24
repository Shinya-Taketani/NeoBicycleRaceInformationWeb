<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\PrestartCapture;

use Throwable;

final class OfflineBridge
{
    public function __construct(private readonly OfflineTransport $transport, private readonly CaptureStore $store) {}

    public function capture(CaptureRequest $request): StoredCapture
    {
        $directory = $this->store->reserve($request);
        try {
            $response = $this->transport->exchange($request);
        } catch (Throwable $error) {
            $this->store->fail($directory, 'TRANSPORT_EXCEPTION', $error);
            throw $error;
        }

        return $this->store->finish($directory, $request, $response);
    }
}
