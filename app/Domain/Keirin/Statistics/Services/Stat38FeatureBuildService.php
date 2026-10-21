<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Services;

use App\Domain\Keirin\Statistics\Calculators\Stat38Calculator;
use App\Domain\Keirin\Statistics\Repositories\Stat38InputRepository;
use App\Models\StatisticFeatureResult;
use App\Models\StatisticFeatureRun;
use App\Models\StatisticFeatureRunItem;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class Stat38FeatureBuildService
{
    public function __construct(private readonly Stat38InputRepository $inputs, private readonly Stat38Calculator $calculator) {}

    public function build(int $raceId, DateTimeImmutable $asOf, bool $dryRun): array
    {
        return DB::transaction(function () use ($raceId, $asOf, $dryRun): array {
            $results = [];
            foreach ($this->inputs->inputs($raceId, $asOf) as $input) {
                $results[] = ['input' => $input, 'result' => $this->calculator->calculate($input)];
            }
            if ($results === []) {
                throw new RuntimeException('No STAT-38 target entries.');
            }
            $run = $dryRun ? null : StatisticFeatureRun::query()->create([
                'run_uuid' => (string) Str::uuid(), 'stat_code' => Stat38Calculator::STAT_CODE,
                'calculation_version' => Stat38Calculator::CALCULATION_VERSION, 'mode' => 'BACKFILL',
                'status' => 'PARTIALLY_SUCCEEDED', 'target_race_id' => $raceId,
                'input_as_of_policy' => 'EXPLICIT_STRICT_ACQUIRED_AND_SAVED_BEFORE_INPUT',
                'parameters' => ['input_as_of' => $asOf->format(DATE_ATOM), 'prediction_use' => 'NOT_AUTHORIZED'],
                'target_race_count' => 1, 'processed_race_count' => 1, 'target_entry_count' => count($results),
                'partial_count' => count(array_filter($results, fn ($row) => $row['result']->status->value === 'PARTIAL')),
                'missing_count' => count(array_filter($results, fn ($row) => $row['result']->status->value === 'MISSING_INPUT')),
                'started_at' => now(), 'finished_at' => now(),
            ]);
            foreach ($results as ['input' => $input, 'result' => $result]) {
                if ($run !== null) {
                    StatisticFeatureResult::query()->create([
                        'feature_run_id' => $run->id, 'stat_code' => Stat38Calculator::STAT_CODE,
                        'calculation_version' => Stat38Calculator::CALCULATION_VERSION,
                        'subject_type' => 'RACE_ENTRY', 'subject_key' => 'race_entry:'.$input->entry['id'],
                        'race_id' => $raceId, 'race_entry_id' => $input->entry['id'], 'player_id' => $input->entry['player_id'],
                        'bike_number' => $input->entry['bike_number'], 'status' => $result->status->value,
                        'quality_status' => $result->qualityStatus->value, 'acquisition_mode' => 'BACKFILL',
                        'input_as_of' => $asOf, 'source_fetched_at' => $result->evidence['retrospective_observation']['fetched_at'] ?? null,
                        'features' => $result->features, 'evidence' => $result->evidence, 'input_hash' => $result->inputHash,
                        'raw_points' => null, 'confidence' => null, 'effective_points' => null, 'calculated_at' => now(),
                    ]);
                }
            }
            if ($run !== null) {
                StatisticFeatureRunItem::query()->create(['feature_run_id' => $run->id, 'race_id' => $raceId,
                    'status' => 'PARTIAL', 'attempt_count' => 1, 'feature_result_count' => count($results),
                    'started_at' => now(), 'finished_at' => now()]);
            }

            return ['feature_run_id' => $run?->id, 'dry_run' => $dryRun, 'race_id' => $raceId, 'entries' => count($results),
                'features' => array_map(fn ($row) => ['race_entry_id' => $row['input']->entry['id'],
                    'status' => $row['result']->status->value, 'features' => $row['result']->features,
                    'input_hash' => $row['result']->inputHash], $results), 'prediction_use' => 'NOT_AUTHORIZED'];
        });
    }
}
