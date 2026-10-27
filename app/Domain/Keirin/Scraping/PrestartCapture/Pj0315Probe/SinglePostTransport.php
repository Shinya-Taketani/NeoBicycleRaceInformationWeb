<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\PrestartCapture\Pj0315Probe;

use App\Domain\Keirin\Scraping\PrestartCapture\Contract;
use GuzzleHttp\TransferStats;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use RuntimeException;
use Throwable;

final class SinglePostTransport
{
    private bool $used = false;

    public function __construct(private readonly Factory $http) {}

    /** @param resource $sink */
    public function exchange(Target $target, $sink): Transfer
    {
        if ($this->used || ! is_resource($sink)) {
            throw new RuntimeException('Only one dispatch per probe transport is permitted.');
        }
        $this->used = true;
        $stats = null;
        $started = Contract::now();
        $base = ['request_started_at' => $started, 'response_received_at' => null, 'http_status' => null,
            'content_type' => null, 'effective_uri' => null, 'http_dispatch_count' => 1, 'retry_count' => 0,
            'follow_redirects' => false, 'connect_timeout_seconds' => 5, 'total_timeout_seconds' => 20];
        try {
            $response = $this->http->asForm()->withoutRedirecting()->connectTimeout(5)->timeout(20)
                ->withHeaders(['User-Agent' => 'NeoBicycleRaceInformationWeb/PJ0315-technical-probe-01',
                    'Accept' => 'text/html,application/xhtml+xml', 'Accept-Encoding' => 'identity',
                    'Referer' => 'https://keirin.jp/pc/racelist'])
                ->withOptions(['http_errors' => false, 'decode_content' => false, 'sink' => $sink,
                    'on_stats' => static function (TransferStats $transfer) use (&$stats): void {
                        $stats = $transfer;
                    },
                    'progress' => static function ($total, $downloaded): void {
                        if ($total > 4194304 || $downloaded > 4194304) {
                            throw new RuntimeException('Bounded response exceeded.');
                        }
                    }])->post(Target::URI, $target->parameters);

            return new Transfer([...$base, 'response_received_at' => Contract::now(), 'http_status' => $response->status(),
                'content_type' => $response->header('Content-Type'),
                'effective_uri' => $response->effectiveUri() === null ? null : (string) $response->effectiveUri(),
                'failure' => null, 'curl_request_size' => $response->handlerStats()['request_size'] ?? null], $response);
        } catch (Throwable $error) {
            $failure = $error instanceof ConnectionException
                ? (str_contains($error->getMessage(), 'cURL error 28') ? 'TIMEOUT' : 'CONNECTION_FAILURE') : 'TRANSPORT_FAILURE';

            return new Transfer([...$base, 'failure' => $failure, 'failed_at' => Contract::now(),
                'effective_uri' => $stats === null ? null : (string) $stats->getEffectiveUri(),
                'curl_request_size' => $stats?->getHandlerStats()['request_size'] ?? null, 'exception_class' => $error::class]);
        }
    }
}
