<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Statistics\Calculators\Stat41Calculator;
use App\Domain\Keirin\Statistics\DTO\Batch05BuildOptionsDto;
use App\Domain\Keirin\Statistics\Services\Batch05FeatureBuildService;
use DateTimeImmutable;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

class BuildBatch05FeaturesCommand extends Command
{
    protected $signature = 'keirin:statistics:build-batch05
        {--stat01-run-id= : Required source STAT-01 feature run ID}
        {--from= : First target race date in YYYY-MM-DD}
        {--to= : Last target race date in YYYY-MM-DD}
        {--race-id= : One internal races.id}
        {--chunk=200 : STAT-01 input_as_of/race ID keyset page size (1-1000)}
        {--dry-run : Calculate STAT-41 race structures without writing statistic tables}';

    protected $description = 'Build existing-database Batch05 race competitiveness and upset structures.';

    public function handle(Batch05FeatureBuildService $service): int
    {
        try {
            $result = $service->build($this->validatedOptions());
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }
        $this->info('batch_execution_uuid='.$result->batchExecutionUuid);
        $this->line('run='.($result->dryRun ? 'dry-run' : 'stored'));
        $this->line("target_races={$result->targetRaces} target_entries={$result->targetEntries}");
        $this->line(
            'stat_code='.Stat41Calculator::STAT_CODE.' feature_run_id='.($result->runId ?? 'dry-run')
            ." processed_races={$result->processedRaces} result_count={$result->resultCount}"
            ." valid={$result->validCount} no_history=0 not_applicable=0 partial_history=0 partial={$result->partialCount}"
            ." missing={$result->missingCount} invalid={$result->invalidCount} errors={$result->errorCount}",
        );

        return $result->errorCount > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function validatedOptions(): Batch05BuildOptionsDto
    {
        $runId = $this->requiredPositiveInteger('stat01-run-id');
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
        $chunk = $this->positiveInteger($this->nullableOption('chunk') ?? '200', '--chunk');
        if ($chunk > 1000) {
            throw new InvalidArgumentException('--chunk must be between 1 and 1000.');
        }

        return new Batch05BuildOptionsDto($runId, $from, $to, $raceId, $chunk, (bool) $this->option('dry-run'));
    }

    private function requiredPositiveInteger(string $name): int
    {
        return $this->positiveInteger($this->requiredOption($name), '--'.$name);
    }

    private function requiredOption(string $name): string
    {
        $value = $this->nullableOption($name);
        if ($value === null) {
            throw new InvalidArgumentException('--'.$name.' is required.');
        }

        return $value;
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
