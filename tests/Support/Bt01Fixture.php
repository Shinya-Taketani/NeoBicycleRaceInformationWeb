<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Keirin\Backtest\DTO\SourceManifestEntryDto;
use Illuminate\Support\Facades\DB;

class Bt01Fixture
{
    /** @return list<SourceManifestEntryDto> */
    public static function manifest(): array
    {
        return [
            new SourceManifestEntryDto(2022, 25, '00000000-0000-4000-8000-000000000025', '2022-01-01', '2022-12-31', 2, 9),
            new SourceManifestEntryDto(2023, 26, '00000000-0000-4000-8000-000000000026', '2023-01-01', '2023-12-31', 1, 5),
            new SourceManifestEntryDto(2024, 1, '00000000-0000-4000-8000-000000000001', '2024-01-01', '2024-12-31', 1, 5),
            new SourceManifestEntryDto(2025, 27, '00000000-0000-4000-8000-000000000027', '2025-01-01', '2025-12-31', 1, 5),
        ];
    }

    /** @return array<string, int> */
    public static function seed(): array
    {
        foreach (self::manifest() as $entry) {
            DB::table('statistic_feature_runs')->insert([
                'id' => $entry->featureRunId,
                'run_uuid' => $entry->featureRunUuid,
                'stat_code' => 'STAT-01',
                'calculation_version' => 'STAT-01-existing-db-v1',
                'mode' => 'BACKFILL',
                'status' => 'PARTIALLY_SUCCEEDED',
                'history_from' => ($entry->year - 1).'-01-01',
                'target_from' => $entry->targetFrom,
                'target_to' => $entry->targetTo,
                'input_as_of_policy' => 'SALES_CLOSE_AT_THEN_SCHEDULED_START_AT',
                'parameters' => '{}',
                'target_race_count' => $entry->expectedRaceCount,
                'processed_race_count' => $entry->expectedRaceCount,
                'target_entry_count' => $entry->expectedResultCount,
                'error_count' => 0,
                'started_at' => now(),
                'finished_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $ids = [];
        $ids['normal_2022'] = self::race(2022, 25, true, 'CONFIRMED');
        $ids['partial_2022'] = self::race(2022, 25, false, 'CONFIRMED');
        $ids['abnormal_2023'] = self::race(2023, 26, true, 'CONFIRMED', abnormal: true);
        $ids['tied_2024'] = self::race(2024, 1, true, 'CONFIRMED', tied: true);
        $ids['unavailable_2025'] = self::race(2025, 27, true, 'UNAVAILABLE');

        return $ids;
    }

    private static function race(int $year, int $featureRunId, bool $completeFeatures, string $resultStatus, bool $abnormal = false, bool $tied = false): int
    {
        $date = "{$year}-06-01";
        $raceId = (int) DB::table('races')->insertGetId([
            'source' => 'bt01-test',
            'external_race_id' => "bt01-{$year}-{$featureRunId}-".($completeFeatures ? 'full' : 'partial'),
            'race_date' => $date,
            'race_number' => 1,
            'scheduled_start_at' => "{$date} 12:00:00",
            'sales_close_at' => "{$date} 11:55:00",
            'race_type' => 'Ａ級予選',
            'entrant_count' => 5,
            'result_status' => $resultStatus,
            'result_available' => $resultStatus === 'CONFIRMED',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $scores = [100, 90, 80, 70, 60];
        $featureCount = $completeFeatures ? 5 : 4;
        foreach (range(1, $featureCount) as $bike) {
            $entryId = ($raceId * 10) + $bike;
            DB::table('statistic_feature_results')->insert([
                'feature_run_id' => $featureRunId,
                'stat_code' => 'STAT-01',
                'calculation_version' => 'STAT-01-existing-db-v1',
                'subject_type' => 'RACE_ENTRY',
                'subject_key' => 'race_entry:'.$entryId,
                'race_id' => $raceId,
                'race_entry_id' => $entryId,
                'player_id' => 100000 + $entryId,
                'bike_number' => $bike,
                'status' => 'VALID',
                'quality_status' => 'FULL',
                'acquisition_mode' => 'BACKFILL',
                'input_as_of' => "{$date} 11:55:00",
                'features' => json_encode([
                    'RACE_SCORE_RAW' => $scores[$bike - 1],
                    'RACE_SCORE_AVAILABLE' => true,
                    'RACE_SCORE_RANK' => $bike,
                ], JSON_THROW_ON_ERROR),
                'evidence' => '{}',
                'input_hash' => hash('sha256', "{$featureRunId}:{$entryId}"),
                'calculated_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        foreach (range(1, 5) as $bike) {
            $rank = $tied && $bike <= 2 ? 1 : ($tied ? $bike : $bike);
            $status = $tied && $bike <= 2 ? 'TIED' : 'FINISHED';
            if ($abnormal && $bike === 5) {
                $rank = null;
                $status = 'DISQUALIFIED';
            }
            DB::table('race_results')->insert([
                'race_id' => $raceId,
                'bike_number' => $bike,
                'rank' => $rank,
                'result_status' => $status,
                'fetched_at' => "{$date} 13:00:00",
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $raceId;
    }
}
