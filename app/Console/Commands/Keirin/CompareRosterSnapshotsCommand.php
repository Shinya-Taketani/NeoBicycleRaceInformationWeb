<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Scraping\Services\RaceRosterSnapshotService;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use Throwable;

class CompareRosterSnapshotsCommand extends Command
{
    protected $signature = 'keirin:statistics:compare-stat46-rosters {--race-id=} {--first-snapshot-id=} {--second-snapshot-id=} {--input-as-of=} {--execute}';

    protected $description = 'Read-only comparison of two explicit saved roster snapshots; never infers official change reasons.';

    public function handle(RaceRosterSnapshotService $service): int
    {
        try {
            $race = $this->positive($this->option('race-id'));
            $first = $this->positive($this->option('first-snapshot-id'));
            $second = $this->positive($this->option('second-snapshot-id'));
            $value = $this->option('input-as-of');
            $asOf = null;
            if ($value !== null) {
                $asOf = is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $value) === 1
                    ? DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $value) : false;
                if ($asOf === false || DateTimeImmutable::getLastErrors() !== false) {
                    throw new InvalidArgumentException('Offset-qualified valid input-as-of required.');
                }
            }
            $result = ! $this->option('execute') ? ['mode' => 'PLAN', 'writes' => 0, 'network' => false,
                'race_id' => $race, 'snapshot_ids' => [$first, $second], 'prediction_use' => 'NOT_AUTHORIZED']
                : $service->compare($race, $first, $second, $asOf);
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error instanceof QueryException ? 'Roster comparison DB operation failed; private SQL withheld.' : $error->getMessage());

            return self::FAILURE;
        }
    }

    private function positive(mixed $value): int
    {
        if (! is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1 || (float) $value > PHP_INT_MAX) {
            throw new InvalidArgumentException('Positive race/snapshot IDs required.');
        }

        return (int) $value;
    }
}
