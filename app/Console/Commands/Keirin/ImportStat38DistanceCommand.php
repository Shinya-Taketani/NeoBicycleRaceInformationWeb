<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Scraping\Services\RaceDistanceObservationService;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Throwable;

class ImportStat38DistanceCommand extends Command
{
    protected $signature = 'keirin:stat38:import-distance {--race-id=} {--fetch-log-id=*} {--execute} {--dry-run}';

    protected $description = 'Append planned distance/laps from explicitly selected saved PJ0315 captures; no HTTP.';

    public function handle(RaceDistanceObservationService $service): int
    {
        try {
            $race = $this->positive($this->option('race-id'));
            $logs = array_map($this->positive(...), $this->option('fetch-log-id'));
            if ($logs === [] || count($logs) > 100 || count(array_unique($logs)) !== count($logs)) {
                throw new \InvalidArgumentException('Select 1..100 distinct fetch log IDs.');
            }
            if (! $this->option('execute')) {
                $this->line(json_encode(['mode' => 'PLAN', 'race_id' => $race, 'captures' => count($logs),
                    'network' => false, 'writes' => 0, 'prediction_use' => 'NOT_AUTHORIZED'], JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            }
            foreach ($logs as $log) {
                $this->line(json_encode($service->import($race, $log, (bool) $this->option('dry-run')), JSON_THROW_ON_ERROR));
            }
        } catch (Throwable $error) {
            $this->error($error instanceof QueryException ? 'Distance import DB operation failed; private SQL withheld.' : $error->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function positive(mixed $value): int
    {
        if (! is_string($value) || preg_match('/^[1-9]\d*$/D', $value) !== 1 || (float) $value > PHP_INT_MAX) {
            throw new \InvalidArgumentException('Positive race/fetch IDs required.');
        }

        return (int) $value;
    }
}
