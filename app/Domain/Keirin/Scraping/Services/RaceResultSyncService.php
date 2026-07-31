<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Services;

use App\Domain\Keirin\Scraping\DTO\ParsedRaceResultPageDto;
use App\Domain\Keirin\Scraping\DTO\RaceResultSyncAttemptDto;
use App\Domain\Keirin\Scraping\Enums\RaceCategory;
use App\Domain\Keirin\Scraping\Enums\RaceResultStatus;
use App\Domain\Keirin\Scraping\Enums\RaceResultSyncAttemptStatus;
use App\Domain\Keirin\Scraping\Exceptions\KeirinHttpException;
use App\Domain\Keirin\Scraping\Fetchers\RaceLiveFetcher;
use App\Domain\Keirin\Scraping\Parsers\RaceDetailParser;
use App\Domain\Keirin\Scraping\Parsers\RaceLiveResultParser;
use App\Domain\Keirin\Scraping\Support\RaceCategoryPolicy;
use App\Domain\Keirin\Scraping\Support\TransientFetchFailurePolicy;
use App\Models\BatchRun;
use App\Models\BatchRunItem;
use App\Models\Race;
use App\Models\RaceEntry;
use App\Repositories\RaceRepository;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class RaceResultSyncService
{
    private const RACE_CHUNK_SIZE = 100;

    public function __construct(
        private readonly BatchRunService $batchRuns,
        private readonly RaceLiveFetcher $fetcher,
        private readonly ScrapingFetchService $fetches,
        private readonly RaceDetailParser $detailParser,
        private readonly RaceLiveResultParser $resultParser,
        private readonly RaceRepository $races,
        private readonly RaceResultImportService $resultImports,
        private readonly RaceCategoryPolicy $categories,
        private readonly TransientFetchFailurePolicy $transientFailures,
    ) {}

    /** @return array{batch_run:BatchRun,success:int,skipped:int,failed:int,results:int,payouts:int} */
    public function sync(DateTimeImmutable $from, DateTimeImmutable $to, array $options = []): array
    {
        $options = $this->normalizedRetryOptions($options);
        $lockKey = 'keirin:races:sync-results:'.$from->format('Y-m-d').':'.$to->format('Y-m-d');
        $run = $this->batchRuns->start('race_result_sync', [
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            ...$options,
        ], $lockKey);

        return $this->executeRun(
            $run,
            $lockKey,
            $this->normalTargets($from, $to, $options),
            $options,
        );
    }

    /** @return array{batch_run:BatchRun,success:int,skipped:int,failed:int,results:int,payouts:int} */
    public function retryFailedBatch(int $sourceBatchRunId, array $options = []): array
    {
        $options = $this->normalizedRetryOptions($options);
        $targets = $this->failedBatchTargets($sourceBatchRunId, $options['limit'] ?? null);
        $lockKey = 'keirin:races:retry-results:'.$sourceBatchRunId;
        $run = $this->batchRuns->start('race_result_retry', [
            'source_batch_run_id' => $sourceBatchRunId,
            'limit' => $options['limit'] ?? null,
            'sleep_ms' => $options['sleep_ms'] ?? null,
            'transient_retry_passes' => $options['transient_retry_passes'],
            'transient_retry_sleep_ms' => $options['transient_retry_sleep_ms'],
        ], $lockKey);

        return $this->executeRun(
            $run,
            $lockKey,
            $this->failedBatchRaceTargets($targets),
            $options,
        );
    }

    /**
     * @param  iterable<array{race:Race,context:array<string,int>}>  $targets
     * @return array{batch_run:BatchRun,success:int,skipped:int,failed:int,results:int,payouts:int}
     */
    private function executeRun(BatchRun $run, string $lockKey, iterable $targets, array $options): array
    {
        $totals = [
            'success' => 0,
            'skipped' => 0,
            'failed' => 0,
            'results' => 0,
            'payouts' => 0,
            'last_error' => null,
        ];
        $outerException = null;
        $deferred = [];
        $retryPasses = $options['transient_retry_passes'];

        try {
            foreach ($targets as $target) {
                $attempt = $this->processRace($run, $target['race'], $target['context'], 0, $options);
                $this->applyAttempt(
                    $attempt,
                    (int) $target['race']->id,
                    $target['context'],
                    $retryPasses > 0,
                    $deferred,
                    $totals,
                );
            }

            for ($retryPass = 1; $retryPass <= $retryPasses && $deferred !== []; $retryPass++) {
                if ($options['transient_retry_sleep_ms'] > 0) {
                    usleep($options['transient_retry_sleep_ms'] * 1000);
                }
                $currentPass = array_values($deferred);
                $deferred = [];

                foreach ($currentPass as $target) {
                    $race = Race::query()
                        ->where('source', (string) config('keirin.source'))
                        ->find($target['race_id']);
                    $attempt = $race instanceof Race
                        ? $this->processRace($run, $race, $target['context'], $retryPass, $options)
                        : $this->failMissingDeferredRace($run, $target['race_id'], $target['context'], $retryPass);
                    $this->applyAttempt(
                        $attempt,
                        $target['race_id'],
                        $target['context'],
                        $retryPass < $retryPasses,
                        $deferred,
                        $totals,
                    );
                }
            }
        } catch (Throwable $throwable) {
            $totals['failed']++;
            $totals['last_error'] = $throwable->getMessage();
            $outerException = $throwable;
        } finally {
            try {
                $run = $this->batchRuns->finish(
                    $run,
                    $totals['success'],
                    $totals['skipped'],
                    $totals['failed'],
                    $totals['last_error'],
                );
            } finally {
                $this->batchRuns->releaseLock($lockKey);
            }
        }

        if ($outerException instanceof Throwable) {
            throw $outerException;
        }

        return [
            'batch_run' => $run,
            'success' => $totals['success'],
            'skipped' => $totals['skipped'],
            'failed' => $totals['failed'],
            'results' => $totals['results'],
            'payouts' => $totals['payouts'],
        ];
    }

    /**
     * @return \Generator<int,array{race:Race,context:array<string,int>}>
     */
    private function normalTargets(DateTimeImmutable $from, DateTimeImmutable $to, array $options): \Generator
    {
        foreach ($this->racesForSync($from, $to, $options) as $race) {
            yield ['race' => $race, 'context' => []];
        }
    }

    /**
     * @param  list<array{race_id:int,source_batch_run_id:int,source_batch_run_item_id:int}>  $targets
     * @return \Generator<int,array{race:Race,context:array<string,int>}>
     */
    private function failedBatchRaceTargets(array $targets): \Generator
    {
        foreach ($targets as $target) {
            $race = Race::query()
                ->where('source', (string) config('keirin.source'))
                ->find($target['race_id']);
            if (! $race instanceof Race) {
                throw new InvalidArgumentException("Race {$target['race_id']} was not available for failed Batch retry.");
            }

            yield [
                'race' => $race,
                'context' => [
                    'source_batch_run_id' => $target['source_batch_run_id'],
                    'source_batch_run_item_id' => $target['source_batch_run_item_id'],
                ],
            ];
        }
    }

    private function processRace(
        BatchRun $run,
        Race $race,
        array $context,
        int $retryPass,
        array $options,
    ): RaceResultSyncAttemptDto {
        [$item, $metadata] = $this->startRaceItem($run, $race, $context, $retryPass);
        $phase = null;

        try {
            $category = $this->categories->classify($race->race_type);
            if ($category !== RaceCategory::Men) {
                $this->batchRuns->skipUnsupportedCategoryItem($item, 'UNSUPPORTED_RACE_CATEGORY', [
                    ...$metadata,
                    'race_type' => $race->race_type,
                    'category' => $category->value,
                ]);

                return new RaceResultSyncAttemptDto(RaceResultSyncAttemptStatus::Skipped);
            }

            if (! is_string($race->encrypted_parameter) || $race->encrypted_parameter === '') {
                throw new RuntimeException("Race {$race->external_race_id} has no encrypted parameter.");
            }

            $phase = 'PJ0315';
            $detailRaw = $this->fetches->fetch(
                fn () => $this->fetcher->fetchDetail($race->encrypted_parameter, $options['sleep_ms'] ?? null),
                (int) $run->id,
            );
            $detail = $this->detailParser->parse($detailRaw->utf8Body);
            $this->races->updateRaceDetail($race, $detail, new DateTimeImmutable('now'));

            $phase = 'PJ0326';
            $resultRaw = $this->fetches->fetch(
                fn () => $this->fetcher->fetchResult($race->encrypted_parameter, $options['sleep_ms'] ?? null),
                (int) $run->id,
            );
            $resultPage = $this->resultParser->parse($resultRaw->utf8Body);
            $this->assertResultContext($race, $resultPage->raceDate, $resultPage->trackCode, $resultPage->raceNumber);
            if (! $resultPage->detectedStatus instanceof RaceResultStatus) {
                $this->batchRuns->skipItem($item, 'RESULT_STATUS_UNDETERMINED', [
                    ...$metadata,
                    'evidence' => $resultPage->statusEvidence,
                    'raw_file_path' => $resultRaw->rawFilePath,
                ]);

                return new RaceResultSyncAttemptDto(RaceResultSyncAttemptStatus::Skipped);
            }
            $this->assertResultPlayers($race, $resultPage->resultPage);
            $imported = $this->resultImports->importStoredResponse(
                $race,
                $run,
                $item,
                $resultRaw,
                $resultPage->resultPage,
                rtrim((string) config('keirin.base_url'), '/').(string) config('keirin.routes.race_live'),
                $resultPage->detectedStatus,
            );
            if ($imported['status'] === 'SKIPPED') {
                $this->batchRuns->skipItem($item, 'RESULT_NOT_AVAILABLE', [
                    ...$metadata,
                    'import_id' => $imported['import']->id,
                ]);

                return new RaceResultSyncAttemptDto(
                    RaceResultSyncAttemptStatus::Skipped,
                    $imported['results'],
                    $imported['payouts'],
                );
            }

            $this->batchRuns->succeedItem($item, [
                ...$metadata,
                'import_id' => $imported['import']->id,
                'results' => $imported['results'],
                'payouts' => $imported['payouts'],
            ]);

            return new RaceResultSyncAttemptDto(
                RaceResultSyncAttemptStatus::Succeeded,
                $imported['results'],
                $imported['payouts'],
            );
        } catch (KeirinHttpException $exception) {
            return $this->failFetchAttempt($item, $metadata, $phase, $retryPass, $exception);
        } catch (Throwable $throwable) {
            $this->batchRuns->failItem($item, $throwable::class, $throwable->getMessage(), [
                ...$metadata,
                'phase' => $phase,
                'retry_pass' => $retryPass,
            ]);

            return new RaceResultSyncAttemptDto(
                RaceResultSyncAttemptStatus::FailedPermanent,
                errorMessage: $throwable->getMessage(),
            );
        }
    }

    private function failFetchAttempt(
        BatchRunItem $item,
        array $metadata,
        ?string $phase,
        int $retryPass,
        KeirinHttpException $exception,
    ): RaceResultSyncAttemptDto {
        $retryable = $this->transientFailures->isRetryable($exception);
        $failure = [
            'retry_pass' => $retryPass,
            'phase' => $phase,
            'fetch_error_type' => $exception->errorType->value,
            'request_url' => $exception->url,
            'http_status' => $exception->httpStatus,
            'http_retry_count' => $exception->response?->retryCount,
            'error_message' => $exception->getMessage(),
            'failed_at' => (new DateTimeImmutable('now'))->format(DATE_ATOM),
        ];
        $transientFailures = $metadata['transient_failures'] ?? [];
        if ($retryable) {
            $transientFailures[] = $failure;
        }
        $failureMetadata = [
            ...$metadata,
            'phase' => $phase,
            'fetch_error_type' => $exception->errorType->value,
            'request_url' => $exception->url,
            'http_status' => $exception->httpStatus,
            'http_retry_count' => $exception->response?->retryCount,
            'retryable_transport' => $retryable,
            'retry_pass' => $retryPass,
        ];
        if ($transientFailures !== []) {
            $failureMetadata['transient_failures'] = $transientFailures;
        }
        $this->batchRuns->failItem(
            $item,
            $exception::class,
            $exception->getMessage(),
            $failureMetadata,
        );

        return new RaceResultSyncAttemptDto(
            $retryable
                ? RaceResultSyncAttemptStatus::FailedTransient
                : RaceResultSyncAttemptStatus::FailedPermanent,
            errorMessage: $exception->getMessage(),
        );
    }

    private function failMissingDeferredRace(
        BatchRun $run,
        int $raceId,
        array $context,
        int $retryPass,
    ): RaceResultSyncAttemptDto {
        [$item, $metadata] = $this->startRaceItem($run, null, $context, $retryPass, $raceId);
        $message = "Deferred retry race {$raceId} no longer exists for source ".config('keirin.source').'.';
        $this->batchRuns->failItem($item, RuntimeException::class, $message, [
            ...$metadata,
            'retry_pass' => $retryPass,
        ]);

        return new RaceResultSyncAttemptDto(
            RaceResultSyncAttemptStatus::FailedPermanent,
            errorMessage: $message,
        );
    }

    /**
     * @return array{0:BatchRunItem,1:array<string,mixed>}
     */
    private function startRaceItem(
        BatchRun $run,
        ?Race $race,
        array $context,
        int $retryPass,
        ?int $raceId = null,
    ): array {
        $raceId ??= (int) $race?->id;
        $previous = BatchRunItem::query()
            ->where('batch_run_id', $run->id)
            ->where('item_type', 'RACE_RESULT')
            ->where('item_key', 'race:'.$raceId)
            ->first();
        $previousMetadata = $previous?->metadata ?? [];
        $metadata = [
            'race_id' => $raceId,
            'external_race_id' => $race?->external_race_id ?? ($previousMetadata['external_race_id'] ?? null),
            'race_date' => $race?->race_date?->format('Y-m-d') ?? ($previousMetadata['race_date'] ?? null),
            'race_number' => $race?->race_number !== null
                ? (int) $race->race_number
                : ($previousMetadata['race_number'] ?? null),
            ...$context,
            'retry_pass' => $retryPass,
        ];
        if (($previousMetadata['transient_failures'] ?? []) !== []) {
            $metadata['transient_failures'] = $previousMetadata['transient_failures'];
        }

        return [
            $this->batchRuns->startItem($run, 'RACE_RESULT', 'race:'.$raceId, $metadata),
            $metadata,
        ];
    }

    /**
     * @param  array<int,array{race_id:int,context:array<string,int>}>  $deferred
     * @param  array{success:int,skipped:int,failed:int,results:int,payouts:int,last_error:?string}  $totals
     */
    private function applyAttempt(
        RaceResultSyncAttemptDto $attempt,
        int $raceId,
        array $context,
        bool $canRetry,
        array &$deferred,
        array &$totals,
    ): void {
        if ($attempt->status === RaceResultSyncAttemptStatus::Succeeded) {
            $totals['success']++;
            $totals['results'] += $attempt->results;
            $totals['payouts'] += $attempt->payouts;

            return;
        }
        if ($attempt->status === RaceResultSyncAttemptStatus::Skipped) {
            $totals['skipped']++;
            $totals['results'] += $attempt->results;
            $totals['payouts'] += $attempt->payouts;

            return;
        }
        if ($attempt->status === RaceResultSyncAttemptStatus::FailedTransient && $canRetry) {
            $deferred[$raceId] = ['race_id' => $raceId, 'context' => $context];

            return;
        }

        $totals['failed']++;
        $totals['last_error'] = $attempt->errorMessage;
    }

    /**
     * @return list<array{race_id:int,source_batch_run_id:int,source_batch_run_item_id:int}>
     */
    private function failedBatchTargets(int $sourceBatchRunId, ?int $limit): array
    {
        $sourceRun = BatchRun::query()->find($sourceBatchRunId);
        if (! $sourceRun instanceof BatchRun) {
            throw new InvalidArgumentException("Source Batch Run {$sourceBatchRunId} was not found.");
        }
        if ($sourceRun->source !== (string) config('keirin.source')) {
            throw new InvalidArgumentException("Source Batch Run {$sourceBatchRunId} belonged to another source.");
        }
        if (! in_array($sourceRun->type, ['race_result_sync', 'race_result_retry'], true)) {
            throw new InvalidArgumentException("Source Batch Run {$sourceBatchRunId} was not a result synchronization Batch.");
        }
        if ($sourceRun->status === 'RUNNING' || $sourceRun->finished_at === null) {
            throw new InvalidArgumentException("Source Batch Run {$sourceBatchRunId} was not finished.");
        }

        $targets = [];
        $seenRaceIds = [];
        $items = BatchRunItem::query()
            ->where('batch_run_id', $sourceBatchRunId)
            ->where('item_type', 'RACE_RESULT')
            ->where('status', 'FAILED')
            ->orderBy('id')
            ->get();
        foreach ($items as $item) {
            if (preg_match('/^race:([1-9]\d*)$/', $item->item_key, $matches) !== 1) {
                throw new InvalidArgumentException("Source Batch Run Item {$item->id} had an invalid race item key.");
            }
            $raceId = (int) $matches[1];
            $race = Race::query()->find($raceId);
            if (! $race instanceof Race) {
                throw new InvalidArgumentException("Source Batch Run Item {$item->id} referenced missing race {$raceId}.");
            }
            if ($race->source !== (string) config('keirin.source')) {
                throw new InvalidArgumentException("Source Batch Run Item {$item->id} referenced another source.");
            }
            if (isset($seenRaceIds[$raceId])) {
                continue;
            }
            $seenRaceIds[$raceId] = true;
            $targets[] = [
                'race_id' => $raceId,
                'source_batch_run_id' => $sourceBatchRunId,
                'source_batch_run_item_id' => (int) $item->id,
            ];
        }

        return $limit === null ? $targets : array_slice($targets, 0, $limit);
    }

    private function normalizedRetryOptions(array $options): array
    {
        $passes = $options['transient_retry_passes']
            ?? config('keirin.result_transient_retry_passes', 1);
        $sleepMs = $options['transient_retry_sleep_ms']
            ?? config('keirin.result_transient_retry_sleep_ms', 5000);
        if (! is_int($passes) || $passes < 0) {
            throw new InvalidArgumentException('transient_retry_passes must be a non-negative integer.');
        }
        if (! is_int($sleepMs) || $sleepMs < 0) {
            throw new InvalidArgumentException('transient_retry_sleep_ms must be a non-negative integer.');
        }

        return [
            ...$options,
            'transient_retry_passes' => $passes,
            'transient_retry_sleep_ms' => $sleepMs,
        ];
    }

    /** @return \Generator<int, Race> */
    private function racesForSync(DateTimeImmutable $from, DateTimeImmutable $to, array $options): \Generator
    {
        $remaining = isset($options['limit']) ? max(0, (int) $options['limit']) : null;
        $lastRaceDate = null;
        $lastRaceNumber = null;
        $lastId = null;

        while ($remaining === null || $remaining > 0) {
            $pageSize = $remaining === null
                ? self::RACE_CHUNK_SIZE
                : min(self::RACE_CHUNK_SIZE, $remaining);
            $races = $this->raceQuery($from, $to, $options)
                ->when($lastRaceDate !== null, function (Builder $query) use ($lastRaceDate, $lastRaceNumber, $lastId): void {
                    $query->where(function (Builder $cursor) use ($lastRaceDate, $lastRaceNumber, $lastId): void {
                        $cursor->whereDate('races.race_date', '>', $lastRaceDate)
                            ->orWhere(function (Builder $sameDate) use ($lastRaceDate, $lastRaceNumber): void {
                                $sameDate->whereDate('races.race_date', $lastRaceDate)
                                    ->where('races.race_number', '>', $lastRaceNumber);
                            })
                            ->orWhere(function (Builder $sameRace) use ($lastRaceDate, $lastRaceNumber, $lastId): void {
                                $sameRace->whereDate('races.race_date', $lastRaceDate)
                                    ->where('races.race_number', $lastRaceNumber)
                                    ->where('races.id', '>', $lastId);
                            });
                    });
                })
                ->orderBy('races.race_date')
                ->orderBy('races.race_number')
                ->orderBy('races.id')
                ->limit($pageSize)
                ->get();

            if ($races->isEmpty()) {
                return;
            }

            foreach ($races as $race) {
                yield $race;
            }

            $lastRace = $races->last();
            $lastRaceDate = $lastRace->race_date->format('Y-m-d');
            $lastRaceNumber = (int) $lastRace->race_number;
            $lastId = (int) $lastRace->id;
            $fetchedCount = $races->count();
            unset($races);

            if ($remaining !== null) {
                $remaining -= $fetchedCount;
                if ($remaining <= 0) {
                    return;
                }
            }
            if ($fetchedCount < $pageSize) {
                return;
            }
        }
    }

    private function raceQuery(DateTimeImmutable $from, DateTimeImmutable $to, array $options): Builder
    {
        return Race::query()
            ->select('races.*')
            ->leftJoin('racetracks', 'racetracks.id', '=', 'races.racetrack_id')
            ->whereDate('races.race_date', '>=', $from->format('Y-m-d'))
            ->whereDate('races.race_date', '<=', $to->format('Y-m-d'))
            ->when(! ($options['force'] ?? false), fn (Builder $query): Builder => $query->where('races.result_available', true))
            ->when(isset($options['race_id']), fn (Builder $query): Builder => $query->where('races.id', $options['race_id']))
            ->when(isset($options['track_code']), fn (Builder $query): Builder => $query->where('racetracks.external_track_id', $options['track_code']))
            ->when(isset($options['race_number']), fn (Builder $query): Builder => $query->where('races.race_number', $options['race_number']));
    }

    private function assertResultContext(Race $race, string $date, string $trackCode, int $raceNumber): void
    {
        $expected = sprintf('%s:%s:%02d', $trackCode, $date, $raceNumber);
        if ($race->external_race_id !== $expected) {
            throw new RuntimeException("PJ0326 context {$expected} did not match race {$race->external_race_id}.");
        }
    }

    private function assertResultPlayers(Race $race, ParsedRaceResultPageDto $page): void
    {
        $entries = RaceEntry::query()->where('race_id', $race->id)->get()->keyBy('bike_number');
        foreach ($page->results as $result) {
            $entry = $entries->get($result->bikeNumber);
            if (! $entry instanceof RaceEntry || $result->externalPlayerId === null || $entry->external_player_id !== $result->externalPlayerId) {
                throw new RuntimeException("PJ0326 player did not match bike {$result->bikeNumber} for race {$race->external_race_id}.");
            }
        }
    }
}
