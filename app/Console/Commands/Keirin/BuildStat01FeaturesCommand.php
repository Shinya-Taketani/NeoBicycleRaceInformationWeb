<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Statistics\DTO\Stat01BuildOptionsDto;
use App\Domain\Keirin\Statistics\Services\Stat01FeatureBuildService;
use DateTimeImmutable;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

class BuildStat01FeaturesCommand extends Command
{
    protected $signature = 'keirin:statistics:build-stat01
        {--from= : First target race date in YYYY-MM-DD}
        {--to= : Last target race date in YYYY-MM-DD}
        {--race-id= : One internal races.id}
        {--chunk=200 : Race ID keyset page size (1-1000)}
        {--dry-run : Calculate without writing statistic tables}';

    protected $description = 'Build existing-database STAT-01 race-score features.';

    public function handle(Stat01FeatureBuildService $service): int
    {
        try {
            $options = $this->validatedOptions();
            $result = $service->build($options);
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }

        $this->info($result->dryRun ? 'run=dry-run' : 'feature_run_id='.$result->runId);
        if ($result->runUuid !== null) {
            $this->line('run_uuid='.$result->runUuid);
        }
        $this->line(
            "target_races={$result->targetRaceCount} processed_races={$result->processedRaceCount} "
            ."target_entries={$result->targetEntryCount} success={$result->successCount} "
            ."partial={$result->partialCount} missing={$result->missingCount} "
            ."invalid={$result->invalidCount} errors={$result->errorCount}",
        );

        return $result->errorCount === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function validatedOptions(): Stat01BuildOptionsDto
    {
        $raceIdText = $this->nullableOption('race-id');
        $fromText = $this->nullableOption('from');
        $toText = $this->nullableOption('to');
        if ($raceIdText !== null && ($fromText !== null || $toText !== null)) {
            throw new InvalidArgumentException('--race-id cannot be combined with --from or --to.');
        }
        if ($raceIdText === null && ($fromText === null || $toText === null)) {
            throw new InvalidArgumentException('Specify --race-id or both --from and --to.');
        }

        $raceId = $raceIdText !== null ? $this->positiveInteger($raceIdText, '--race-id') : null;
        $from = $fromText !== null ? $this->date($fromText, '--from') : null;
        $to = $toText !== null ? $this->date($toText, '--to') : null;
        if ($from !== null && $to !== null && $from > $to) {
            throw new InvalidArgumentException('--from must not be after --to.');
        }

        $chunkText = $this->nullableOption('chunk') ?? '200';
        $chunk = $this->positiveInteger($chunkText, '--chunk');
        if ($chunk > 1000) {
            throw new InvalidArgumentException('--chunk must be between 1 and 1000.');
        }

        return new Stat01BuildOptionsDto(
            from: $from,
            to: $to,
            raceId: $raceId,
            chunkSize: $chunk,
            dryRun: (bool) $this->option('dry-run'),
        );
    }

    private function nullableOption(string $name): ?string
    {
        $value = $this->option($name);
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function positiveInteger(string $value, string $option): int
    {
        if (preg_match('/^\d+$/', $value) !== 1 || (int) $value < 1) {
            throw new InvalidArgumentException("{$option} must be a positive integer.");
        }

        return (int) $value;
    }

    private function date(string $value, string $option): DateTimeImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw new InvalidArgumentException("{$option} must be a valid date in YYYY-MM-DD format.");
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (! $date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException("{$option} must be a real calendar date.");
        }

        return $date;
    }
}
