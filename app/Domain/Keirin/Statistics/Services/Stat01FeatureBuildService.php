<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Services;

use App\Domain\Keirin\Statistics\Calculators\Stat01Calculator;
use App\Domain\Keirin\Statistics\DTO\Stat01BuildOptionsDto;
use App\Domain\Keirin\Statistics\DTO\Stat01BuildSummaryDto;
use App\Domain\Keirin\Statistics\DTO\Stat01EntryFeatureDto;
use App\Domain\Keirin\Statistics\Enums\StatisticAcquisitionMode;
use App\Domain\Keirin\Statistics\Enums\StatisticFeatureResultStatus;
use App\Domain\Keirin\Statistics\Enums\StatisticFeatureRunItemStatus;
use App\Domain\Keirin\Statistics\Enums\StatisticFeatureRunStatus;
use App\Domain\Keirin\Statistics\Repositories\Stat01InputRepository;
use App\Models\StatisticFeatureResult;
use App\Models\StatisticFeatureRun;
use App\Models\StatisticFeatureRunItem;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class Stat01FeatureBuildService
{
    private const SUBJECT_TYPE = 'RACE_ENTRY';

    private const INPUT_AS_OF_POLICY = 'SALES_CLOSE_AT_THEN_SCHEDULED_START_AT';

    public function __construct(
        private readonly Stat01InputRepository $inputs,
        private readonly Stat01Calculator $calculator,
    ) {}

    public function build(Stat01BuildOptionsDto $options): Stat01BuildSummaryDto
    {
        $targets = $this->inputs->counts($options);
        if ($targets->races === 0) {
            throw new RuntimeException('No target races were found.');
        }
        $run = $options->dryRun ? null : $this->startRun($options, $targets->races, $targets->entries);
        $processed = $success = $partial = $missing = $invalid = $errors = 0;
        $hasPartialRace = false;
        $errorSummary = [];
        $fatal = null;

        try {
            foreach ($this->inputs->raceInputs($options) as $race) {
                $processed++;
                $item = null;

                try {
                    $item = $run instanceof StatisticFeatureRun
                        ? StatisticFeatureRunItem::query()->create([
                            'feature_run_id' => $run->id,
                            'race_id' => $race->id,
                            'status' => StatisticFeatureRunItemStatus::Running->value,
                            'attempt_count' => 1,
                            'started_at' => new DateTimeImmutable('now'),
                        ])
                        : null;
                    $calculation = $this->calculator->calculate($race);
                    $raceCounts = [
                        'success' => 0,
                        'partial' => 0,
                        'missing' => 0,
                        'invalid' => 0,
                    ];
                    foreach ($calculation->entries as $entryResult) {
                        match ($entryResult->status) {
                            StatisticFeatureResultStatus::Valid => $raceCounts['success']++,
                            StatisticFeatureResultStatus::Partial => $raceCounts['partial']++,
                            StatisticFeatureResultStatus::MissingInput => $raceCounts['missing']++,
                            StatisticFeatureResultStatus::InvalidInput => $raceCounts['invalid']++,
                        };
                    }

                    if ($run instanceof StatisticFeatureRun && $item instanceof StatisticFeatureRunItem) {
                        DB::transaction(function () use ($run, $item, $calculation): void {
                            $calculatedAt = new DateTimeImmutable('now');
                            foreach ($calculation->entries as $entryResult) {
                                $this->storeResult($run, $calculation->raceId, $entryResult, $calculatedAt);
                            }
                            $item->forceFill([
                                'status' => $calculation->partial
                                    ? StatisticFeatureRunItemStatus::Partial->value
                                    : StatisticFeatureRunItemStatus::Succeeded->value,
                                'feature_result_count' => count($calculation->entries),
                                'finished_at' => new DateTimeImmutable('now'),
                            ])->save();
                        });
                    }

                    $success += $raceCounts['success'];
                    $partial += $raceCounts['partial'];
                    $missing += $raceCounts['missing'];
                    $invalid += $raceCounts['invalid'];
                    $hasPartialRace = $hasPartialRace || $calculation->partial;
                } catch (Throwable $throwable) {
                    $errors++;
                    $errorSummary[] = [
                        'race_id' => $race->id,
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
        } catch (Throwable $throwable) {
            $errors++;
            $fatal = $throwable;
            $errorSummary[] = [
                'race_id' => null,
                'error_type' => $throwable::class,
                'error_message' => $throwable->getMessage(),
            ];
        } finally {
            if ($run instanceof StatisticFeatureRun) {
                $run->forceFill([
                    'status' => $this->runStatus($success, $partial, $missing, $invalid, $errors, $hasPartialRace)->value,
                    'processed_race_count' => $processed,
                    'success_count' => $success,
                    'partial_count' => $partial,
                    'missing_count' => $missing,
                    'invalid_count' => $invalid,
                    'error_count' => $errors,
                    'error_summary' => $errorSummary !== []
                        ? json_encode(array_slice($errorSummary, 0, 100), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
                        : null,
                    'finished_at' => new DateTimeImmutable('now'),
                ])->save();
            }
        }

        if ($fatal instanceof Throwable && $options->dryRun) {
            throw $fatal;
        }

        return new Stat01BuildSummaryDto(
            runId: $run?->id,
            runUuid: $run?->run_uuid,
            dryRun: $options->dryRun,
            targetRaceCount: $targets->races,
            processedRaceCount: $processed,
            targetEntryCount: $targets->entries,
            successCount: $success,
            partialCount: $partial,
            missingCount: $missing,
            invalidCount: $invalid,
            errorCount: $errors,
        );
    }

    private function startRun(Stat01BuildOptionsDto $options, int $targetRaces, int $targetEntries): StatisticFeatureRun
    {
        return StatisticFeatureRun::query()->create([
            'run_uuid' => (string) Str::uuid(),
            'stat_code' => Stat01Calculator::STAT_CODE,
            'calculation_version' => Stat01Calculator::CALCULATION_VERSION,
            'mode' => StatisticAcquisitionMode::Backfill->value,
            'status' => StatisticFeatureRunStatus::Running->value,
            'history_from' => $options->historyFrom()?->format('Y-m-d'),
            'target_from' => $options->from?->format('Y-m-d'),
            'target_to' => $options->to?->format('Y-m-d'),
            'target_race_id' => $options->raceId,
            'input_as_of_policy' => self::INPUT_AS_OF_POLICY,
            'parameters' => $options->parameters(),
            'target_race_count' => $targetRaces,
            'target_entry_count' => $targetEntries,
            'started_at' => new DateTimeImmutable('now'),
        ]);
    }

    private function storeResult(
        StatisticFeatureRun $run,
        int $raceId,
        Stat01EntryFeatureDto $result,
        DateTimeImmutable $calculatedAt,
    ): void {
        StatisticFeatureResult::query()->create([
            'feature_run_id' => $run->id,
            'stat_code' => Stat01Calculator::STAT_CODE,
            'calculation_version' => Stat01Calculator::CALCULATION_VERSION,
            'subject_type' => self::SUBJECT_TYPE,
            'subject_key' => 'race_entry:'.$result->entry->id,
            'race_id' => $raceId,
            'race_entry_id' => $result->entry->id,
            'player_id' => $result->entry->playerId,
            'opponent_player_id' => null,
            'bike_number' => $result->entry->bikeNumber,
            'status' => $result->status->value,
            'quality_status' => $result->qualityStatus->value,
            'acquisition_mode' => StatisticAcquisitionMode::Backfill->value,
            'input_as_of' => $result->inputAsOf,
            'source_fetched_at' => $result->entry->fetchedAt,
            'features' => $result->features,
            'evidence' => $result->evidence,
            'input_hash' => $result->inputHash,
            'raw_points' => null,
            'confidence' => null,
            'effective_points' => null,
            'calculated_at' => $calculatedAt,
        ]);
    }

    private function runStatus(
        int $success,
        int $partial,
        int $missing,
        int $invalid,
        int $errors,
        bool $hasPartialRace,
    ): StatisticFeatureRunStatus {
        if ($errors > 0 && $success + $partial + $missing + $invalid === 0) {
            return StatisticFeatureRunStatus::Failed;
        }
        if ($errors > 0 || $partial > 0 || $missing > 0 || $invalid > 0 || $hasPartialRace) {
            return StatisticFeatureRunStatus::PartiallySucceeded;
        }

        return StatisticFeatureRunStatus::Succeeded;
    }
}
