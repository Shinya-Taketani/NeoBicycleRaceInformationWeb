<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\DTO;

use DateTimeImmutable;

readonly class OfficialMethodHistoryInputDto
{
    public function __construct(public array $target, public array $entry, public DateTimeImmutable $inputAsOf,
        public iterable $history) {}
}
