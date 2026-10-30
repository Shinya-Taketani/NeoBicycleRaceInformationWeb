<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Scraping\Services\RaceResultSyncService;
use DateTimeImmutable;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

class SyncRaceResultsCommand extends Command
{
    protected $signature = 'keirin:races:sync-results
        {--from= : YYYY-MM-DD}
        {--to= : YYYY-MM-DD}
        {--date= : Single YYYY-MM-DD date (overrides from/to)}
        {--race-id= : Internal races.id}
        {--track-code= : KEIRIN.JP track code}
        {--race-number= : Race number}
        {--limit= : Maximum number of races}
        {--force : Include races without a result-available flag}
        {--sleep-ms= : Request interval override in milliseconds}
        {--retry-failed-batch-run-id= : Retry only FAILED races from a completed result Batch Run}
        {--transient-retry-passes= : Deferred retry passes for transient fetch failures}
        {--transient-retry-sleep-ms= : Wait between deferred retry passes in milliseconds}';

    protected $description = 'Sync KEIRIN.JP race details, results, and payouts.';

    public function handle(RaceResultSyncService $results): int
    {
        try {
            $sourceBatchRunId = $this->integerOption('retry-failed-batch-run-id', 1);
            if ($sourceBatchRunId !== null) {
                $this->assertRetryModeOptions();
                $result = $results->retryFailedBatch(
                    $sourceBatchRunId,
                    $this->optionsForService(retryMode: true),
                );
            } else {
                [$from, $to] = $this->dateRange();
                $result = $results->sync($from, $to, $this->optionsForService(retryMode: false));
            }
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }

        $this->info("batch_run_id={$result['batch_run']->id}");
        $this->line("success={$result['success']} skipped={$result['skipped']} failed={$result['failed']} results={$result['results']} payouts={$result['payouts']}");
        if ($result['failed'] > 0 && $result['batch_run']->error_message !== null) {
            $this->error($result['batch_run']->error_message);
        }

        return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return array{0:DateTimeImmutable,1:DateTimeImmutable} */
    private function dateRange(): array
    {
        $single = $this->option('date');
        if (is_string($single) && $single !== '') {
            $date = $this->date($single, '--date');

            return [$date, $date];
        }
        $from = $this->date($this->option('from'), '--from');
        $to = $this->date($this->option('to'), '--to');
        if ($from > $to) {
            throw new InvalidArgumentException('--from must be before or equal to --to.');
        }

        return [$from, $to];
    }

    private function date(mixed $value, string $option): DateTimeImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw new InvalidArgumentException("{$option} must be a valid YYYY-MM-DD date.");
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (! $date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException("{$option} must be a real calendar date.");
        }

        return $date;
    }

    private function optionsForService(bool $retryMode): array
    {
        $options = [
            'transient_retry_passes' => $this->integerOption('transient-retry-passes', 0)
                ?? $this->configuredNonNegativeInteger('keirin.result_transient_retry_passes'),
            'transient_retry_sleep_ms' => $this->integerOption('transient-retry-sleep-ms', 0)
                ?? $this->configuredNonNegativeInteger('keirin.result_transient_retry_sleep_ms'),
        ];
        foreach (['limit' => 1, 'sleep-ms' => 0] as $option => $minimum) {
            $value = $this->integerOption($option, $minimum);
            if ($value !== null) {
                $options[str_replace('-', '_', $option)] = $value;
            }
        }

        if (! $retryMode) {
            $options['force'] = (bool) $this->option('force');
            foreach (['race-id' => 'race_id', 'race-number' => 'race_number'] as $option => $key) {
                $value = $this->integerOption($option, 1);
                if ($value !== null) {
                    $options[$key] = $value;
                }
            }
        }
        if (! $retryMode && is_string($this->option('track-code')) && $this->option('track-code') !== '') {
            $options['track_code'] = (string) $this->option('track-code');
        }

        return $options;
    }

    private function assertRetryModeOptions(): void
    {
        foreach (['date', 'from', 'to', 'race-id', 'track-code', 'race-number'] as $option) {
            if ($this->hasValue($this->option($option))) {
                throw new InvalidArgumentException("--{$option} cannot be used with --retry-failed-batch-run-id.");
            }
        }
        if ((bool) $this->option('force')) {
            throw new InvalidArgumentException('--force cannot be used with --retry-failed-batch-run-id.');
        }
    }

    private function integerOption(string $name, int $minimum): ?int
    {
        $value = $this->option($name);
        if (! $this->hasValue($value)) {
            return null;
        }
        $text = is_string($value) || is_int($value) ? (string) $value : '';
        if (preg_match('/^\d+$/', $text) !== 1 || (int) $text < $minimum) {
            throw new InvalidArgumentException("--{$name} must be an integer greater than or equal to {$minimum}.");
        }

        return (int) $text;
    }

    private function configuredNonNegativeInteger(string $key): int
    {
        $value = config($key);
        if (! is_int($value) || $value < 0) {
            throw new InvalidArgumentException("{$key} must be a non-negative integer.");
        }

        return $value;
    }

    private function hasValue(mixed $value): bool
    {
        return $value !== null && $value !== '';
    }
}
