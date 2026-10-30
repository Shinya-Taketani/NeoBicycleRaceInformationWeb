<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\DTO;

use App\Domain\Keirin\Statistics\Support\AcquiredHistorySupport;
use App\Domain\Keirin\Statistics\Support\Stat20AcquiredHistoryContract;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class Stat20AcquiredHistoryOptionsDto
{
    public ?DateTimeImmutable $from;

    public ?DateTimeImmutable $to;

    public function __construct(public int $stat01RunId, public array $sourceRunIds, ?DateTimeImmutable $from,
        ?DateTimeImmutable $to, public ?int $raceId, public int $chunkSize = 100, public bool $dryRun = false,
        public string $sourceContract = Stat20AcquiredHistoryContract::VERSION)
    {
        Stat20AcquiredHistoryContract::versions($sourceContract);
        $this->from = $from === null ? null : AcquiredHistorySupport::jst($from);
        $this->to = $to === null ? null : AcquiredHistorySupport::jst($to);
        if ($stat01RunId < 1 || $chunkSize < 1 || $chunkSize > 1000 || count($sourceRunIds) > 19
            || count($sourceRunIds) !== count(array_unique($sourceRunIds, SORT_REGULAR))
            || ($raceId !== null && ($raceId < 1 || $from !== null || $to !== null))
            || ($raceId === null && ($from === null || $to === null || $from > $to))) {
            throw new InvalidArgumentException('Explicit bounded STAT-20 v2 targets and up to nineteen distinct source runs required.');
        }
        foreach ([$this->from, $this->to] as $date) {
            if ($date !== null && ($date->format('Y') < '2022' || $date->format('Y') > '2025')) {
                throw new InvalidArgumentException('STAT-20 v2 targets must be within 2022-2025 JST.');
            }
        }
        foreach ($sourceRunIds as $id) {
            if (! is_int($id) || $id < 1 || $id === $stat01RunId) {
                throw new InvalidArgumentException('Invalid explicit STAT-20 v2 source run ID.');
            }
        }
    }
}
