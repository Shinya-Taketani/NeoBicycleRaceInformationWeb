<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\TacticalHistory;

use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;

final class SourceVerifier
{
    public function __construct(private readonly HistoryReader $history) {}

    public function verify(string $directory): array
    {
        $manifest = json_decode(file_get_contents($directory.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        if (hash_file('sha256', $directory.'/history-cache.sqlite') !== $manifest['history_cache_sha256']) {
            throw new RuntimeException('History cache file drifted.');
        }
        $index = json_decode(file_get_contents($directory.'/history-entry-index.json'), true, flags: JSON_THROW_ON_ERROR);
        $hash = hash_init('sha256');
        $count = 0;
        foreach (DB::table('race_entries as e')->join('races as r', 'r.id', '=', 'e.race_id')
            ->whereBetween('r.race_date', ['2022-01-01', '2025-12-31'])->whereNotNull('e.player_id')
            ->select(['e.id', 'e.player_id', 'r.race_date'])->lazyById(1000, 'e.id', 'id') as $row) {
            hash_update($hash, json_encode([(int) $row->id, (int) $row->player_id, $row->race_date], JSON_THROW_ON_ERROR)."\n");
            $count++;
        }
        if ($count !== $index['rows'] || hash_final($hash) !== $index['sha256']) {
            throw new RuntimeException('History entry identities changed after the input snapshot.');
        }
        $cache = new PDO('sqlite:file:'.$directory.'/history-cache.sqlite?mode=ro');
        $cache->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->history->useEntryIndex($cache);
        $windows = 0;
        foreach ($cache->query('SELECT cache_key,audit FROM windows ORDER BY cache_key') as $row) {
            [$player, $meeting, $start] = explode(':', $row['cache_key']);
            $cached = json_decode($row['audit'], true, flags: JSON_THROW_ON_ERROR);
            $current = iterator_to_array($this->history->rows((int) $player, $start, (int) $meeting));
            if ($current !== $cached['source_rows']) {
                throw new RuntimeException('History source drifted for window '.$row['cache_key']);
            }
            $windows++;
            if ($windows % 1000 === 0) {
                echo json_encode(['phase' => 'SOURCE_END_WINDOWS', 'verified' => $windows])."\n";
            }
        }
        $targets = 0;
        foreach ([2022, 2023, 2024, 2025] as $year) {
            $chunk = [];
            foreach (JsonlArtifact::read($directory.'/history-'.$year.'.jsonl') as $row) {
                $chunk[] = $row['target'];
                $targets++;
                if (count($chunk) === 200) {
                    $this->verifyTargets($chunk, $year);
                    $chunk = [];
                }
            }
            if ($chunk !== []) {
                $this->verifyTargets($chunk, $year);
            }
            foreach (JsonlArtifact::read($directory.'/inputs-'.$year.'.jsonl') as $_) {
                // Exhaust each input stream to validate its content seal.
            }
        }

        return ['status' => 'VERIFIED_CURRENT_STATE_AGAINST_INPUT_SNAPSHOT', 'identity_rows' => $count,
            'windows' => $windows, 'targets' => $targets, 'production_writes' => 0];
    }

    private function verifyTargets(array $targets, int $year): void
    {
        $current = DB::table('races as r')->leftJoin('race_days as d', 'd.id', '=', 'r.race_day_id')
            ->leftJoin('race_meetings as m', 'm.id', '=', 'd.race_meeting_id')->join('race_entries as e', 'e.race_id', '=', 'r.id')
            ->whereIn('e.id', array_column($targets, 'entry_id'))->whereBetween('r.race_date', [$year.'-01-01', $year.'-12-31'])
            ->select(['r.id as race_id', 'r.race_date', 'm.id as meeting_id', 'm.starts_on as meeting_start', 'm.ends_on as meeting_end',
                'e.id as entry_id', 'e.player_id', 'e.bike_number as bike'])->get()->keyBy('entry_id');
        foreach ($targets as $target) {
            $row = $current->get($target['entry_id']);
            if ($row === null) {
                if ($target['entry_id'] !== null) {
                    throw new RuntimeException('Target entry disappeared after input generation.');
                }

                continue;
            }
            $values = (array) $row;
            foreach (['race_id', 'meeting_id', 'entry_id', 'player_id', 'bike'] as $key) {
                $values[$key] = $values[$key] === null ? null : (int) $values[$key];
            }
            foreach ($values as $key => $value) {
                if ($value !== ($target[$key] ?? null)) {
                    throw new RuntimeException('Target metadata changed after input generation at entry '.$target['entry_id']);
                }
            }
        }
    }
}
