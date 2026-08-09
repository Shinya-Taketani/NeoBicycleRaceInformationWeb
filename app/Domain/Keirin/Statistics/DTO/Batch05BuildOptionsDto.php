<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\DTO;

use DateTimeImmutable;

readonly class Batch05BuildOptionsDto
{
    public function __construct(
        public int $stat01RunId,
        public ?DateTimeImmutable $from,
        public ?DateTimeImmutable $to,
        public ?int $raceId,
        public int $chunkSize,
        public bool $dryRun,
    ) {}

    /** @return array<string, bool|int|string|null> */
    public function parameters(string $batchExecutionUuid): array
    {
        return [
            'batch_execution_uuid' => $batchExecutionUuid,
            'stat01_run_id' => $this->stat01RunId,
            'from' => $this->from?->format('Y-m-d'),
            'to' => $this->to?->format('Y-m-d'),
            'race_id' => $this->raceId,
            'chunk' => $this->chunkSize,
            'dry_run' => $this->dryRun,
            'result_grain' => 'RACE',
            'source_mode' => 'STAT01_SNAPSHOT_ONLY',
            'composite_score_policy' => 'BACKTEST_PENDING',
        ];
    }
}
