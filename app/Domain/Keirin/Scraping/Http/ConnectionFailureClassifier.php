<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Http;

use App\Domain\Keirin\Scraping\Enums\FetchErrorType;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

final class ConnectionFailureClassifier
{
    private const CURL_OPERATION_TIMED_OUT = 28;

    private const CURL_COULD_NOT_CONNECT = 7;

    private const CURL_COULD_NOT_RESOLVE_HOST = 6;

    public function classify(ConnectionException $exception): FetchErrorType
    {
        $current = $exception->getPrevious();

        while ($current instanceof Throwable) {
            if ($current instanceof ConnectException || $current instanceof GuzzleRequestException) {
                $classified = $this->fromHandlerContext($current->getHandlerContext());
                if ($classified !== null) {
                    return $classified;
                }
            }

            $current = $current->getPrevious();
        }

        $message = strtolower($exception->getMessage());

        return match (true) {
            str_contains($message, 'timed out'), str_contains($message, 'timeout') => FetchErrorType::Timeout,
            str_contains($message, 'could not resolve host'), str_contains($message, 'name or service not known'), str_contains($message, 'getaddrinfo') => FetchErrorType::DnsFailure,
            str_contains($message, 'connection refused'), str_contains($message, 'failed to connect'), str_contains($message, 'could not connect') => FetchErrorType::ConnectionFailed,
            default => FetchErrorType::Unknown,
        };
    }

    /**
     * @param  array<string,mixed>  $context
     */
    private function fromHandlerContext(array $context): ?FetchErrorType
    {
        $errno = isset($context['errno']) && is_numeric($context['errno']) ? (int) $context['errno'] : null;

        return match ($errno) {
            self::CURL_OPERATION_TIMED_OUT => FetchErrorType::Timeout,
            self::CURL_COULD_NOT_RESOLVE_HOST => FetchErrorType::DnsFailure,
            self::CURL_COULD_NOT_CONNECT => FetchErrorType::ConnectionFailed,
            default => null,
        };
    }
}
