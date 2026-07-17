<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Exceptions;

use App\Domain\Keirin\Scraping\DTO\FetchedResponseDto;
use App\Domain\Keirin\Scraping\Enums\FetchErrorType;
use RuntimeException;
use Throwable;

class KeirinHttpException extends RuntimeException
{
    public function __construct(
        public readonly FetchErrorType $errorType,
        public readonly string $url,
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?FetchedResponseDto $response = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
