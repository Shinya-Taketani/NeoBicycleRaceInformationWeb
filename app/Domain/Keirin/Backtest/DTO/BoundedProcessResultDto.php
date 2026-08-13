<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\DTO;

readonly class BoundedProcessResultDto
{
    public function __construct(
        public int $exitCode,
        public string $stderr,
        public int $stdoutBytes,
    ) {}
}
