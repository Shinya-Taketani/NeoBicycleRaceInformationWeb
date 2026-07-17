<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Services;

use App\Domain\Keirin\Scraping\DTO\FetchedResponseDto;
use App\Domain\Keirin\Scraping\DTO\StoredRawResponseDto;
use App\Domain\Keirin\Scraping\Enums\FetchErrorType;
use App\Domain\Keirin\Scraping\Exceptions\CharacterEncodingConversionException;
use App\Domain\Keirin\Scraping\Exceptions\KeirinHttpException;

class ScrapingFetchService
{
    public function __construct(private readonly RawResponseStorageService $rawStorage) {}

    /**
     * @param  callable(): FetchedResponseDto  $fetch
     */
    public function fetch(callable $fetch, ?int $batchRunId = null): StoredRawResponseDto
    {
        try {
            $response = $fetch();
        } catch (KeirinHttpException $exception) {
            if ($exception->response !== null && $exception->response->body === '') {
                $this->rawStorage->logFailureWithoutBody($exception->response, $exception->errorType, $exception->getMessage(), $batchRunId);
            }

            throw $exception;
        }

        $errorType = $this->httpErrorType($response);
        $errorMessage = $this->httpErrorMessage($response);

        try {
            $stored = $this->rawStorage->store($response, $batchRunId, $errorType, $errorMessage);
        } catch (CharacterEncodingConversionException $exception) {
            if ($errorType !== null) {
                throw new KeirinHttpException($errorType, $response->url, $errorMessage ?? $exception->getMessage(), $response->httpStatus, $response, $exception);
            }

            throw $exception;
        }

        if ($errorType !== null) {
            throw new KeirinHttpException($errorType, $response->url, $errorMessage ?? 'HTTP request failed.', $response->httpStatus, $response);
        }

        if ($response->body === '') {
            throw new KeirinHttpException(FetchErrorType::EmptyResponse, $response->url, 'HTTP response body was empty.', $response->httpStatus, $response);
        }

        return $stored;
    }

    private function httpErrorType(FetchedResponseDto $response): ?FetchErrorType
    {
        return match (true) {
            $response->httpStatus === 429 => FetchErrorType::TooManyRequests,
            $response->httpStatus !== null && $response->httpStatus >= 500 => FetchErrorType::ServerError,
            $response->httpStatus !== null && $response->httpStatus >= 400 => FetchErrorType::HttpError,
            $response->body === '' => FetchErrorType::EmptyResponse,
            default => null,
        };
    }

    private function httpErrorMessage(FetchedResponseDto $response): ?string
    {
        return $response->httpStatus !== null && $response->httpStatus >= 400
            ? "KEIRIN.JP returned HTTP {$response->httpStatus}."
            : ($response->body === '' ? 'HTTP response body was empty.' : null);
    }
}
