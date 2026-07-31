<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Scraping\Services\RetiredPlayerBackfillService;
use App\Domain\Keirin\Scraping\Support\PlayerNameNormalizer;
use DateTimeImmutable;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

class BackfillRetiredPlayersCommand extends Command
{
    protected $signature = 'keirin:players:backfill-retired
        {--external-player-id= : Six-digit KEIRIN.JP player ID}
        {--from= : First race date in YYYY-MM-DD}
        {--to= : Last race date in YYYY-MM-DD}
        {--limit= : Maximum distinct player candidates}
        {--sleep-ms= : Request interval override in milliseconds}
        {--dry-run : Fetch and validate without changing players or race_entries}';

    protected $description = 'Backfill retired player masters and link unresolved race entries.';

    public function handle(RetiredPlayerBackfillService $service): int
    {
        try {
            $options = $this->validatedOptions();
            $result = $service->backfill($options);
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }

        foreach ($result['details'] as $detail) {
            $this->line('external_player_id='.$detail['external_player_id']);
            if (($detail['status'] ?? null) === 'retired') {
                $this->line('registration_number='.$detail['registration_number']);
                $this->line('name='.PlayerNameNormalizer::displayName($detail['name']));
                $this->line('status=retired');
                $this->line('prefecture='.($detail['prefecture'] ?? ''));
                $this->line('age='.($detail['age'] ?? ''));
                $this->line('graduation_period='.($detail['graduation_period'] ?? ''));
                $this->line('grade='.($detail['grade'] ?? ''));
                $this->line('retired_on='.$detail['retired_on']);
                $this->line('source_updated_at='.($detail['source_updated_at'] ?? ''));
                if ($options['dry_run']) {
                    $this->line('would_create_player='.(int) $detail['would_create_player']);
                    $this->line('would_update_player='.(int) $detail['would_update_player']);
                    $this->line('would_link_entries='.$detail['would_link_entries']);
                } else {
                    $this->line('created='.(int) $detail['created']);
                    $this->line('linked_entries='.$detail['linked_entries']);
                }
            } elseif (($detail['status'] ?? null) === 'skipped') {
                $this->line('skip_reason='.$detail['skip_reason']);
                $this->line('linked_entries='.($detail['linked_entries'] ?? 0));
            } else {
                $this->error('error='.$detail['error']);
            }
        }

        $this->info("batch_run_id={$result['batch_run']->id}");
        $this->line(
            "success={$result['success']} skipped={$result['skipped']} failed={$result['failed']} "
            ."players={$result['players']} linked_entries={$result['linked_entries']}",
        );

        return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function validatedOptions(): array
    {
        $externalPlayerId = $this->nullableOption('external-player-id');
        $fromText = $this->nullableOption('from');
        $toText = $this->nullableOption('to');
        $hasPeriod = $fromText !== null || $toText !== null;
        if (($externalPlayerId === null) === ! $hasPeriod) {
            throw new InvalidArgumentException('Specify either --external-player-id or both --from and --to.');
        }
        if ($hasPeriod && ($fromText === null || $toText === null)) {
            throw new InvalidArgumentException('Both --from and --to are required for period mode.');
        }
        if ($externalPlayerId !== null && preg_match('/^\d{6}$/', $externalPlayerId) !== 1) {
            throw new InvalidArgumentException('--external-player-id must be a six-digit number.');
        }

        $from = $fromText !== null ? $this->date($fromText, '--from') : null;
        $to = $toText !== null ? $this->date($toText, '--to') : null;
        if ($from !== null && $to !== null && $from > $to) {
            throw new InvalidArgumentException('--from must not be after --to.');
        }

        return [
            'external_player_id' => $externalPlayerId,
            'from' => $from?->format('Y-m-d'),
            'to' => $to?->format('Y-m-d'),
            'limit' => $this->integerOption('limit', 1),
            'sleep_ms' => $this->integerOption('sleep-ms', 0),
            'dry_run' => (bool) $this->option('dry-run'),
        ];
    }

    private function nullableOption(string $name): ?string
    {
        $value = $this->option($name);
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function integerOption(string $name, int $minimum): ?int
    {
        $value = $this->nullableOption($name);
        if ($value === null) {
            return null;
        }
        if (preg_match('/^-?\d+$/', $value) !== 1 || (int) $value < $minimum) {
            $operator = $minimum === 0 ? 'non-negative' : 'positive';
            throw new InvalidArgumentException("--{$name} must be a {$operator} integer.");
        }

        return (int) $value;
    }

    private function date(string $value, string $option): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (! $date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException("{$option} must be a valid date in YYYY-MM-DD format.");
        }

        return $date;
    }
}
