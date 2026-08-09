<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Repositories;

use App\Domain\Keirin\Backtest\DTO\FoldDefinitionDto;
use App\Domain\Keirin\Backtest\DTO\RaceContextDto;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

class BacktestContextRepository
{
    /** @return \Generator<int, list<RaceContextDto>> */
    public function chunks(FoldDefinitionDto $fold, int $chunkSize): \Generator
    {
        $lastId = 0;
        while (true) {
            $rows = DB::table('races')
                ->select(['id', 'race_date', 'scheduled_start_at', 'sales_close_at', 'entrant_count', 'result_status'])
                ->where('id', '>', $lastId)
                ->whereBetween('entrant_count', [5, 9])
                ->where(function ($query): void {
                    $query->where('race_type', 'like', 'Ａ級%')->orWhere('race_type', 'like', 'Ｓ級%');
                })
                ->whereDate('race_date', '>=', $fold->evaluationFrom->format('Y-m-d'))
                ->whereDate('race_date', '<=', $fold->evaluationTo->format('Y-m-d'))
                ->orderBy('id')
                ->limit($chunkSize)
                ->get();
            if ($rows->isEmpty()) {
                return;
            }

            yield $rows->map(fn (object $row): RaceContextDto => new RaceContextDto(
                raceId: (int) $row->id,
                raceDate: new DateTimeImmutable((string) $row->race_date),
                scheduledStartAt: $row->scheduled_start_at !== null ? new DateTimeImmutable((string) $row->scheduled_start_at) : null,
                salesCloseAt: $row->sales_close_at !== null ? new DateTimeImmutable((string) $row->sales_close_at) : null,
                entrantCount: (int) $row->entrant_count,
                resultStatus: (string) $row->result_status,
            ))->all();
            $lastId = (int) $rows->last()->id;
            if ($rows->count() < $chunkSize) {
                return;
            }
        }
    }
}
