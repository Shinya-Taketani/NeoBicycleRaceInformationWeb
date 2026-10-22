<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Services;

use App\Domain\Keirin\Statistics\Calculators\Stat19OfficialMethodHistoryCalculator;
use App\Domain\Keirin\Statistics\Calculators\Stat30OfficialMethodMixCalculator;
use App\Domain\Keirin\Statistics\OfficialMethodHistory\WindowBuilder;
use App\Domain\Keirin\Statistics\Repositories\OfficialMethodHistoryRepository;
use App\Models\StatisticFeatureResult;
use App\Models\StatisticFeatureRun;
use App\Models\StatisticFeatureRunItem;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class OfficialMethodHistoryBuildService
{
    public function __construct(private readonly OfficialMethodHistoryRepository $inputs, private readonly WindowBuilder $windows,
        private readonly Stat19OfficialMethodHistoryCalculator $stat19, private readonly Stat30OfficialMethodMixCalculator $stat30) {}

    public function build(int $raceId, DateTimeImmutable $asOf, bool $dryRun): array
    {
        return DB::transaction(function () use ($raceId, $asOf, $dryRun): array {
            $rows = $this->calculate($raceId, $asOf);
            // Re-read identities, metadata and Raw before publishing either statistic.
            $end = $this->calculate($raceId, $asOf);
            if (array_column($rows, 'hashes') !== array_column($end, 'hashes')) {
                throw new RuntimeException('Official history end integrity mismatch.');
            }
            $runs = [];
            foreach ([0, 1] as $position) {
                $prototype = $rows[0]['results'][$position];
                $run = $dryRun ? null : StatisticFeatureRun::query()->create([
                    'run_uuid' => (string) Str::uuid(), 'stat_code' => $prototype->statCode, 'calculation_version' => $prototype->version,
                    'mode' => 'BACKFILL', 'status' => 'PARTIALLY_SUCCEEDED', 'history_from' => '2022-01-01',
                    'target_race_id' => $raceId, 'input_as_of_policy' => 'MEETING_START_JST_RETROSPECTIVE_ONLY',
                    'parameters' => ['input_as_of' => $asOf->format(DATE_ATOM), 'prediction_use' => 'NOT_AUTHORIZED'],
                    'target_race_count' => 1, 'processed_race_count' => 1, 'target_entry_count' => count($rows),
                    'partial_count' => count($rows), 'started_at' => now(), 'finished_at' => now(),
                ]);
                foreach ($rows as $row) {
                    $entry = $row['entry'];
                    $result = $row['results'][$position];
                    if ($run !== null) {
                        StatisticFeatureResult::query()->create([
                            'feature_run_id' => $run->id, 'stat_code' => $result->statCode, 'calculation_version' => $result->version,
                            'subject_type' => 'RACE_ENTRY', 'subject_key' => 'race_entry:'.$entry['entry_id'],
                            'race_id' => $raceId, 'race_entry_id' => $entry['entry_id'], 'player_id' => $entry['player_id'],
                            'bike_number' => $entry['bike'], 'status' => $result->status->value, 'quality_status' => $result->qualityStatus->value,
                            'acquisition_mode' => 'BACKFILL', 'input_as_of' => $asOf,
                            'source_fetched_at' => $result->evidence['latest_fetched_at'], 'features' => $result->features,
                            'evidence' => $result->evidence, 'input_hash' => $result->inputHash, 'raw_points' => null,
                            'confidence' => null, 'effective_points' => null, 'calculated_at' => now(),
                        ]);
                    }
                }
                if ($run !== null) {
                    StatisticFeatureRunItem::query()->create(['feature_run_id' => $run->id, 'race_id' => $raceId,
                        'status' => 'PARTIAL', 'attempt_count' => 1, 'feature_result_count' => count($rows),
                        'started_at' => now(), 'finished_at' => now()]);
                }
                $runs[$prototype->statCode] = $run?->id;
            }

            return ['dry_run' => $dryRun, 'race_id' => $raceId, 'entries' => count($rows), 'feature_run_ids' => $runs,
                'features' => array_map(fn ($r) => ['entry' => $r['entry'], 'results' => array_map(fn ($v) => [
                    'stat_code' => $v->statCode, 'status' => $v->status->value, 'features' => $v->features,
                    'evidence' => $v->evidence, 'input_hash' => $v->inputHash], $r['results'])], $rows),
                'prediction_use' => 'NOT_AUTHORIZED'];
        });
    }

    private function calculate(int $raceId, DateTimeImmutable $asOf): array
    {
        $rows = [];
        foreach ($this->inputs->inputs($raceId, $asOf) as $input) {
            $profile = $this->windows->build($input);
            $results = [$this->stat19->calculate($profile), $this->stat30->calculate($profile)];
            $rows[] = ['entry' => $input->entry, 'results' => $results, 'hashes' => array_map(fn ($r) => $r->inputHash, $results)];
        }
        if ($rows === []) {
            throw new RuntimeException('No official history target entries.');
        }

        return $rows;
    }
}
