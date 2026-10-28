<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\PrestartCapture\Observation;

use App\Domain\Keirin\Scraping\PrestartCapture\Contract;
use App\Domain\Keirin\Scraping\PrestartCapture\Pj0315Probe\Transfer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use RuntimeException;
use Throwable;

final class Pj0315ObservationTransport
{
    public function __construct(private readonly Factory $http) {}

    /** No real transport authorization exists yet: preserve the real POST path, but require an injected HTTP fake. */
    public function exchange(array $parameters, mixed $sink): Transfer
    {
        $keys = array_keys($parameters);
        sort($keys);
        if ($keys !== ['disp', 'encp'] || $parameters['disp'] !== 'PJ0315' || ! is_string($parameters['encp'])
            || $parameters['encp'] === '' || strlen($parameters['encp']) > 8192 || ! is_resource($sink)) {
            throw new RuntimeException('Only a bound PJ0315 form and exclusive sink are accepted.');
        }
        $this->http->preventStrayRequests()->allowStrayRequests([]);
        $base = ['request_started_at' => Contract::now(), 'response_received_at' => null, 'http_status' => null,
            'effective_uri' => null, 'content_type' => null, 'retry_count' => 0, 'follow_redirects' => false];
        try {
            $response = $this->http->asForm()->withoutRedirecting()->connectTimeout(5)->timeout(20)
                ->withHeaders(['User-Agent' => 'NeoBicycleRaceInformationWeb/PJ0315-observation-v1',
                    'Accept-Encoding' => 'identity', 'Referer' => 'https://keirin.jp/pc/racelist'])
                ->withOptions(['sink' => $sink, 'http_errors' => false, 'decode_content' => false,
                    'progress' => static function ($total, $received): void {
                        if ($total > 4194304 || $received > 4194304) {
                            throw new RuntimeException('Response limit exceeded.');
                        }
                    }])->post(ObservationContract::URI, $parameters);

            return new Transfer([...$base, 'response_received_at' => Contract::now(), 'http_status' => $response->status(),
                'effective_uri' => $response->effectiveUri() === null ? null : (string) $response->effectiveUri(),
                'content_type' => $response->header('Content-Type'), 'retry_after' => $response->header('Retry-After'),
                'location' => $response->header('Location'), 'failure' => null], $response);
        } catch (Throwable $error) {
            return new Transfer([...$base, 'failed_at' => Contract::now(),
                'failure' => $error instanceof ConnectionException && str_contains($error->getMessage(), '28') ? 'TIMEOUT' : 'TRANSPORT_FAILURE']);
        }
    }
}
