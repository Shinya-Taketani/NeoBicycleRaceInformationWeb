<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Statistics\DTO\Stat20AcquiredHistoryOptionsDto;
use App\Domain\Keirin\Statistics\Services\Stat20AcquiredHistoryBuildService;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

final class BuildStat20AcquiredHistoryCommand extends Command
{
    protected $signature = 'keirin:statistics:build-stat20-acquired-history
        {--stat01-run-id= : Fixed completed STAT01 run}
        {--source-run-id=* : Explicit upstream runs, at most nineteen different statistics}
        {--source-contract=STAT-20-acquired-history-v2 : Explicit v2 or grade-breakdown v3 audit contract}
        {--race-id= : Single race}
        {--from= : First target JST date, 2022-2025}
        {--to= : Last target JST date, 2022-2025}
        {--chunk=100 : Keyset page size 1-1000}
        {--dry-run : Calculate without run/item/result writes}';

    protected $description = 'Audit twenty saved upstream statistics; no confidence or prediction authorization.';

    public function handle(Stat20AcquiredHistoryBuildService $service): int
    {
        try {
            $r = $service->build(new Stat20AcquiredHistoryOptionsDto($this->positive($this->option('stat01-run-id')),
                array_map($this->positive(...), $this->option('source-run-id')), $this->date($this->option('from')),
                $this->date($this->option('to')), $this->option('race-id') === null ? null : $this->positive($this->option('race-id')),
                $this->positive($this->option('chunk')), (bool) $this->option('dry-run'), (string) $this->option('source-contract')));
            $this->line(json_encode($r, JSON_THROW_ON_ERROR));

            return $r->counts['errors'] > 0 ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function positive(mixed $v): int
    {
        if (! is_scalar($v) || preg_match('/^[1-9]\d*$/D', (string) $v) !== 1 || (float) $v > PHP_INT_MAX) {
            throw new InvalidArgumentException('Positive integer required.');
        }

        return (int) $v;
    }

    private function date(mixed $v): ?DateTimeImmutable
    {
        if ($v === null) {
            return null;
        }
        if (! is_string($v) || preg_match('/^202[2-5]-\d{2}-\d{2}$/D', $v) !== 1) {
            throw new InvalidArgumentException('Explicit 2022-2025 JST calendar date required.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $v, new DateTimeZone('Asia/Tokyo'));
        if ($date === false || $date->format('Y-m-d') !== $v) {
            throw new InvalidArgumentException('Invalid calendar date.');
        }

        return $date;
    }
}
