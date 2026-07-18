<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Services;

use App\Domain\Keirin\Scraping\Enums\BatchRunItemStatus;
use App\Domain\Keirin\Scraping\Enums\BatchRunStatus;
use App\Models\BatchRun;
use App\Models\BatchRunItem;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

class BatchRunService
{
    /**
     * @var array<string,bool>
     */
    private array $heldLocks = [];

    public function start(string $type, array $parameters = [], ?string $lockKey = null): BatchRun
    {
        $this->acquireLock($lockKey);

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

        return $run;
    }

    public function releaseLock(?string $lockKey): void
    {
        if ($lockKey === null || DB::getDriverName() !== 'pgsql' || ! isset($this->heldLocks[$lockKey])) {
            return;
        }

        DB::selectOne('SELECT pg_advisory_unlock(hashtext(?))', [$lockKey]);
        unset($this->heldLocks[$lockKey]);
    }

    public function startItem(BatchRun $run, string $itemType, string $itemKey, array $metadata = []): BatchRunItem
    {
        $item = BatchRunItem::query()->firstOrNew([
            'batch_run_id' => $run->id,
            'item_type' => $itemType,
            'item_key' => $itemKey,
        ]);

        $item->fill([
            'status' => BatchRunItemStatus::Running->value,
            'attempt_count' => ((int) $item->attempt_count) + 1,
            'started_at' => new DateTimeImmutable('now'),
            'finished_at' => null,
            'skip_reason' => null,
            'error_type' => null,
            'error_message' => null,
            'metadata' => $metadata,
        ])->save();

        return $item;
    }

    public function succeedItem(BatchRunItem $item, array $metadata = []): void
    {
        $this->finishItem($item, BatchRunItemStatus::Succeeded->value, metadata: $metadata);
    }

    public function failItem(BatchRunItem $item, string $errorType, string $errorMessage, array $metadata = []): void
    {
        $this->finishItem($item, BatchRunItemStatus::Failed->value, $errorType, $errorMessage, metadata: $metadata);
    }

    public function skipItem(BatchRunItem $item, string $reason, array $metadata = [], string $status = BatchRunItemStatus::Skipped->value): void
    {
        if (! in_array($status, [BatchRunItemStatus::Skipped->value, BatchRunItemStatus::SkippedUnsupportedCategory->value], true)) {
            throw new \InvalidArgumentException("Unsupported batch item skip status: {$status}");
        }

        $item->fill([
            'status' => $status,
            'skip_reason' => $reason,
            'finished_at' => new DateTimeImmutable('now'),
            'metadata' => $metadata ?: $item->metadata,
        ])->save();
    }

    public function skipUnsupportedCategoryItem(BatchRunItem $item, string $reason, array $metadata = []): void
    {
        $this->skipItem($item, $reason, $metadata, BatchRunItemStatus::SkippedUnsupportedCategory->value);
    }

    private function acquireLock(?string $lockKey): void
    {
        if ($lockKey === null || DB::getDriverName() !== 'pgsql') {
            return;
        }

        $locked = DB::selectOne('SELECT pg_try_advisory_lock(hashtext(?)) AS locked', [$lockKey]);
        if (! (bool) ($locked->locked ?? false)) {
            throw new \RuntimeException("Another batch is already running for {$lockKey}.");
        }

        $this->heldLocks[$lockKey] = true;
    }

    private function finishItem(BatchRunItem $item, string $status, ?string $errorType = null, ?string $errorMessage = null, array $metadata = []): void
    {
        $item->fill([
            'status' => $status,
            'finished_at' => new DateTimeImmutable('now'),
            'error_type' => $errorType,
            'error_message' => $errorMessage,
            'metadata' => $metadata ?: $item->metadata,
        ])->save();
    }
}
