<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Enums;

enum FetchErrorType: string
{
    case TooManyRequests = 'HTTP_429';
    case ServerError = 'HTTP_5XX';
    case Timeout = 'TIMEOUT';
    case ConnectionFailed = 'CONNECTION_FAILED';
    case HttpError = 'HTTP_ERROR';
    case EmptyResponse = 'EMPTY_RESPONSE';
    case EncodingConversionFailed = 'ENCODING_CONVERSION_FAILED';
    case Unknown = 'UNKNOWN';
}
