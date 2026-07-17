<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Services;

use App\Domain\Keirin\Scraping\DTO\ParsedRaceResultPageDto;
use App\Domain\Keirin\Scraping\Enums\ParsedRaceResultPageStatus;
use App\Domain\Keirin\Scraping\Parsers\RaceResultPageParser;
use App\Models\BatchRun;
use App\Models\BatchRunItem;
use App\Models\Race;
use App\Models\RacePayout;
use App\Models\RaceResult;
use App\Models\RaceResultImport;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class RaceResultImportService
{
    public function __construct(
        private readonly BatchRunService $batchRuns,
        private readonly RawResponseStorageService $rawStorage,
        private readonly RaceResultPageParser $parser,
    ) {}

    /**
     * @return array{results:int,payouts:int,race:?Race,import:RaceResultImport,status:string}
     */
    public function importRawFile(?int $raceId, ?string $externalRaceId, string $rawFile, string $sourceUrl, string $requestedResultStatus): array
    {
        $raceKey = $raceId !== null ? 'id-'.$raceId : 'external-'.$externalRaceId;
        $sourceHash = hash_file('sha256', $rawFile);
        if (! is_string($sourceHash)) {
            throw new \RuntimeException("Failed to hash raw result file: {$rawFile}");
        }

        $lockKey = 'keirin:race-result-import:'.$raceKey;
        $run = $this->batchRuns->start('race_result_import', [
            'race_id' => $raceId,
            'external_race_id' => $externalRaceId,
            'source_url' => $sourceUrl,
            'requested_result_status' => $requestedResultStatus,
        ], $lockKey);
        $item = $this->batchRuns->startItem($run, 'RACE_RESULT', 'race-result:'.$raceKey.':'.$sourceHash);
        $import = null;
        $race = null;

        try {
            $storedRaw = $this->rawStorage->storeImportedRawFile($rawFile, (string) $raceKey);
            $race = $this->findRace($raceId, $externalRaceId);
            $import = $this->createImport($race, $run, $item, $sourceUrl, $storedRaw['sha256'], $storedRaw['path'], $requestedResultStatus);

            if (! $race instanceof Race) {
                throw new \RuntimeException('Target race was not found.');
            }

            $page = $this->parser->parse($storedRaw['body']);
            $this->validateRequestedStatus($page, $requestedResultStatus);

            $result = match ($page->pageStatus) {
                ParsedRaceResultPageStatus::ResultsAvailable => $this->syncAvailableResults($race, $import, $page, $sourceUrl, $requestedResultStatus),
                ParsedRaceResultPageStatus::Unavailable,
                ParsedRaceResultPageStatus::UnderReview => $this->skipUnavailableResult($race, $import, $page),
                ParsedRaceResultPageStatus::Cancelled => $this->syncCancelled($race, $import, $page, $requestedResultStatus),
            };

            $this->batchRuns->succeedItem($item, ['import_id' => $import->id, 'status' => $result['status']]);
            $this->batchRuns->finish($run, 1, $result['status'] === 'SKIPPED' ? 1 : 0, 0);

            return ['results' => $result['results'], 'payouts' => $result['payouts'], 'race' => $race, 'import' => $import->refresh(), 'status' => $result['status']];
        } catch (\Throwable $throwable) {
            if ($import instanceof RaceResultImport) {
                $this->failImport($import, $throwable);
            } elseif (isset($storedRaw)) {
                $import = $this->createImport($race, $run, $item, $sourceUrl, $storedRaw['sha256'], $storedRaw['path'], $requestedResultStatus);
                $this->failImport($import, $throwable);
            }

            $this->batchRuns->failItem($item, $throwable::class, $throwable->getMessage(), ['import_id' => $import?->id]);
            $this->batchRuns->finish($run, 0, 0, 1, $throwable->getMessage());

            throw $throwable;
        } finally {
            $this->batchRuns->releaseLock($lockKey);
        }
    }

    private function findRace(?int $raceId, ?string $externalRaceId): ?Race
    {
        return Race::query()
            ->when($raceId !== null, fn (Builder $query): Builder => $query->whereKey($raceId))
            ->when($raceId === null && $externalRaceId !== null, fn (Builder $query): Builder => $query->where('external_race_id', $externalRaceId))
            ->first();
    }

    private function createImport(?Race $race, BatchRun $run, BatchRunItem $item, string $sourceUrl, string $sourceHash, string $rawFilePath, string $requestedResultStatus): RaceResultImport
    {
        return RaceResultImport::query()->create([
            'race_id' => $race?->id,
            'batch_run_id' => $run->id,
            'batch_run_item_id' => $item->id,
            'source_url' => $sourceUrl,
            'source_hash' => $sourceHash,
            'raw_file_path' => $rawFilePath,
            'parser_version' => (string) config('keirin.parser_version'),
            'requested_result_status' => $requestedResultStatus,
            'import_status' => 'RUNNING',
            'imported_at' => new DateTimeImmutable('now'),
        ]);
    }

    private function validateRequestedStatus(ParsedRaceResultPageDto $page, string $requestedResultStatus): void
    {
        if ($page->pageStatus === ParsedRaceResultPageStatus::ResultsAvailable && ! in_array($requestedResultStatus, ['CONFIRMED', 'CORRECTED', 'PROVISIONAL', 'UNDER_REVIEW'], true)) {
            throw new \RuntimeException('Result rows are available, but requested result-status does not permit result sync.');
        }

        if ($page->pageStatus === ParsedRaceResultPageStatus::Cancelled && $requestedResultStatus !== 'CANCELLED') {
            throw new \RuntimeException('Cancelled result page requires --result-status=CANCELLED.');
        }
    }

    /**
     * @return array{results:int,payouts:int,status:string}
     */
    private function syncAvailableResults(Race $race, RaceResultImport $import, ParsedRaceResultPageDto $page, string $sourceUrl, string $requestedResultStatus): array
    {
        DB::transaction(function () use ($race, $import, $page, $sourceUrl, $requestedResultStatus): void {
            $lockedRace = Race::query()->whereKey($race->id)->lockForUpdate()->firstOrFail();
            $fetchedAt = new DateTimeImmutable('now');

            $seenResultBikeNumbers = [];
            foreach ($page->results as $result) {
                if ($result->bikeNumber === null) {
                    throw new \RuntimeException('Parsed race result row was missing bike number.');
                }

                $seenResultBikeNumbers[] = $result->bikeNumber;
                RaceResult::query()->updateOrCreate(
                    [
                        'race_id' => $lockedRace->id,
                        'bike_number' => $result->bikeNumber,
                    ],
                    [
                        'race_result_import_id' => $import->id,
                        'rank' => $result->rank,
                        'result_status' => $result->status->value,
                        'winning_technique' => $result->winningTechnique,
                        'raw_result_text' => $result->rawText,
                        'source_url' => $sourceUrl,
                        'fetched_at' => $fetchedAt,
                    ],
                );
            }

            RaceResult::query()
                ->where('race_id', $lockedRace->id)
                ->whereNotIn('bike_number', $seenResultBikeNumbers)
                ->delete();

            $seenPayoutKeys = [];
            foreach ($page->payouts as $payout) {
                $key = $payout->betTypeCode.'|'.$payout->combination.'|'.$payout->sequence;
                $seenPayoutKeys[] = $key;
                RacePayout::query()->updateOrCreate(
                    [
                        'race_id' => $lockedRace->id,
                        'bet_type_code' => $payout->betTypeCode,
                        'combination' => $payout->combination,
                        'sequence' => $payout->sequence,
                    ],
                    [
                        'race_result_import_id' => $import->id,
                        'payout_amount' => $payout->payoutAmount,
                        'popularity' => $payout->popularity,
                        'source_url' => $sourceUrl,
                        'fetched_at' => $fetchedAt,
                    ],
                );
            }

            RacePayout::query()
                ->where('race_id', $lockedRace->id)
                ->get()
                ->each(function (RacePayout $payout) use ($seenPayoutKeys): void {
                    $key = $payout->bet_type_code.'|'.$payout->combination.'|'.$payout->sequence;
                    if (! in_array($key, $seenPayoutKeys, true)) {
                        $payout->delete();
                    }
                });

            $lockedRace->forceFill([
                'result_status' => $requestedResultStatus,
                'result_url' => $sourceUrl,
                'result_confirmed_at' => in_array($requestedResultStatus, ['CONFIRMED', 'CORRECTED'], true) ? $fetchedAt : $lockedRace->result_confirmed_at,
                'last_fetched_at' => $fetchedAt,
            ])->save();
        });

        $this->succeedImport($import, $page, count($page->results), count($page->payouts));

        return ['results' => count($page->results), 'payouts' => count($page->payouts), 'status' => 'SUCCEEDED'];
    }

    /**
     * @return array{results:int,payouts:int,status:string}
     */
    private function skipUnavailableResult(Race $race, RaceResultImport $import, ParsedRaceResultPageDto $page): array
    {
        if (! in_array($race->result_status, ['CONFIRMED', 'CORRECTED'], true)) {
            $race->forceFill([
                'result_status' => $page->pageStatus->value,
                'last_fetched_at' => new DateTimeImmutable('now'),
            ])->save();
        }

        $import->forceFill([
            'parsed_page_status' => $page->pageStatus->value,
            'import_status' => 'SKIPPED',
            'result_count' => 0,
            'payout_count' => 0,
        ])->save();

        return ['results' => 0, 'payouts' => 0, 'status' => 'SKIPPED'];
    }

    /**
     * @return array{results:int,payouts:int,status:string}
     */
    private function syncCancelled(Race $race, RaceResultImport $import, ParsedRaceResultPageDto $page, string $requestedResultStatus): array
    {
        if ($requestedResultStatus !== 'CANCELLED') {
            throw new \RuntimeException('Cancelled page cannot be imported without --result-status=CANCELLED.');
        }

        DB::transaction(function () use ($race): void {
            $lockedRace = Race::query()->whereKey($race->id)->lockForUpdate()->firstOrFail();
            RaceResult::query()->where('race_id', $lockedRace->id)->delete();
            RacePayout::query()->where('race_id', $lockedRace->id)->delete();
            $lockedRace->forceFill([
                'result_status' => 'CANCELLED',
                'last_fetched_at' => new DateTimeImmutable('now'),
            ])->save();
        });

        $this->succeedImport($import, $page, 0, 0);

        return ['results' => 0, 'payouts' => 0, 'status' => 'SUCCEEDED'];
    }

    private function succeedImport(RaceResultImport $import, ParsedRaceResultPageDto $page, int $resultCount, int $payoutCount): void
    {
        $import->forceFill([
            'parsed_page_status' => $page->pageStatus->value,
            'import_status' => 'SUCCEEDED',
            'result_count' => $resultCount,
            'payout_count' => $payoutCount,
        ])->save();
    }

    private function failImport(RaceResultImport $import, \Throwable $throwable): void
    {
        $import->forceFill([
            'import_status' => 'FAILED',
            'error_type' => $throwable::class,
            'error_message' => $throwable->getMessage(),
        ])->save();
    }
}
