<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\PrestartCapture\Pj0315Probe;

use Illuminate\Http\Client\Response;

/** Retain the HTTP response/stream until the caller has flushed the exclusive Raw sink. */
final readonly class Transfer
{
    public function __construct(public array $metadata, public ?Response $response = null) {}
}
