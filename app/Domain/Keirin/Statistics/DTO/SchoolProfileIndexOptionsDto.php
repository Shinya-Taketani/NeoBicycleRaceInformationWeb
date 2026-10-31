<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\DTO;

use InvalidArgumentException;

final readonly class SchoolProfileIndexOptionsDto
{
    public function __construct(public string $path, public string $sha256)
    {
        if (! str_starts_with($path, '/') || preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1) {
            throw new InvalidArgumentException('Explicit absolute school selection and SHA required.');
        }
    }
}
