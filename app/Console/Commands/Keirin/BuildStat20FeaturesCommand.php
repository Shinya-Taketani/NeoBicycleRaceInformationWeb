<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Statistics\DTO\Stat20BuildOptionsDto;
use App\Domain\Keirin\Statistics\Services\Stat20FeatureBuildService;
use DateTimeImmutable;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

class BuildStat20FeaturesCommand extends Command
{
    protected $signature = 'keirin:statistics:build-stat20
        {--stat01-run-id= : Required source STAT-01 run}
        {--source-run-id=* : Explicit category1 upstream runs; missing sources remain pending}
        {--from= : First target date, before 2026}
        {--to= : Last target date, before 2026}
        {--race-id= : One internal race ID}
        {--chunk=100 : Keyset page size (1-1000)}
        {--dry-run : Audit without persisting any statistics}';

    protected $description = 'Build STAT-20 quality evidence; no confidence weights, predictions, or source reconstruction.';

    public function handle(Stat20FeatureBuildService $service): int
    {
        try {
            $result = $service->build(new Stat20BuildOptionsDto(
                $this->positive($this->option('stat01-run-id')),
                array_map($this->positive(...), $this->option('source-run-id')),
                $this->date($this->option('from')), $this->date($this->option('to')),
                $this->option('race-id') !== null ? $this->positive($this->option('race-id')) : null,
                $this->positive($this->option('chunk')), (bool) $this->option('dry-run'),
            ));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->line('stat_code=STAT-20 feature_run_id='.($result->runId ?? 'dry-run'));
        $this->line("target_races={$result->targetRaces} target_entries={$result->targetEntries}");
        $this->line(json_encode($result->counts, JSON_THROW_ON_ERROR));

        return $result->counts['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function positive(mixed $value): int
    {
        if (! is_scalar($value) || preg_match('/^[1-9]\d*$/D', (string) $value) !== 1 || (float) $value > PHP_INT_MAX) {
            throw new InvalidArgumentException('Run, race and chunk values must be positive integers.');
        }

        return (int) $value;
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Invalid target calendar date.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Invalid target calendar date.');
        }

        return $date;
    }
}
