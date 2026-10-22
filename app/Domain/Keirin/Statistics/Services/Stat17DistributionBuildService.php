<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Services;

use App\Domain\Keirin\Statistics\Calculators\Stat17DistributionCalculator;
use App\Domain\Keirin\Statistics\Repositories\Stat17DistributionInputRepository;
use App\Models\StatisticFeatureResult;
use App\Models\StatisticFeatureRun;
use App\Models\StatisticFeatureRunItem;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class Stat17DistributionBuildService
{
    public function __construct(private readonly Stat17DistributionInputRepository $inputs,
        private readonly Stat17DistributionCalculator $calculator) {}

    public function build(int $raceId, int $fetchLogId, DateTimeImmutable $asOf, bool $dryRun): array
    {
        return DB::transaction(function () use ($raceId, $fetchLogId, $asOf, $dryRun): array {
            $inputs = $this->inputs->inputs($raceId, $fetchLogId, $asOf);
            $results = array_map($this->calculator->calculate(...), $inputs);
            // Recheck the same saved capture before publishing results; no source fallback or re-selection.
            $end = $this->inputs->inputs($raceId, $fetchLogId, $asOf);
            if (array_map(fn ($i) => $i->observation, $inputs) !== array_map(fn ($i) => $i->observation, $end)) {
                throw new RuntimeException('Tactic input drift before feature publication.');
            }
            $missing = count(array_filter($results, fn ($r) => $r->status->value === 'MISSING_INPUT'));
            $run = $dryRun ? null : StatisticFeatureRun::query()->create([
                'run_uuid' => (string) Str::uuid(), 'stat_code' => Stat17DistributionCalculator::STAT_CODE,
                'calculation_version' => Stat17DistributionCalculator::CALCULATION_VERSION, 'mode' => 'BACKFILL',
                'status' => 'PARTIALLY_SUCCEEDED', 'target_race_id' => $raceId, 'input_as_of_policy' => 'RETROSPECTIVE_DISPLAYED_COUNTS_ONLY',
                'parameters' => ['input_as_of' => $asOf->format(DATE_ATOM), 'fetch_log_id' => $fetchLogId, 'prediction_use' => 'NOT_AUTHORIZED'],
                'target_race_count' => 1, 'processed_race_count' => 1, 'target_entry_count' => count($inputs),
                'partial_count' => count($inputs) - $missing, 'missing_count' => $missing, 'started_at' => now(), 'finished_at' => now()]);
            foreach ($inputs as $index => $input) {
                $row = $input->observation;
                $result = $results[$index];
                if ($run !== null) {
                    StatisticFeatureResult::query()->create(['feature_run_id' => $run->id, 'stat_code' => Stat17DistributionCalculator::STAT_CODE,
                        'calculation_version' => Stat17DistributionCalculator::CALCULATION_VERSION, 'subject_type' => 'RACE_ENTRY',
                        'subject_key' => 'race_entry:'.$row['race_entry_id'], 'race_id' => $raceId, 'race_entry_id' => $row['race_entry_id'],
                        'player_id' => $row['player_id'], 'bike_number' => $row['bike_number'], 'status' => $result->status->value,
                        'quality_status' => $result->qualityStatus->value, 'acquisition_mode' => 'BACKFILL', 'input_as_of' => $asOf,
                        'source_fetched_at' => $row['fetched_at'], 'features' => $result->features, 'evidence' => $result->evidence,
                        'input_hash' => $result->inputHash, 'raw_points' => null, 'confidence' => null, 'effective_points' => null, 'calculated_at' => now()]);
                }
            }
            if ($run !== null) {
                StatisticFeatureRunItem::query()->create(['feature_run_id' => $run->id, 'race_id' => $raceId, 'status' => 'PARTIAL',
                    'attempt_count' => 1, 'feature_result_count' => count($inputs), 'started_at' => now(), 'finished_at' => now()]);
            }

            return ['feature_run_id' => $run?->id, 'race_id' => $raceId, 'fetch_log_id' => $fetchLogId, 'dry_run' => $dryRun,
                'features' => array_map(fn ($r) => ['features' => $r->features, 'evidence' => $r->evidence, 'input_hash' => $r->inputHash], $results),
                'prediction_use' => 'NOT_AUTHORIZED'];
        });
    }
}
