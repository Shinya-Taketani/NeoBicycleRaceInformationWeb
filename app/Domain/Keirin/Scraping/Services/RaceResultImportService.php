<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Services;

use App\Domain\Keirin\Scraping\DTO\ParsedRaceResultPageDto;
use App\Domain\Keirin\Scraping\DTO\StoredImportedRawFileDto;
use App\Domain\Keirin\Scraping\Enums\FetchErrorType;
use App\Domain\Keirin\Scraping\Enums\ParsedRaceResultPageStatus;
use App\Domain\Keirin\Scraping\Enums\RaceResultStatus;
use App\Domain\Keirin\Scraping\Exceptions\CharacterEncodingConversionException;
use App\Domain\Keirin\Scraping\Exceptions\InvalidRaceResultStatusTransitionException;
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
use RuntimeException;
use Throwable;

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
    public function importRawFile(?int $raceId, ?string $externalRaceId, string $rawFile, string $sourceUrl, RaceResultStatus $requestedResultStatus): array
    {
        $raceKey = $raceId !== null ? 'id-'.$raceId : 'external-'.$externalRaceId;
        $sourceHash = hash_file('sha256', $rawFile);
        if (! is_string($sourceHash)) {
            throw new RuntimeException("Failed to hash raw result file: {$rawFile}");
        }

        $lockKey = 'keirin:race-result-import:'.$raceKey;
        $run = $this->batchRuns->start('race_result_import', [
            'race_id' => $raceId,
            'external_race_id' => $externalRaceId,
            'source_url' => $sourceUrl,
            'requested_result_status' => $requestedResultStatus->value,
        ], $lockKey);
        $item = $this->batchRuns->startItem($run, 'RACE_RESULT', 'race-result:'.$raceKey.':'.$sourceHash);
        $import = null;
        $race = null;
        $importFinalized = false;

        try {
            $storedRaw = $this->rawStorage->storeImportedRawFile($rawFile, (string) $raceKey);
            $race = $this->findRace($raceId, $externalRaceId);
            $import = $this->createImport($race, $run, $item, $sourceUrl, $storedRaw, $requestedResultStatus);

            if (! $race instanceof Race) {
                throw new RuntimeException('Target race was not found.');
            }

            $converted = $this->rawStorage->convertImportedRawFile($storedRaw);
            $import->forceFill([
                'detected_encoding' => $converted->detectedEncoding,
                'utf8_conversion_succeeded' => true,
                'converted_hash' => $converted->sha256,
            ])->save();

            $page = $this->parser->parse($converted->utf8Body);
            $this->validateRequestedStatus($page, $requestedResultStatus);

            $result = match ($page->pageStatus) {
                ParsedRaceResultPageStatus::ResultsAvailable => $this->syncAvailableResults($race, $import, $page, $sourceUrl, $requestedResultStatus),
                ParsedRaceResultPageStatus::Unavailable,
                ParsedRaceResultPageStatus::UnderReview => $this->skipUnavailableResult($race, $import, $page, $sourceUrl, $requestedResultStatus),
                ParsedRaceResultPageStatus::Cancelled => $this->syncCancelled($race, $import, $page, $sourceUrl, $requestedResultStatus),
            };
            $importFinalized = true;

            if ($result['status'] === 'SKIPPED') {
                $this->batchRuns->skipItem($item, 'RESULT_NOT_AVAILABLE', ['import_id' => $import->id, 'status' => 'SKIPPED']);
                $this->batchRuns->finish($run, 0, 1, 0);
            } else {
                $this->batchRuns->succeedItem($item, ['import_id' => $import->id, 'status' => 'SUCCEEDED']);
                $this->batchRuns->finish($run, 1, 0, 0);
            }

            return [
                'results' => $result['results'],
                'payouts' => $result['payouts'],
                'race' => $race->refresh(),
                'import' => $import->refresh(),
                'status' => $result['status'],
            ];
        } catch (Throwable $throwable) {
            if ($import instanceof RaceResultImport && ! $importFinalized) {
                $this->failImport($import, $throwable);
            } elseif (! $import instanceof RaceResultImport && isset($storedRaw)) {
                $import = $this->createImport($race, $run, $item, $sourceUrl, $storedRaw, $requestedResultStatus);
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
            ->when(
                $raceId === null && $externalRaceId !== null,
                fn (Builder $query): Builder => $query
                    ->where('source', (string) config('keirin.source'))
                    ->where('external_race_id', $externalRaceId),
            )
            ->first();
    }

    private function createImport(
        ?Race $race,
        BatchRun $run,
        BatchRunItem $item,
        string $sourceUrl,
        StoredImportedRawFileDto $storedRaw,
        RaceResultStatus $requestedResultStatus,
    ): RaceResultImport {
        return RaceResultImport::query()->create([
            'race_id' => $race?->id,
            'batch_run_id' => $run->id,
            'batch_run_item_id' => $item->id,
            'source_url' => $sourceUrl,
            'source_hash' => $storedRaw->sha256,
            'raw_file_path' => $storedRaw->rawFilePath,
            'raw_response_size' => $storedRaw->responseSize,
            'utf8_conversion_succeeded' => false,
            'parser_version' => (string) config('keirin.parser_version'),
            'requested_result_status' => $requestedResultStatus->value,
            'import_status' => 'RUNNING',
            'imported_at' => new DateTimeImmutable('now'),
        ]);
    }

    private function validateRequestedStatus(ParsedRaceResultPageDto $page, RaceResultStatus $requestedResultStatus): void
    {
        $valid = match ($page->pageStatus) {
            ParsedRaceResultPageStatus::ResultsAvailable => in_array($requestedResultStatus, [
                RaceResultStatus::UnderReview,
                RaceResultStatus::Provisional,
                RaceResultStatus::Confirmed,
                RaceResultStatus::Corrected,
            ], true),
            ParsedRaceResultPageStatus::Unavailable => $requestedResultStatus === RaceResultStatus::Unavailable,
            ParsedRaceResultPageStatus::UnderReview => $requestedResultStatus === RaceResultStatus::UnderReview,
            ParsedRaceResultPageStatus::Cancelled => $requestedResultStatus === RaceResultStatus::Cancelled,
        };

        if (! $valid) {
            throw new RuntimeException("Parsed page status {$page->pageStatus->value} does not match requested result status {$requestedResultStatus->value}.");
        }
    }

    private function validateCompleteAvailablePage(ParsedRaceResultPageDto $page): void
    {
        if ($page->pageStatus !== ParsedRaceResultPageStatus::ResultsAvailable) {
            throw new RuntimeException('Only a results-available page can synchronize race results.');
        }

        if (! $page->resultParsingComplete || ! $page->payoutParsingComplete) {
            throw new RuntimeException('Race result or payout parsing was not complete.');
        }

        if ($page->results === []) {
            throw new RuntimeException('A results-available page must contain at least one result row.');
        }

        $seenBikeNumbers = [];
        foreach ($page->results as $result) {
            if ($result->bikeNumber === null || $result->bikeNumber < 1 || $result->bikeNumber > 9) {
                throw new RuntimeException('Parsed race result row had an invalid bike number.');
            }

            if (isset($seenBikeNumbers[$result->bikeNumber])) {
                throw new RuntimeException("Parsed bike number {$result->bikeNumber} appeared more than once.");
            }

            $seenBikeNumbers[$result->bikeNumber] = true;
        }

        foreach ($page->payouts as $payout) {
            if ($payout->betTypeCode === '' || $payout->combination === '' || $payout->payoutAmount === null) {
                throw new RuntimeException('Parsed payout data was incomplete.');
            }
        }
    }

    /**
     * @return array{results:int,payouts:int,status:string}
     */
    private function syncAvailableResults(
        Race $race,
        RaceResultImport $import,
        ParsedRaceResultPageDto $page,
        string $sourceUrl,
        RaceResultStatus $requestedResultStatus,
    ): array {
        $this->validateCompleteAvailablePage($page);

        DB::transaction(function () use ($race, $import, $page, $sourceUrl, $requestedResultStatus): void {
            $lockedRace = Race::query()->whereKey($race->id)->lockForUpdate()->firstOrFail();
            $this->assertTransitionAllowed($lockedRace, $requestedResultStatus);
            $fetchedAt = new DateTimeImmutable('now');

            $seenResultBikeNumbers = [];
            foreach ($page->results as $result) {
                $bikeNumber = (int) $result->bikeNumber;
                $seenResultBikeNumbers[] = $bikeNumber;
                RaceResult::query()->updateOrCreate(
                    [
                        'race_id' => $lockedRace->id,
                        'bike_number' => $bikeNumber,
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
                'result_status' => $requestedResultStatus->value,
                'result_url' => $sourceUrl,
                'result_confirmed_at' => in_array($requestedResultStatus, [RaceResultStatus::Confirmed, RaceResultStatus::Corrected], true)
                    ? $fetchedAt
                    : null,
                'last_fetched_at' => $fetchedAt,
            ])->save();

            $this->succeedImport($import, $page, count($page->results), count($page->payouts));
        });

        return ['results' => count($page->results), 'payouts' => count($page->payouts), 'status' => 'SUCCEEDED'];
    }

    /**
     * @return array{results:int,payouts:int,status:string}
     */
    private function skipUnavailableResult(
        Race $race,
        RaceResultImport $import,
        ParsedRaceResultPageDto $page,
        string $sourceUrl,
        RaceResultStatus $requestedResultStatus,
    ): array {
        DB::transaction(function () use ($race, $import, $page, $sourceUrl, $requestedResultStatus): void {
            $lockedRace = Race::query()->whereKey($race->id)->lockForUpdate()->firstOrFail();
            $this->assertTransitionAllowed($lockedRace, $requestedResultStatus);

            $lockedRace->forceFill([
                'result_status' => $requestedResultStatus->value,
                'result_url' => $sourceUrl,
                'result_confirmed_at' => null,
                'last_fetched_at' => new DateTimeImmutable('now'),
            ])->save();

            $import->forceFill([
                'parsed_page_status' => $page->pageStatus->value,
                'import_status' => 'SKIPPED',
                'result_count' => 0,
                'payout_count' => 0,
                'error_type' => null,
                'error_message' => null,
            ])->save();
        });

        return ['results' => 0, 'payouts' => 0, 'status' => 'SKIPPED'];
    }

    /**
     * @return array{results:int,payouts:int,status:string}
     */
    private function syncCancelled(
        Race $race,
        RaceResultImport $import,
        ParsedRaceResultPageDto $page,
        string $sourceUrl,
        RaceResultStatus $requestedResultStatus,
    ): array {
        DB::transaction(function () use ($race, $import, $page, $sourceUrl, $requestedResultStatus): void {
            $lockedRace = Race::query()->whereKey($race->id)->lockForUpdate()->firstOrFail();
            $this->assertTransitionAllowed($lockedRace, $requestedResultStatus);

            RaceResult::query()->where('race_id', $lockedRace->id)->get()->each->delete();
            RacePayout::query()->where('race_id', $lockedRace->id)->get()->each->delete();

            $lockedRace->forceFill([
                'result_status' => RaceResultStatus::Cancelled->value,
                'result_url' => $sourceUrl,
                'result_confirmed_at' => null,
                'last_fetched_at' => new DateTimeImmutable('now'),
            ])->save();

            $this->succeedImport($import, $page, 0, 0);
        });

        return ['results' => 0, 'payouts' => 0, 'status' => 'SUCCEEDED'];
    }

    private function assertTransitionAllowed(Race $race, RaceResultStatus $requested): void
    {
        $current = RaceResultStatus::tryFrom((string) $race->result_status);
        if ($current === null) {
            throw new RuntimeException("Race has an unsupported result status: {$race->result_status}");
        }

        if (! $requested->canTransitionFrom($current)) {
            throw new InvalidRaceResultStatusTransitionException($current, $requested);
        }
    }

    private function succeedImport(RaceResultImport $import, ParsedRaceResultPageDto $page, int $resultCount, int $payoutCount): void
    {
        $import->forceFill([
            'parsed_page_status' => $page->pageStatus->value,
            'import_status' => 'SUCCEEDED',
            'result_count' => $resultCount,
            'payout_count' => $payoutCount,
            'error_type' => null,
            'error_message' => null,
        ])->save();
    }

    private function failImport(RaceResultImport $import, Throwable $throwable): void
    {
        $errorType = $throwable instanceof CharacterEncodingConversionException
            ? FetchErrorType::EncodingConversionFailed->value
            : $throwable::class;

        $import->forceFill([
            'import_status' => 'FAILED',
            'result_count' => 0,
            'payout_count' => 0,
            'error_type' => $errorType,
            'error_message' => $throwable->getMessage(),
        ])->save();
    }
}
