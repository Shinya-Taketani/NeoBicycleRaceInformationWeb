<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Statistics\DTO\Batch03BuildOptionsDto;
use App\Domain\Keirin\Statistics\Services\Batch03FeatureBuildService;
use DateTimeImmutable;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

class BuildBatch03FeaturesCommand extends Command
{
    protected $signature = 'keirin:statistics:build-batch03
        {--stat01-run-id= : Required source STAT-01 feature run ID}
        {--history-from= : Required earliest history date in YYYY-MM-DD}
        {--from= : First target race date in YYYY-MM-DD}
        {--to= : Last target race date in YYYY-MM-DD}
        {--race-id= : One internal races.id}
        {--chunk=200 : Race ID keyset page size (1-1000)}
        {--dry-run : Calculate all six statistics without writing statistic tables}';

    protected $description = 'Build existing-database Batch03 context and race-stage statistics.';

    public function handle(Batch03FeatureBuildService $service): int
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
        $hasErrors = false;
        foreach ($result->stats as $stat) {
            $hasErrors = $hasErrors || $stat->errorCount > 0;
            $this->line(
                "stat_code={$stat->statCode} feature_run_id=".($stat->runId ?? 'dry-run')
                ." processed_races={$stat->processedRaces} result_count={$stat->resultCount}"
                ." valid={$stat->validCount} no_history={$stat->noHistoryCount}"
                ." not_applicable={$stat->notApplicableCount} partial_history={$stat->partialHistoryCount}"
                ." partial={$stat->partialCount} missing={$stat->missingCount}"
                ." invalid={$stat->invalidCount} errors={$stat->errorCount}",
            );
        }

        return $hasErrors ? self::FAILURE : self::SUCCESS;
    }

    private function validatedOptions(): Batch03BuildOptionsDto
    {
        $runId = $this->requiredPositiveInteger('stat01-run-id');
        $historyFrom = $this->date($this->requiredOption('history-from'), '--history-from');
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
        if ($from !== null && $historyFrom >= $from) {
            throw new InvalidArgumentException('--history-from must be before --from.');
        }
        $chunk = $this->positiveInteger($this->nullableOption('chunk') ?? '200', '--chunk');
        if ($chunk > 1000) {
            throw new InvalidArgumentException('--chunk must be between 1 and 1000.');
        }

        return new Batch03BuildOptionsDto($runId, $historyFrom, $from, $to, $raceId, $chunk, (bool) $this->option('dry-run'));
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
