<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\GrowthPointAnalysis;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionPipeline\ReadOnlySession;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionResult\ResultStore;
use Generator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class HistorySource
{
    public function __construct(private readonly ReadOnlySession $session, private readonly ResultStore $writer) {}

    public function capture(Workspace $workspace, string $stage): array
    {
        return $this->session->run(function ($settings) use ($workspace, $stage): array {
            $digest = [];
            $expected = $this->writer->writeJsonl($stage, 'history.jsonl', $this->rows($workspace, $digest));

            return compact('settings', 'digest', 'expected');
        });
    }

    public function verify(Workspace $workspace, array $expected): array
    {
        return $this->session->run(function ($settings) use ($workspace, $expected): array {
            $digest = [];
            foreach ($this->rows($workspace, $digest) as $_) {
            }
            Files::same($expected, $digest, 'growth historical START/END');

            return ['settings' => $settings, 'digest' => $digest, 'status' => 'UNCHANGED'];
        });
    }

    private function rows(Workspace $workspace, array &$digest): Generator
    {
        $players = $workspace->db->query('SELECT DISTINCT player_id FROM targets WHERE player_id IS NOT NULL ORDER BY player_id')->fetchAll(\PDO::FETCH_COLUMN);
        $targetIds = $workspace->db->query('SELECT race_id FROM cohort ORDER BY race_id')->fetchAll(\PDO::FETCH_COLUMN);
        $last = $raceCount = $entryCount = 0;
        $hash = hash_init('sha256');
        while (true) {
            $races = DB::table('races as r')->leftJoin('race_days as d', 'd.id', '=', 'r.race_day_id')
                ->leftJoin('race_meetings as m', 'm.id', '=', 'd.race_meeting_id')
                ->whereBetween('r.race_date', ['2022-01-01', '2025-12-31'])
                ->where(function ($q): void {
                    $q->where('r.race_type', 'like', 'Ａ級%')->orWhere('r.race_type', 'like', 'Ｓ級%');
                })
                ->where(function ($q) use ($players, $targetIds): void {
                    $q->whereIntegerInRaw('r.id', $targetIds)->orWhereExists(function ($sub) use ($players): void {
                        $sub->selectRaw('1')->from('race_entries as e')->whereColumn('e.race_id', 'r.id')->whereIntegerInRaw('e.player_id', $players);
                    });
                })->where('r.id', '>', $last)->orderBy('r.id')->limit(200)
                ->select(['r.id', 'r.race_date', 'r.scheduled_start_at', 'r.entrant_count', 'r.result_status',
                    'd.race_date as day_date', 'm.id as meeting_id', 'm.starts_on', 'm.ends_on'])->get();
            if ($races->isEmpty()) {
                break;
            }
            $entries = DB::table('race_entries as e')->join('races as r', 'r.id', '=', 'e.race_id')
                ->leftJoin('race_results as rr', function ($join): void {
                    $join->on('rr.race_id', '=', 'e.race_id')->on('rr.bike_number', '=', 'e.bike_number');
                })->whereIntegerInRaw('r.id', $races->pluck('id')->all())->whereBetween('r.race_date', ['2022-01-01', '2025-12-31'])
                ->select(['e.id', 'e.race_id', 'e.player_id', 'e.bike_number', 'e.race_score', 'e.fetched_at as entry_fetched_at',
                    'rr.id as result_id', 'rr.race_entry_id as result_entry_id', 'rr.player_id as result_player_id',
                    'rr.rank', 'rr.result_status', 'rr.fetched_at as result_fetched_at'])->orderBy('e.race_id')->orderBy('e.id')->get()->groupBy('race_id');
            foreach ($races as $race) {
                $row = ['race_id' => (int) $race->id, 'year' => (int) substr($race->race_date, 0, 4), 'date' => $race->race_date,
                    'scheduled_start_at' => $race->scheduled_start_at, 'n' => (int) $race->entrant_count,
                    'race_status' => $race->result_status, 'meeting_id' => $race->meeting_id === null ? null : (int) $race->meeting_id,
                    'day_date' => $race->day_date, 'starts_on' => $race->starts_on, 'ends_on' => $race->ends_on, 'entries' => []];
                Contract::year($row['year']);
                foreach ($entries[$race->id] ?? [] as $entry) {
                    $row['entries'][] = ['id' => (int) $entry->id, 'player_id' => $entry->player_id === null ? null : (int) $entry->player_id,
                        'bike' => (int) $entry->bike_number, 'race_score' => $entry->race_score === null ? null : (string) $entry->race_score,
                        'rank' => $entry->rank === null ? null : (int) $entry->rank, 'status' => $entry->result_status,
                        'result_id' => $entry->result_id === null ? null : (int) $entry->result_id,
                        'result_entry_id' => $entry->result_entry_id === null ? null : (int) $entry->result_entry_id,
                        'result_player_id' => $entry->result_player_id === null ? null : (int) $entry->result_player_id,
                        'entry_fetched_at' => $entry->entry_fetched_at, 'result_fetched_at' => $entry->result_fetched_at];
                    $entryCount++;
                }
                if ($row['entries'] === [] && in_array($row['race_id'], $targetIds, true)) {
                    throw new RuntimeException('Missing target entries.');
                }
                hash_update($hash, Files::canonical($row)."\n");
                $raceCount++;
                yield $row;
            }
            $last = (int) $races->last()->id;
        }
        $digest = ['races' => $raceCount, 'entries' => $entryCount, 'sha256' => hash_final($hash)];
    }
}
