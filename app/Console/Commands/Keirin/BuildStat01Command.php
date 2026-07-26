<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Statistics\Services\Stat01BuildService;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Throwable;

class BuildStat01Command extends Command
{
    protected $signature = 'keirin:statistics:build-stat01
        {--from= : First race date in YYYY-MM-DD}
        {--to= : Last race date in YYYY-MM-DD}
        {--race-id= : Internal races.id}
        {--chunk=500 : Number of races loaded per chunk}
        {--dry-run : Calculate without writing audit or result rows}
        {--recalculate : Refresh an existing identical snapshot result}';

    protected $description = 'Build audited STAT-01 race-score features.';

    public function handle(Stat01BuildService $service): int
    {
        $scope = $this->scope();
        if ($scope === null) {
            return self::FAILURE;
        }
        $chunk = $this->positiveInteger($this->option('chunk'), '--chunk');
        if ($chunk === null || $chunk > 5000) {
            $this->error('--chunk must be an integer between 1 and 5000.');

            return self::FAILURE;
        }

        try {
            $summary = $service->build(
                $scope['from'],
                $scope['to'],
                $scope['race_id'],
                $chunk,
                (bool) $this->option('dry-run'),
                (bool) $this->option('recalculate'),
            );
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }

        $this->info('calculation_run_id='.($summary->run?->id ?? 'dry-run'));
        $this->line(
            "races={$summary->processedRaceCount}/{$summary->targetRaceCount} "
            ."targets={$summary->targetCount} success={$summary->successCount} "
            ."partial={$summary->partialCount} missing={$summary->missingCount} "
            ."invalid={$summary->invalidCount} failed={$summary->errorCount}",
        );
        foreach ($summary->errors as $error) {
            $this->error($error);
        }
        if (! $summary->hasTargets()) {
            $this->warn('No target races were found.');

            return self::FAILURE;
        }

        return $summary->errorCount === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array{from:?DateTimeImmutable,to:?DateTimeImmutable,race_id:?int}|null
     */
    private function scope(): ?array
    {
        $raceIdOption = $this->option('race-id');
        $hasRaceId = $raceIdOption !== null && $raceIdOption !== '';
        $hasDates = $this->option('from') !== null || $this->option('to') !== null;
        if ($hasRaceId && $hasDates) {
            $this->error('Use either --race-id or --from/--to, not both.');

            return null;
        }
        if ($hasRaceId) {
            $raceId = $this->positiveInteger($raceIdOption, '--race-id');

            return $raceId === null ? null : ['from' => null, 'to' => null, 'race_id' => $raceId];
        }

        $from = $this->date($this->option('from'), '--from');
        $to = $this->date($this->option('to'), '--to');
        if (! $from instanceof DateTimeImmutable || ! $to instanceof DateTimeImmutable) {
            return null;
        }
        if ($from > $to) {
            $this->error('--from must be before or equal to --to.');

            return null;
        }

        return ['from' => $from, 'to' => $to, 'race_id' => null];
    }

    private function date(mixed $value, string $option): ?DateTimeImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            $this->error("{$option} must be a valid YYYY-MM-DD date.");

            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (! $date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $value) {
            $this->error("{$option} must be a real calendar date.");

            return null;
        }

        return $date;
    }

    private function positiveInteger(mixed $value, string $option): ?int
    {
        if (! is_numeric($value) || (int) $value < 1 || (string) (int) $value !== (string) $value) {
            $this->error("{$option} must be a positive integer.");

            return null;
        }

        return (int) $value;
    }
}
