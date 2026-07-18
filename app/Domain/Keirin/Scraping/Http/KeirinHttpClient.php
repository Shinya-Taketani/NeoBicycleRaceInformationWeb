<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Http;

use App\Domain\Keirin\Scraping\DTO\FetchedResponseDto;
use App\Domain\Keirin\Scraping\Enums\FetchErrorType;
use App\Domain\Keirin\Scraping\Exceptions\KeirinHttpException;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class KeirinHttpClient
{
    private readonly ConnectionFailureClassifier $connectionFailures;

    public function __construct(?ConnectionFailureClassifier $connectionFailures = null)
    {
        $this->connectionFailures = $connectionFailures ?? new ConnectionFailureClassifier;
    }

    public function get(string $pathOrUrl, array $query = [], ?int $sleepMsOverride = null, array $headers = []): FetchedResponseDto
    {
        return $this->request('GET', $pathOrUrl, $query, $sleepMsOverride, $headers);
    }

    public function postForm(string $pathOrUrl, array $form, ?int $sleepMsOverride = null, array $headers = []): FetchedResponseDto
    {
        return $this->request('POST', $pathOrUrl, $form, $sleepMsOverride, $headers);
    }

    private function request(string $method, string $pathOrUrl, array $parameters, ?int $sleepMsOverride, array $headers): FetchedResponseDto
    {
        $url = $this->url($pathOrUrl);
        $sleepMs = $sleepMsOverride ?? (int) config('keirin.sleep_ms', 1000);

        if ($sleepMs > 0) {
            usleep($sleepMs * 1000);
        }

        $retryCount = 0;
        $response = null;

        try {
            $request = Http::withHeaders([
                'User-Agent' => (string) config('keirin.user_agent'),
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                ...$headers,
            ])
                ->connectTimeout((float) config('keirin.connect_timeout_seconds', 5))
                ->timeout((float) config('keirin.timeout_seconds', 20))
                ->retry(
                    (int) config('keirin.retry_times', 2),
                    fn (int $attempt): int => (int) config('keirin.retry_base_sleep_ms', 500) * (2 ** max(0, $attempt - 1)),
                    function ($exception, $request) use (&$retryCount): bool {
                        $retryCount++;

                        if ($exception instanceof ConnectionException) {
                            return true;
                        }

                        if ($exception instanceof RequestException) {
                            $status = $exception->response?->status();

                            return $status === 429 || ($status !== null && $status >= 500);
                        }

                        return false;
                    },
                    throw: false,
                );

            $response = $method === 'POST'
                ? $request->asForm()->post($url, $parameters)
                : $request->get($url, $parameters);
        } catch (ConnectionException $exception) {
            throw new KeirinHttpException(
                $this->connectionFailures->classify($exception),
                $url,
                $exception->getMessage(),
                null,
                $this->emptyResponse($method, $url, $parameters, $retryCount),
                $exception,
            );
        }

        if ($response === null) {
            throw new KeirinHttpException(FetchErrorType::Unknown, $url, 'HTTP response was not created.');
        }

        return new FetchedResponseDto(
            source: (string) config('keirin.source'),
            method: $method,
            url: $response->effectiveUri() !== null ? (string) $response->effectiveUri() : $url,
            requestKey: $this->requestKey($method, $url, $parameters),
            httpStatus: $response->status(),
            body: $response->body(),
            contentType: $response->header('Content-Type'),
            fetchedAt: new DateTimeImmutable('now'),
            retryCount: $retryCount,
            requestParameters: $parameters,
        );
    }

    private function url(string $pathOrUrl): string
    {
        if (Str::startsWith($pathOrUrl, ['http://', 'https://'])) {
            return $pathOrUrl;
        }

        return rtrim((string) config('keirin.base_url'), '/').'/'.ltrim($pathOrUrl, '/');
    }

    private function requestKey(string $method, string $url, array $parameters): string
    {
        ksort($parameters);

        return hash('sha256', $method.' '.$url.'?'.http_build_query($parameters));
    }

    private function emptyResponse(string $method, string $url, array $parameters, int $retryCount): FetchedResponseDto
    {
        return new FetchedResponseDto(
            source: (string) config('keirin.source'),
            method: $method,
            url: $url,
            requestKey: $this->requestKey($method, $url, $parameters),
            httpStatus: null,
            body: '',
            contentType: null,
            fetchedAt: new DateTimeImmutable('now'),
            retryCount: $retryCount,
            requestParameters: $parameters,
        );
    }
}
