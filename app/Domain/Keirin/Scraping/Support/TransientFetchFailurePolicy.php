<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Support;

use App\Domain\Keirin\Scraping\Enums\FetchErrorType;
use App\Domain\Keirin\Scraping\Exceptions\KeirinHttpException;

final class TransientFetchFailurePolicy
{
    public function isRetryable(KeirinHttpException $exception): bool
    {
        return in_array($exception->errorType, [
            FetchErrorType::DnsFailure,
            FetchErrorType::Timeout,
            FetchErrorType::ConnectionFailed,
            FetchErrorType::TooManyRequests,
            FetchErrorType::ServerError,
        ], true);
    }
}
