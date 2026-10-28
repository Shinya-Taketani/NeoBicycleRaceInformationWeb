<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\PrestartCapture\Pj0315Probe;

use RuntimeException;
use Throwable;

final class ProbeFailure extends RuntimeException
{
    public function __construct(public readonly array $evidence, Throwable $previous)
    {
        parent::__construct('Technical probe failed; see verified retention facts.', 0, $previous);
    }
}
