<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Statistics\Services\OfficialMethodHistoryBuildService;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use Throwable;

class BuildOfficialMethodHistoryCommand extends Command
{
    protected $signature = 'keirin:statistics:build-official-method-history {--race-id=} {--input-as-of=} {--execute} {--dry-run}';

    protected $description = 'Build retrospective official top2 method profiles for STAT-19/30, without prediction authorization.';

    public function handle(OfficialMethodHistoryBuildService $service): int
    {
        try {
            $id = $this->option('race-id');
            $stamp = $this->option('input-as-of');
            if (! is_string($id) || preg_match('/^[1-9]\d*$/D', $id) !== 1 || (float) $id > PHP_INT_MAX
                || ! is_string($stamp) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $stamp) !== 1) {
                throw new InvalidArgumentException('Explicit race ID and ISO input-as-of with offset required.');
            }
            $asOf = new DateTimeImmutable($stamp);
            if (DateTimeImmutable::getLastErrors() !== false) {
                throw new InvalidArgumentException('Invalid input-as-of date.');
            }
            $result = $this->option('execute') ? $service->build((int) $id, $asOf, (bool) $this->option('dry-run'))
                : ['mode' => 'PLAN', 'race_id' => (int) $id, 'writes' => 0, 'prediction_use' => 'NOT_AUTHORIZED'];
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error instanceof QueryException ? 'Official method history DB operation failed; SQL withheld.' : $error->getMessage());

            return self::FAILURE;
        }
    }
}
