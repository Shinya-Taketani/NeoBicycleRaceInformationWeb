<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Scraping\Services\RaceListSyncService;
use DateTimeImmutable;
use Illuminate\Console\Command;

class SyncRaceListCommand extends Command
{
    protected $signature = 'keirin:races:sync-race-list
        {--from= : YYYY-MM-DD}
        {--to= : YYYY-MM-DD}
        {--date= : Single YYYY-MM-DD date (overrides from/to)}
        {--race-id= : Restrict to the day containing this existing race ID}
        {--track-code= : KEIRIN.JP track code}
        {--limit= : Maximum number of race days}
        {--force : Refresh already synchronized data}
        {--sleep-ms= : Request interval override in milliseconds}';

    protected $description = 'Sync KEIRIN.JP daily race lists and race entries.';

    public function handle(RaceListSyncService $raceLists): int
    {
        [$from, $to] = $this->dateRange();
        if (! $from instanceof DateTimeImmutable || ! $to instanceof DateTimeImmutable) {
            return self::FAILURE;
        }

        $options = $this->optionsForService();
        if ($options === null) {
            return self::FAILURE;
        }
        $result = $raceLists->sync($from, $to, $options);
        $this->info("batch_run_id={$result['batch_run']->id}");
        $this->line("days={$result['success']} races={$result['races']} entries={$result['entries']} unresolved_players={$result['unresolved_players']} failed={$result['failed']}");
        if ($result['failed'] > 0 && $result['batch_run']->error_message !== null) {
            $this->error($result['batch_run']->error_message);
        }

        return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return array{0:?DateTimeImmutable,1:?DateTimeImmutable} */
    private function dateRange(): array
    {
        $single = $this->option('date');
        if (is_string($single) && $single !== '') {
            $date = $this->date($single, '--date');

            return [$date, $date];
        }
        $from = $this->date($this->option('from'), '--from');
        $to = $this->date($this->option('to'), '--to');
        if ($from instanceof DateTimeImmutable && $to instanceof DateTimeImmutable && $from > $to) {
            $this->error('--from must be before or equal to --to.');

            return [null, null];
        }

        return [$from, $to];
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

    private function optionsForService(): ?array
    {
        $options = ['force' => (bool) $this->option('force')];
        foreach (['race-id' => 'race_id', 'limit' => 'limit', 'sleep-ms' => 'sleep_ms'] as $option => $key) {
            $value = $this->option($option);
            if ($value === null) {
                continue;
            }
            $minimum = $option === 'sleep-ms' ? 0 : 1;
            if (! is_numeric($value) || (int) $value < $minimum) {
                $this->error("--{$option} must be an integer greater than or equal to {$minimum}.");

                return null;
            }
            $options[$key] = (int) $value;
        }
        if (is_string($this->option('track-code')) && $this->option('track-code') !== '') {
            $options['track_code'] = (string) $this->option('track-code');
        }

        return $options;
    }
}
