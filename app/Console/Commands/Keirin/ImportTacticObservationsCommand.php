<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Scraping\Services\RaceTacticObservationService;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use Throwable;

class ImportTacticObservationsCommand extends Command
{
    protected $signature = 'keirin:tactics:import-observations {--race-id=} {--fetch-log-id=*} {--execute} {--dry-run}';

    protected $description = 'Append selected saved PJ0315 tactic/BHS captures without HTTP.';

    public function handle(RaceTacticObservationService $service): int
    {
        try {
            $race = $this->positive($this->option('race-id'));
            $logs = array_map($this->positive(...), $this->option('fetch-log-id'));
            if ($logs === [] || count($logs) > 100 || count(array_unique($logs)) !== count($logs)) {
                throw new InvalidArgumentException('Select 1..100 distinct fetch log IDs.');
            }
            if (! $this->option('execute')) {
                $this->line(json_encode(['mode' => 'PLAN', 'race_id' => $race, 'captures' => count($logs), 'writes' => 0,
                    'network' => false, 'prediction_use' => 'NOT_AUTHORIZED'], JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            }
            foreach ($logs as $log) {
                $this->line(json_encode($service->import($race, $log, (bool) $this->option('dry-run')), JSON_THROW_ON_ERROR));
            }

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error instanceof QueryException ? 'Tactic import DB operation failed; private SQL withheld.' : $error->getMessage());

            return self::FAILURE;
        }
    }

    private function positive(mixed $value): int
    {
        if (! is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1 || (float) $value > PHP_INT_MAX) {
            throw new InvalidArgumentException('Positive race/fetch IDs required.');
        }

        return (int) $value;
    }
}
