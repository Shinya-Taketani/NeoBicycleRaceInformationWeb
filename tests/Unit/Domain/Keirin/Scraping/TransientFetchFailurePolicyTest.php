<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Scraping;

use App\Domain\Keirin\Scraping\Enums\FetchErrorType;
use App\Domain\Keirin\Scraping\Exceptions\KeirinHttpException;
use App\Domain\Keirin\Scraping\Support\TransientFetchFailurePolicy;
use PHPUnit\Framework\TestCase;

class TransientFetchFailurePolicyTest extends TestCase
{
    public function test_it_retries_only_explicit_transient_fetch_failures(): void
    {
        $policy = new TransientFetchFailurePolicy;

        foreach ([
            FetchErrorType::DnsFailure,
            FetchErrorType::Timeout,
            FetchErrorType::ConnectionFailed,
            FetchErrorType::TooManyRequests,
            FetchErrorType::ServerError,
        ] as $errorType) {
            $this->assertTrue($policy->isRetryable($this->exception($errorType)), $errorType->value);
        }
    }

    public function test_it_does_not_retry_permanent_or_unknown_fetch_failures(): void
    {
        $policy = new TransientFetchFailurePolicy;

        foreach ([
            FetchErrorType::HttpError,
            FetchErrorType::EmptyResponse,
            FetchErrorType::EncodingConversionFailed,
            FetchErrorType::Unknown,
        ] as $errorType) {
            $this->assertFalse($policy->isRetryable($this->exception($errorType)), $errorType->value);
        }
    }

    private function exception(FetchErrorType $errorType): KeirinHttpException
    {
        return new KeirinHttpException(
            $errorType,
            'https://keirin.jp/pc/racelive',
            'Synthetic fetch failure.',
        );
    }
}
