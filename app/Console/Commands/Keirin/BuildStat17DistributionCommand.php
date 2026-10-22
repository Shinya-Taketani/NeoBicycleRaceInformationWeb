<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Statistics\Services\Stat17DistributionBuildService;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use Throwable;

class BuildStat17DistributionCommand extends Command
{
    protected $signature = 'keirin:statistics:build-stat17-profile {--race-id=} {--fetch-log-id=} {--input-as-of=} {--execute} {--dry-run}';

    protected $description = 'Describe a selected stored PJ0315 four-count distribution; never prediction-authorized.';

    public function handle(Stat17DistributionBuildService $service): int
    {
        try {
            $race = $this->option('race-id');
            $log = $this->option('fetch-log-id');
            foreach ([$race, $log] as $id) {
                if (! is_string($id) || preg_match('/^[1-9][0-9]*$/D', $id) !== 1 || (float) $id > PHP_INT_MAX) {
                    throw new InvalidArgumentException('Explicit positive race/fetch IDs required.');
                }
            }
            $stamp = $this->option('input-as-of');
            if (! is_string($stamp) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $stamp) !== 1) {
                throw new InvalidArgumentException('Explicit ISO input-as-of with offset required.');
            }
            $asOf = new DateTimeImmutable($stamp);
            if (DateTimeImmutable::getLastErrors() !== false) {
                throw new InvalidArgumentException('Invalid input-as-of date.');
            }
            $out = $this->option('execute') ? $service->build((int) $race, (int) $log, $asOf, (bool) $this->option('dry-run'))
                : ['mode' => 'PLAN', 'race_id' => (int) $race, 'fetch_log_id' => (int) $log, 'writes' => 0, 'prediction_use' => 'NOT_AUTHORIZED'];
            $this->line(json_encode($out, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error instanceof QueryException ? 'STAT-17 profile DB operation failed; private SQL withheld.' : $error->getMessage());

            return self::FAILURE;
        }
    }
}
