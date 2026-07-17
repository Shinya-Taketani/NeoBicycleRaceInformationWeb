<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Services;

use App\Domain\Keirin\Scraping\DTO\FetchedResponseDto;
use App\Domain\Keirin\Scraping\DTO\StoredRawResponseDto;
use App\Domain\Keirin\Scraping\Enums\FetchErrorType;
use App\Domain\Keirin\Scraping\Exceptions\CharacterEncodingConversionException;
use App\Domain\Keirin\Scraping\Support\CharacterEncodingConverter;
use App\Models\ScrapingFetchLog;
use Illuminate\Support\Facades\Storage;
use Throwable;

class RawResponseStorageService
{
    public function __construct(private readonly CharacterEncodingConverter $converter) {}

    public function store(FetchedResponseDto $response, ?int $batchRunId = null, ?FetchErrorType $errorType = null, ?string $errorMessage = null): StoredRawResponseDto
    {
        $sha256 = hash('sha256', $response->body);
        $path = $this->path($response, $sha256);
        Storage::disk((string) config('keirin.raw_disk'))->put($path, $response->body);

        $utf8Body = '';
        $detectedEncoding = null;
        $conversionSucceeded = false;
        $resolvedErrorType = $errorType;
        $resolvedErrorMessage = $errorMessage;

        try {
            [$utf8Body, $detectedEncoding] = $this->converter->convertToUtf8($response->body, $response->contentType);
            $conversionSucceeded = true;
        } catch (CharacterEncodingConversionException $exception) {
            $resolvedErrorType = FetchErrorType::EncodingConversionFailed;
            $resolvedErrorMessage = $exception->getMessage();
        }

        $log = ScrapingFetchLog::query()->create([
            'batch_run_id' => $batchRunId,
            'source' => $response->source,
            'request_method' => $response->method,
            'request_url' => $response->url,
            'request_key' => $response->requestKey,
            'http_status' => $response->httpStatus,
            'fetched_at' => $response->fetchedAt,
            'content_type' => $response->contentType,
            'detected_encoding' => $detectedEncoding,
            'utf8_conversion_succeeded' => $conversionSucceeded,
            'response_size' => strlen($response->body),
            'sha256' => $sha256,
            'raw_file_path' => $path,
            'retry_count' => $response->retryCount,
            'parser_version' => (string) config('keirin.parser_version'),
            'error_type' => $resolvedErrorType?->value,
            'error_message' => $resolvedErrorMessage,
        ]);

        if (! $conversionSucceeded) {
            throw new CharacterEncodingConversionException($resolvedErrorMessage ?? 'Failed to convert response to UTF-8.');
        }

        return new StoredRawResponseDto(
            rawFilePath: $path,
            sha256: $sha256,
            responseSize: strlen($response->body),
            detectedEncoding: $detectedEncoding,
            utf8ConversionSucceeded: $conversionSucceeded,
            utf8Body: $utf8Body,
            fetchLogId: (int) $log->id,
        );
    }

    public function storeFailure(FetchedResponseDto $response, FetchErrorType $errorType, Throwable $exception, ?int $batchRunId = null): void
    {
        $this->store($response, $batchRunId, $errorType, $exception->getMessage());
    }

    private function path(FetchedResponseDto $response, string $sha256): string
    {
        $date = $response->fetchedAt->setTimezone(new \DateTimeZone((string) config('app.timezone')))->format('Y/m/d');
        $time = $response->fetchedAt->format('YmdHis.u');
        $requestKey = substr($response->requestKey, 0, 24);

        return trim((string) config('keirin.raw_root'), '/')."/{$response->source}/{$date}/{$requestKey}-{$time}-".substr($sha256, 0, 16).'.html';
    }
}
