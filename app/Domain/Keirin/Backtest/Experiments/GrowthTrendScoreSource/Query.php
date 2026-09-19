<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\GrowthTrendScoreSource;

use Generator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class Query
{
    private array $approvedQueries = [];

    public function build(array $players, array $targetIds, int $last, bool $targets = false): Builder
    {
        foreach ([...$players, ...$targetIds] as $id) {
            if (! is_int($id) || $id < 1) {
                throw new RuntimeException('Invalid query identity.');
            }
        }

        $query = DB::table('race_entries as e')->join('races as r', 'r.id', '=', 'e.race_id')
            ->leftJoin('race_days as d', 'd.id', '=', 'r.race_day_id')->leftJoin('race_meetings as m', 'm.id', '=', 'd.race_meeting_id')
            ->whereBetween('r.race_date', [$targets ? '2024-01-01' : '2022-01-01', '2025-12-31'])
            ->where(fn ($q) => $q->where('r.race_type', 'like', 'Ａ級%')->orWhere('r.race_type', 'like', 'Ｓ級%'))
            ->where(function ($q) use ($players, $targetIds): void {
                $q->whereIntegerInRaw('e.id', $targetIds);
                if ($players !== []) {
                    $q->orWhereIntegerInRaw('e.player_id', $players);
                }
            })->where('e.id', '>', $last)->orderBy('e.id')->limit(1000)->select(Contract::COLUMNS);
        // Register the exact generated SELECT, including predicates and bindings, before execution.
        $this->approvedQueries = [spl_object_id($query) => [$query->toSql(), $query->getBindings()]];

        return $query;
    }

    public function approved(Builder $query): void
    {
        $tables = [$query->from, ...array_map(fn ($j) => $j->table, $query->joins ?? [])];
        if (($this->approvedQueries[spl_object_id($query)] ?? null) !== [$query->toSql(), $query->getBindings()]
            || $tables !== ['race_entries as e', 'races as r', 'race_days as d', 'race_meetings as m']
            || $query->columns !== Contract::COLUMNS || $query->unions !== null || $query->limit !== 1000
            || $query->wheres[0]['type'] !== 'between' || $query->wheres[0]['column'] !== 'r.race_date'
            || ! in_array($query->wheres[0]['values'], [['2022-01-01', '2025-12-31'], ['2024-01-01', '2025-12-31']], true)) {
            throw new RuntimeException('Unapproved score query.');
        }
    }

    public function rows(array $players, array $ids, array &$audit, bool $targets = false): Generator
    {
        $last = 0;
        do {
            $query = $this->build($players, $ids, $last, $targets);
            $this->approved($query);
            $audit['select_queries'] = ($audit['select_queries'] ?? 0) + 1;
            $rows = $query->get();
            foreach ($rows as $object) {
                $row = (array) $object;
                foreach (['entry_id', 'race_id', 'player_id', 'bike', 'race_day_id', 'meeting_id'] as $key) {
                    $row[$key] = $row[$key] === null ? null : (int) $row[$key];
                }
                $row['race_score'] = $row['race_score'] === null ? null : (string) $row['race_score'];
                Workspace::validate($row);
                $last = $row['entry_id'];
                yield $row;
            }
        } while (count($rows) === 1000);
    }
}
