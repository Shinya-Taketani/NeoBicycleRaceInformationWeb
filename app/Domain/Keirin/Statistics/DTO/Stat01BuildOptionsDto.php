<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\DTO;

use DateTimeImmutable;

readonly class Stat01BuildOptionsDto
{
    public function __construct(
        public ?DateTimeImmutable $from,
        public ?DateTimeImmutable $to,
        public ?int $raceId,
        public int $chunkSize,
        public bool $dryRun,
    ) {}

    public function historyFrom(): ?DateTimeImmutable
    {
        return $this->from?->modify('-1 year');
    }

    /** @return array<string, bool|int|string|null> */
    public function parameters(): array
    {
        return [
            'from' => $this->from?->format('Y-m-d'),
            'to' => $this->to?->format('Y-m-d'),
            'race_id' => $this->raceId,
            'chunk' => $this->chunkSize,
            'dry_run' => $this->dryRun,
        ];
    }
}
