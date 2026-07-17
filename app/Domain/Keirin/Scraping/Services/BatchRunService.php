<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Services;

use App\Domain\Keirin\Scraping\Enums\BatchRunStatus;
use App\Models\BatchRun;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

class BatchRunService
{
    public function start(string $type, array $parameters = [], ?string $lockKey = null): BatchRun
    {
        if ($lockKey !== null && DB::getDriverName() === 'pgsql') {
            $locked = DB::selectOne('SELECT pg_try_advisory_lock(hashtext(?)) AS locked', [$lockKey]);
            if (! (bool) ($locked->locked ?? false)) {
                throw new \RuntimeException("Another batch is already running for {$lockKey}.");
            }
        }

        return BatchRun::query()->create([
            'type' => $type,
            'source' => (string) config('keirin.source'),
            'status' => BatchRunStatus::Running->value,
            'lock_key' => $lockKey,
            'parameters' => $parameters,
            'started_at' => new DateTimeImmutable('now'),
        ]);
    }

    public function finish(BatchRun $run, int $success, int $skipped, int $failure, ?string $errorMessage = null): BatchRun
    {
        $run->forceFill([
            'status' => $failure === 0 ? BatchRunStatus::Succeeded->value : ($success > 0 || $skipped > 0 ? BatchRunStatus::PartiallyFailed->value : BatchRunStatus::Failed->value),
            'finished_at' => new DateTimeImmutable('now'),
            'success_count' => $success,
            'skipped_count' => $skipped,
            'failure_count' => $failure,
            'error_message' => $errorMessage,
        ])->save();

        if ($run->lock_key !== null && DB::getDriverName() === 'pgsql') {
            DB::selectOne('SELECT pg_advisory_unlock(hashtext(?))', [$run->lock_key]);
        }

        return $run;
    }
}
