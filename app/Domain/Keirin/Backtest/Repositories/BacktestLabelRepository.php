<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Repositories;

use App\Domain\Keirin\Backtest\DTO\LabelResultDto;
use Illuminate\Support\Facades\DB;

class BacktestLabelRepository
{
    /** @param list<int> $raceIds @return array<int, list<LabelResultDto>> */
    public function forRaces(array $raceIds): array
    {
        if ($raceIds === []) {
            return [];
        }
        $grouped = [];
        foreach (DB::table('race_results')->select(['race_id', 'bike_number', 'rank', 'result_status'])->whereIn('race_id', $raceIds)->orderBy('race_id')->orderBy('bike_number')->get() as $row) {
            $grouped[(int) $row->race_id][] = new LabelResultDto(
                raceId: (int) $row->race_id,
                bikeNumber: (int) $row->bike_number,
                rank: $row->rank !== null ? (int) $row->rank : null,
                resultStatus: (string) $row->result_status,
            );
        }

        return $grouped;
    }
}
