<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Services;

use App\Domain\Keirin\Statistics\Calculators\Stat41Calculator;
use App\Domain\Keirin\Statistics\DTO\Batch05BuildOptionsDto;
use App\Domain\Keirin\Statistics\DTO\Batch05BuildSummaryDto;
use App\Domain\Keirin\Statistics\DTO\Batch05FeatureResultDto;
use App\Domain\Keirin\Statistics\Enums\StatisticAcquisitionMode;
use App\Domain\Keirin\Statistics\Enums\StatisticFeatureResultStatus;
use App\Domain\Keirin\Statistics\Enums\StatisticFeatureRunItemStatus;
use App\Domain\Keirin\Statistics\Enums\StatisticFeatureRunStatus;
use App\Domain\Keirin\Statistics\Repositories\Batch05TargetRepository;
use App\Models\StatisticFeatureResult;
use App\Models\StatisticFeatureRun;
use App\Models\StatisticFeatureRunItem;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class Batch05FeatureBuildService
{
    private const SUBJECT_TYPE = 'RACE';

    private const INPUT_AS_OF_POLICY = 'STAT01_RUN_RESULT_INPUT_AS_OF';

    public function __construct(
        private readonly Batch05TargetRepository $targets,
        private readonly Stat41Calculator $calculator,
    ) {}

    public function build(Batch05BuildOptionsDto $options): Batch05BuildSummaryDto
    {
        $this->targets->validatedStat01Run($options->stat01RunId);
        $counts = $this->targets->counts($options);
        if ($counts->races === 0) {
            throw new RuntimeException('No target races were found in the specified STAT-01 run.');
        }
        $this->targets->assertTargetInputAsOfConsistent($options);

        $batchExecutionUuid = (string) Str::uuid();
        $run = $options->dryRun ? null : $this->startRun($options, $batchExecutionUuid, $counts->races, $counts->entries);
        $counter = $this->emptyCounter();
        $fatal = null;
        try {
            foreach ($this->targets->workingBatches($options) as $workingBatch) {
                foreach ($workingBatch as $race) {
                    $counter['processed_races']++;
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
                        $result = $this->calculator->calculate($race);
                        if ($run instanceof StatisticFeatureRun && $item instanceof StatisticFeatureRunItem) {
                            DB::transaction(function () use ($run, $item, $result): void {
                                $this->storeResult($run, $result, new DateTimeImmutable('now'));
                                $item->forceFill([
                                    'status' => $result->status === StatisticFeatureResultStatus::Valid
                                        ? StatisticFeatureRunItemStatus::Succeeded->value
                                        : StatisticFeatureRunItemStatus::Partial->value,
                                    'feature_result_count' => 1,
                                    'finished_at' => new DateTimeImmutable('now'),
                                ])->save();
                            });
                        }
                        $this->countResult($counter, $result->status);
                    } catch (Throwable $throwable) {
                        $counter['errors']++;
                        $counter['error_summary'][] = [
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
                unset($workingBatch);
            }
        } catch (Throwable $throwable) {
            $fatal = $throwable;
            $counter['errors']++;
            $counter['error_summary'][] = [
                'race_id' => null,
                'error_type' => $throwable::class,
                'error_message' => $throwable->getMessage(),
            ];
        } finally {
            if ($run instanceof StatisticFeatureRun) {
                $this->finishRun($run, $counter);
            }
        }
        if ($fatal instanceof Throwable && $options->dryRun) {
            throw $fatal;
        }

        return new Batch05BuildSummaryDto(
            batchExecutionUuid: $batchExecutionUuid,
            dryRun: $options->dryRun,
            runId: $run?->id,
            targetRaces: $counts->races,
            targetEntries: $counts->entries,
            processedRaces: $counter['processed_races'],
            resultCount: $counter['results'],
            validCount: $counter['valid'],
            partialCount: $counter['partial'],
            missingCount: $counter['missing'],
            invalidCount: $counter['invalid'],
            errorCount: $counter['errors'],
        );
    }

    private function startRun(Batch05BuildOptionsDto $options, string $batchExecutionUuid, int $races, int $entries): StatisticFeatureRun
    {
        return StatisticFeatureRun::query()->create([
            'run_uuid' => (string) Str::uuid(),
            'stat_code' => Stat41Calculator::STAT_CODE,
            'calculation_version' => Stat41Calculator::CALCULATION_VERSION,
            'mode' => StatisticAcquisitionMode::Backfill->value,
            'status' => StatisticFeatureRunStatus::Running->value,
            'history_from' => null,
            'target_from' => $options->from?->format('Y-m-d'),
            'target_to' => $options->to?->format('Y-m-d'),
            'target_race_id' => $options->raceId,
            'input_as_of_policy' => self::INPUT_AS_OF_POLICY,
            'parameters' => $options->parameters($batchExecutionUuid),
            'target_race_count' => $races,
            'target_entry_count' => $entries,
            'started_at' => new DateTimeImmutable('now'),
        ]);
    }

    private function storeResult(StatisticFeatureRun $run, Batch05FeatureResultDto $result, DateTimeImmutable $calculatedAt): void
    {
        StatisticFeatureResult::query()->create([
            'feature_run_id' => $run->id,
            'stat_code' => Stat41Calculator::STAT_CODE,
            'calculation_version' => Stat41Calculator::CALCULATION_VERSION,
            'subject_type' => self::SUBJECT_TYPE,
            'subject_key' => 'race:'.$result->raceId,
            'race_id' => $result->raceId,
            'race_entry_id' => null,
            'player_id' => null,
            'opponent_player_id' => null,
            'bike_number' => null,
            'status' => $result->status->value,
            'quality_status' => $result->qualityStatus->value,
            'acquisition_mode' => StatisticAcquisitionMode::Backfill->value,
            'input_as_of' => $result->inputAsOf,
            'source_fetched_at' => $result->sourceFetchedAt,
            'features' => $result->features,
            'evidence' => $result->evidence,
            'input_hash' => $result->inputHash,
            'raw_points' => null,
            'confidence' => null,
            'effective_points' => null,
            'calculated_at' => $calculatedAt,
        ]);
    }

    /** @param array<string, mixed> $counter */
    private function countResult(array &$counter, StatisticFeatureResultStatus $status): void
    {
        $counter['results']++;
        match ($status) {
            StatisticFeatureResultStatus::Valid => $counter['valid']++,
            StatisticFeatureResultStatus::Partial => $counter['partial']++,
            StatisticFeatureResultStatus::MissingInput => $counter['missing']++,
            StatisticFeatureResultStatus::InvalidInput => $counter['invalid']++,
            default => $counter['partial']++,
        };
    }

    /** @param array<string, mixed> $counter */
    private function finishRun(StatisticFeatureRun $run, array $counter): void
    {
        $nonSuccessful = $counter['partial'] + $counter['missing'] + $counter['invalid'];
        $status = $counter['errors'] > 0 && $counter['results'] === 0
            ? StatisticFeatureRunStatus::Failed
            : ($counter['errors'] > 0 || $nonSuccessful > 0
                ? StatisticFeatureRunStatus::PartiallySucceeded
                : StatisticFeatureRunStatus::Succeeded);
        $run->forceFill([
            'status' => $status->value,
            'processed_race_count' => $counter['processed_races'],
            'success_count' => $counter['valid'],
            'partial_count' => $counter['partial'],
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
    private function emptyCounter(): array
    {
        return [
            'processed_races' => 0,
            'results' => 0,
            'valid' => 0,
            'partial' => 0,
            'missing' => 0,
            'invalid' => 0,
            'errors' => 0,
            'error_summary' => [],
        ];
    }
}
