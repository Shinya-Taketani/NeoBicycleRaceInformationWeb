<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Repositories;

use App\Domain\Keirin\Backtest\DTO\Bt02OutcomeContextSourceRowDto;
use Illuminate\Support\Facades\DB;

class Bt02OutcomeContextSnapshotSourceRepository
{
    /** @return iterable<Bt02OutcomeContextSourceRowDto> */
    public function rows(): iterable
    {
        $query = DB::table('races as races')
            ->leftJoin('race_results as race_results', 'race_results.race_id', '=', 'races.id')
            ->select([
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
            ->whereBetween('races.race_date', ['2022-01-01', '2025-12-31'])
            ->whereBetween('races.entrant_count', [5, 9])
            ->where(function ($query): void {
                $query->where('races.race_type', 'like', 'Ａ級%')
                    ->orWhere('races.race_type', 'like', 'Ｓ級%');
            })
            ->orderBy('races.race_date')
            ->orderBy('races.id')
            ->orderBy('race_results.bike_number');

        foreach ($query->cursor() as $row) {
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
