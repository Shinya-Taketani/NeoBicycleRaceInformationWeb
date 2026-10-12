<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Services;

use App\Domain\Keirin\Statistics\Calculators\Stat20Calculator;
use App\Domain\Keirin\Statistics\DTO\Stat20BuildOptionsDto;
use App\Domain\Keirin\Statistics\DTO\Stat20BuildSummaryDto;
use App\Domain\Keirin\Statistics\Repositories\Stat20InputRepository;
use App\Models\StatisticFeatureResult;
use App\Models\StatisticFeatureRun;
use App\Models\StatisticFeatureRunItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class Stat20FeatureBuildService
{
    public function __construct(private readonly Stat20InputRepository $inputs, private readonly Stat20Calculator $calculator) {}

    public function build(Stat20BuildOptionsDto $options): Stat20BuildSummaryDto
    {
        $sources = $this->inputs->sourceRuns($options);
        $targets = $this->inputs->counts($options);
        if ($targets->races === 0) {
            throw new RuntimeException('No permitted STAT-20 target races.');
        }
        $run = $options->dryRun ? null : StatisticFeatureRun::query()->create([
            'run_uuid' => (string) Str::uuid(), 'stat_code' => Stat20Calculator::STAT_CODE,
            'calculation_version' => Stat20Calculator::CALCULATION_VERSION, 'mode' => 'BACKFILL', 'status' => 'RUNNING',
            'target_from' => $options->from?->format('Y-m-d'), 'target_to' => $options->to?->format('Y-m-d'),
            'target_race_id' => $options->raceId, 'target_race_count' => $targets->races, 'target_entry_count' => $targets->entries,
            'input_as_of_policy' => 'STAT01_RUN_RESULT_INPUT_AS_OF',
            'parameters' => ['stat01_run_id' => $options->stat01RunId, 'source_run_ids' => $sources,
                'chunk' => $options->chunkSize, 'result_grain' => 'RACE_ENTRY', 'confidence_policy' => 'NOT_DEFINED'],
            'started_at' => now(),
        ]);
        $counts = ['processed_races' => 0, 'results' => 0, 'valid' => 0, 'partial' => 0, 'missing' => 0, 'invalid' => 0, 'errors' => 0];
        $errors = [];
        $recordError = static function (Throwable $e, ?int $id) use (&$counts, &$errors): void {
            $counts['errors']++;
            if (count($errors) < 100) {
                $errors[] = ['race_id' => $id, 'error_type' => $e::class, 'error_message' => $e->getMessage()];
            }
        };
        try {
            foreach ($this->inputs->raceIds($options) as $id) {
                $counts['processed_races']++;
                $item = null;
                try {
                    $item = $run === null ? null : StatisticFeatureRunItem::query()->create([
                        'feature_run_id' => $run->id, 'race_id' => $id, 'status' => 'RUNNING', 'attempt_count' => 1, 'started_at' => now(),
                    ]);
                    $results = $this->calculator->calculate($this->inputs->raceInput($options, $sources, $id));
                    if ($run !== null) {
                        DB::transaction(function () use ($run, $item, $results): void {
                            foreach ($results as $result) {
                                $target = $result->target;
                                StatisticFeatureResult::query()->create([
                                    'feature_run_id' => $run->id, 'stat_code' => Stat20Calculator::STAT_CODE,
                                    'calculation_version' => Stat20Calculator::CALCULATION_VERSION,
                                    'subject_type' => 'RACE_ENTRY', 'subject_key' => 'race_entry:'.$target->raceEntryId,
                                    'race_id' => $target->raceId, 'race_entry_id' => $target->raceEntryId,
                                    'player_id' => $target->playerId, 'bike_number' => $target->bikeNumber,
                                    'status' => $result->status->value, 'quality_status' => $result->qualityStatus->value,
                                    'acquisition_mode' => 'BACKFILL', 'input_as_of' => $target->inputAsOf,
                                    'source_fetched_at' => $result->sourceFetchedAt,
                                    'features' => $result->features, 'evidence' => $result->evidence, 'input_hash' => $result->inputHash,
                                    'raw_points' => null, 'confidence' => null, 'effective_points' => null, 'calculated_at' => now(),
                                ]);
                            }
                            $item->forceFill(['status' => 'PARTIAL', 'feature_result_count' => count($results), 'finished_at' => now()])->save();
                        });
                    }
                    foreach ($results as $result) {
                        $counts['results']++;
                        $counts[match ($result->status->value) {
                            'VALID' => 'valid', 'INVALID_INPUT' => 'invalid', 'MISSING_INPUT' => 'missing', default => 'partial',
                        }]++;
                    }
                    unset($results);
                } catch (Throwable $e) {
                    $recordError($e, $id);
                    $item?->forceFill(['status' => 'FAILED', 'error_type' => $e::class,
                        'error_message' => $e->getMessage(), 'finished_at' => now()])->save();
                }
            }
        } catch (Throwable $e) {
            $recordError($e, null);
        } finally {
            $run?->forceFill([
                'status' => $counts['errors'] > 0 && $counts['results'] === 0 ? 'FAILED' : 'PARTIALLY_SUCCEEDED',
                'processed_race_count' => $counts['processed_races'], 'success_count' => $counts['valid'],
                'partial_count' => $counts['partial'], 'missing_count' => $counts['missing'], 'invalid_count' => $counts['invalid'],
                'error_count' => $counts['errors'], 'error_summary' => $errors === [] ? null : json_encode($errors, JSON_THROW_ON_ERROR),
                'finished_at' => now(),
            ])->save();
        }

        return new Stat20BuildSummaryDto($run?->id, $options->dryRun, $targets->races, $targets->entries, $counts);
    }
}
