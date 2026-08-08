<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Services;

use App\Domain\Keirin\Statistics\Calculators\Stat39Calculator;
use App\Domain\Keirin\Statistics\Calculators\Stat42Calculator;
use App\Domain\Keirin\Statistics\Contracts\Batch04Calculator;
use App\Domain\Keirin\Statistics\DTO\Batch04BuildOptionsDto;
use App\Domain\Keirin\Statistics\DTO\Batch04BuildSummaryDto;
use App\Domain\Keirin\Statistics\DTO\Batch04FeatureResultDto;
use App\Domain\Keirin\Statistics\DTO\Batch04PositionHistoryContextDto;
use App\Domain\Keirin\Statistics\DTO\Batch04StatSummaryDto;
use App\Domain\Keirin\Statistics\Enums\Batch04Stat;
use App\Domain\Keirin\Statistics\Enums\StatisticAcquisitionMode;
use App\Domain\Keirin\Statistics\Enums\StatisticFeatureResultStatus;
use App\Domain\Keirin\Statistics\Enums\StatisticFeatureRunItemStatus;
use App\Domain\Keirin\Statistics\Enums\StatisticFeatureRunStatus;
use App\Domain\Keirin\Statistics\Repositories\Batch04HeadToHeadRepository;
use App\Domain\Keirin\Statistics\Repositories\Batch04PositionBiasRepository;
use App\Domain\Keirin\Statistics\Repositories\Batch04TargetRepository;
use App\Models\StatisticFeatureResult;
use App\Models\StatisticFeatureRun;
use App\Models\StatisticFeatureRunItem;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class Batch04FeatureBuildService
{
    private const SUBJECT_TYPE = 'RACE_ENTRY';

    private const INPUT_AS_OF_POLICY = 'STAT01_RUN_RESULT_INPUT_AS_OF';

    /** @var array<string, Batch04Calculator> */
    private array $calculators;

    public function __construct(
        private readonly Batch04TargetRepository $targets,
        private readonly Batch04PositionBiasRepository $positions,
        private readonly Batch04HeadToHeadRepository $headToHead,
        Stat39Calculator $stat39,
        Stat42Calculator $stat42,
    ) {
        $this->calculators = [];
        foreach ([$stat39, $stat42] as $calculator) {
            $this->calculators[$calculator->stat()->value] = $calculator;
        }
    }

    public function build(Batch04BuildOptionsDto $options): Batch04BuildSummaryDto
    {
        $this->targets->validatedStat01Run($options->stat01RunId);
        $targetCounts = $this->targets->counts($options);
        if ($targetCounts->races === 0) {
            throw new RuntimeException('No target races were found in the specified STAT-01 run.');
        }
        $this->targets->assertTargetInputAsOfComplete($options);
        if ($options->historyFrom >= $this->targets->earliestTargetDate($options)) {
            throw new RuntimeException('--history-from must be before every target race date.');
        }
        $this->positions->begin($options->historyFrom, $this->targets->latestTargetInputAsOf($options));
        $batchExecutionUuid = (string) Str::uuid();
        $runs = $options->dryRun ? [] : $this->startRuns(
            $options,
            $batchExecutionUuid,
            $targetCounts->races,
            $targetCounts->entries,
        );
        $counters = [];
        foreach (Batch04Stat::cases() as $stat) {
            $counters[$stat->value] = $this->emptyCounters();
        }
        $fatal = null;

        try {
            foreach ($this->targets->workingBatches($options) as $workingBatch) {
                $pairHistories = [];
                $pairHistoryFailure = null;
                try {
                    $pairHistories = $this->headToHead->historiesForWorkingBatch($workingBatch, $options);
                } catch (Throwable $throwable) {
                    $pairHistoryFailure = $throwable;
                }
                foreach ($workingBatch as $race) {
                    $positionContexts = [];
                    $positionFailure = null;
                    try {
                        $positionContexts = $this->positions->contextsForRace($race);
                    } catch (Throwable $throwable) {
                        $positionFailure = $throwable;
                    }
                    foreach ($this->calculators as $statCode => $calculator) {
                        $counters[$statCode]['processed_races']++;
                        $run = $runs[$statCode] ?? null;
                        $item = null;
                        try {
                            if ($run instanceof StatisticFeatureRun) {
                                $item = StatisticFeatureRunItem::query()->create([
                                    'feature_run_id' => $run->id,
                                    'race_id' => $race->raceId,
                                    'status' => StatisticFeatureRunItemStatus::Running->value,
                                    'attempt_count' => 1,
                                    'started_at' => new DateTimeImmutable('now'),
                                ]);
                            }
                            if ($calculator->stat() === Batch04Stat::Stat39 && $positionFailure instanceof Throwable) {
                                throw $positionFailure;
                            }
                            if ($calculator->stat() === Batch04Stat::Stat42 && $pairHistoryFailure instanceof Throwable) {
                                throw $pairHistoryFailure;
                            }
                            $results = [];
                            foreach ($race->entries as $entry) {
                                $positionContext = $positionContexts[$entry->raceEntryId]
                                    ?? new Batch04PositionHistoryContextDto([], hash('sha256', ''), []);
                                $results[] = $calculator->calculate(
                                    $entry,
                                    $race,
                                    $positionContext,
                                    $pairHistories,
                                    $options,
                                    $batchExecutionUuid,
                                );
                            }
                            if ($run instanceof StatisticFeatureRun && $item instanceof StatisticFeatureRunItem) {
                                DB::transaction(function () use ($run, $item, $race, $results): void {
                                    $calculatedAt = new DateTimeImmutable('now');
                                    foreach ($results as $result) {
                                        $this->storeResult($run, $race->raceId, $result, $calculatedAt);
                                    }
                                    $item->forceFill([
                                        'status' => $this->itemIsPartial($results)
                                            ? StatisticFeatureRunItemStatus::Partial->value
                                            : StatisticFeatureRunItemStatus::Succeeded->value,
                                        'feature_result_count' => count($results),
                                        'finished_at' => new DateTimeImmutable('now'),
                                    ])->save();
                                });
                            }
                            foreach ($results as $result) {
                                $this->countResult($counters[$statCode], $result->status);
                            }
                        } catch (Throwable $throwable) {
                            $counters[$statCode]['errors']++;
                            $counters[$statCode]['error_summary'][] = [
                                'race_id' => $race->raceId,
                                'error_type' => $throwable::class,
                                'error_message' => $throwable->getMessage(),
                            ];
                            if ($item instanceof StatisticFeatureRunItem) {
                                $item->forceFill([
                                    'status' => StatisticFeatureRunItemStatus::Failed->value,
                                    'error_type' => $throwable::class,
                                    'error_message' => $throwable->getMessage(),
                                    'finished_at' => new DateTimeImmutable('now'),
                                ])->save();
                            }
                        }
                    }
                }
                unset($pairHistories, $workingBatch);
            }
        } catch (Throwable $throwable) {
            $fatal = $throwable;
            foreach (Batch04Stat::cases() as $stat) {
                $counters[$stat->value]['errors']++;
                $counters[$stat->value]['error_summary'][] = [
                    'race_id' => null,
                    'error_type' => $throwable::class,
                    'error_message' => $throwable->getMessage(),
                ];
            }
        } finally {
            foreach ($runs as $statCode => $run) {
                $this->finishRun($run, $counters[$statCode]);
            }
        }
        if ($fatal instanceof Throwable && $options->dryRun) {
            throw $fatal;
        }

        $summaries = [];
        foreach (Batch04Stat::cases() as $stat) {
            $counter = $counters[$stat->value];
            $summaries[] = new Batch04StatSummaryDto(
                statCode: $stat->value,
                runId: ($runs[$stat->value] ?? null)?->id,
                processedRaces: $counter['processed_races'],
                resultCount: $counter['results'],
                validCount: $counter['valid'],
                noHistoryCount: $counter['no_history'],
                notApplicableCount: $counter['not_applicable'],
                partialHistoryCount: $counter['partial_history'],
                partialCount: $counter['partial'],
                missingCount: $counter['missing'],
                invalidCount: $counter['invalid'],
                errorCount: $counter['errors'],
            );
        }

        return new Batch04BuildSummaryDto(
            $batchExecutionUuid,
            $options->dryRun,
            $targetCounts->races,
            $targetCounts->entries,
            $summaries,
        );
    }

    /** @return array<string, StatisticFeatureRun> */
    private function startRuns(Batch04BuildOptionsDto $options, string $batchExecutionUuid, int $targetRaces, int $targetEntries): array
    {
        return DB::transaction(function () use ($options, $batchExecutionUuid, $targetRaces, $targetEntries): array {
            $runs = [];
            foreach (Batch04Stat::cases() as $stat) {
                $runs[$stat->value] = StatisticFeatureRun::query()->create([
                    'run_uuid' => (string) Str::uuid(),
                    'stat_code' => $stat->value,
                    'calculation_version' => $stat->calculationVersion(),
                    'mode' => StatisticAcquisitionMode::Backfill->value,
                    'status' => StatisticFeatureRunStatus::Running->value,
                    'history_from' => $options->historyFrom->format('Y-m-d'),
                    'target_from' => $options->from?->format('Y-m-d'),
                    'target_to' => $options->to?->format('Y-m-d'),
                    'target_race_id' => $options->raceId,
                    'input_as_of_policy' => self::INPUT_AS_OF_POLICY,
                    'parameters' => $options->parameters($batchExecutionUuid),
                    'target_race_count' => $targetRaces,
                    'target_entry_count' => $targetEntries,
                    'started_at' => new DateTimeImmutable('now'),
                ]);
            }

            return $runs;
        });
    }

    private function storeResult(StatisticFeatureRun $run, int $raceId, Batch04FeatureResultDto $result, DateTimeImmutable $calculatedAt): void
    {
        $sourceFetchedAt = $result->evidence['source_max_fetched_at'] ?? null;
        StatisticFeatureResult::query()->create([
            'feature_run_id' => $run->id,
            'stat_code' => $run->stat_code,
            'calculation_version' => $run->calculation_version,
            'subject_type' => self::SUBJECT_TYPE,
            'subject_key' => 'race_entry:'.$result->target->raceEntryId,
            'race_id' => $raceId,
            'race_entry_id' => $result->target->raceEntryId,
            'player_id' => $result->target->playerId,
            'opponent_player_id' => null,
            'bike_number' => $result->target->bikeNumber,
            'status' => $result->status->value,
            'quality_status' => $result->qualityStatus->value,
            'acquisition_mode' => StatisticAcquisitionMode::Backfill->value,
            'input_as_of' => $result->target->inputAsOf,
            'source_fetched_at' => $sourceFetchedAt !== null ? new DateTimeImmutable((string) $sourceFetchedAt) : null,
            'features' => $result->features,
            'evidence' => $result->evidence,
            'input_hash' => $result->inputHash,
            'raw_points' => null,
            'confidence' => null,
            'effective_points' => null,
            'calculated_at' => $calculatedAt,
        ]);
    }

    /** @param list<Batch04FeatureResultDto> $results */
    private function itemIsPartial(array $results): bool
    {
        foreach ($results as $result) {
            if (! in_array($result->status, [StatisticFeatureResultStatus::Valid, StatisticFeatureResultStatus::NotApplicable], true)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $counter */
    private function countResult(array &$counter, StatisticFeatureResultStatus $status): void
    {
        $counter['results']++;
        match ($status) {
            StatisticFeatureResultStatus::Valid => $counter['valid']++,
            StatisticFeatureResultStatus::NoHistory => $counter['no_history']++,
            StatisticFeatureResultStatus::NotApplicable => $counter['not_applicable']++,
            StatisticFeatureResultStatus::PartialHistory => $counter['partial_history']++,
            StatisticFeatureResultStatus::Partial => $counter['partial']++,
            StatisticFeatureResultStatus::MissingInput => $counter['missing']++,
            StatisticFeatureResultStatus::InvalidInput => $counter['invalid']++,
        };
    }

    /** @param array<string, mixed> $counter */
    private function finishRun(StatisticFeatureRun $run, array $counter): void
    {
        $nonSuccessful = $counter['no_history'] + $counter['partial_history'] + $counter['partial'] + $counter['missing'] + $counter['invalid'];
        $status = $counter['errors'] > 0 && $counter['results'] === 0
            ? StatisticFeatureRunStatus::Failed
            : ($counter['errors'] > 0 || $nonSuccessful > 0
                ? StatisticFeatureRunStatus::PartiallySucceeded
                : StatisticFeatureRunStatus::Succeeded);
        $run->forceFill([
            'status' => $status->value,
            'processed_race_count' => $counter['processed_races'],
            'success_count' => $counter['valid'] + $counter['not_applicable'],
            'partial_count' => $counter['no_history'] + $counter['partial_history'] + $counter['partial'],
            'missing_count' => $counter['missing'],
            'invalid_count' => $counter['invalid'],
            'error_count' => $counter['errors'],
            'error_summary' => $counter['error_summary'] !== []
                ? json_encode(array_slice($counter['error_summary'], 0, 100), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
                : null,
            'finished_at' => new DateTimeImmutable('now'),
        ])->save();
    }

    /** @return array<string, mixed> */
    private function emptyCounters(): array
    {
        return [
            'processed_races' => 0,
            'results' => 0,
            'valid' => 0,
            'no_history' => 0,
            'not_applicable' => 0,
            'partial_history' => 0,
            'partial' => 0,
            'missing' => 0,
            'invalid' => 0,
            'errors' => 0,
            'error_summary' => [],
        ];
    }
}
