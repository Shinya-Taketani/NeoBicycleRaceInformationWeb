<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Repositories;

use App\Domain\Keirin\Backtest\DTO\Bt02OutcomeContextSourceRowDto;
use App\Domain\Keirin\Backtest\DTO\SourceManifestEntryDto;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class Bt02OutcomeContextSnapshotSourceRepository
{
    /** @return iterable<Bt02OutcomeContextSourceRowDto> */
    public function rows(SourceManifestEntryDto $source): iterable
    {
        $targetRaces = DB::table('statistic_feature_results')
            ->select('race_id')
            ->where('feature_run_id', $source->featureRunId)
            ->distinct();

        $query = DB::query()
            ->fromSub($targetRaces, 'target_races')
            ->leftJoin('races as races', 'races.id', '=', 'target_races.race_id')
            ->leftJoin('race_results as race_results', 'race_results.race_id', '=', 'races.id')
            ->select([
                'target_races.race_id as target_race_id',
                'races.id as race_id',
                'races.race_date',
                'races.scheduled_start_at',
                'races.sales_close_at',
                'races.entrant_count',
                'races.result_status as race_status',
                'races.race_type',
                'race_results.bike_number',
                'race_results.rank',
                'race_results.result_status',
            ])
            ->orderBy('races.race_date')
            ->orderBy('races.id')
            ->orderBy('race_results.bike_number');

        foreach ($query->cursor() as $row) {
            if ($row->race_id === null) {
                throw new RuntimeException(
                    "Fixed STAT-01 source race was missing: year {$source->year}, feature_run_id {$source->featureRunId}, race_id {$row->target_race_id}.",
                );
            }
            yield new Bt02OutcomeContextSourceRowDto(
                raceId: (int) $row->race_id,
                raceDate: (string) $row->race_date,
                scheduledStartAt: $row->scheduled_start_at !== null ? (string) $row->scheduled_start_at : null,
                salesCloseAt: $row->sales_close_at !== null ? (string) $row->sales_close_at : null,
                entrantCount: (int) $row->entrant_count,
                raceStatus: (string) $row->race_status,
                raceType: (string) $row->race_type,
                bikeNumber: $row->bike_number !== null ? (int) $row->bike_number : null,
                rank: $row->rank !== null ? (int) $row->rank : null,
                resultStatus: $row->result_status !== null ? (string) $row->result_status : null,
            );
        }
    }
}
