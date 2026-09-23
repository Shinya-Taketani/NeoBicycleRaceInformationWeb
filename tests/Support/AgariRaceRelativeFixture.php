<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Statistics\AgariRaceRelative\Artifacts;
use App\Domain\Keirin\Statistics\AgariRaceRelative\Contract;

/** Synthetic database-shaped records; no real rider, result or Raw is copied. */
final class AgariRaceRelativeFixture
{
    public static function race(int $id = 1, string $date = '2023-01-01', array $times = ['12.0', '12.00', '13', '14', '15', '16', '17']): array
    {
        $hash = hash('sha256', 'synthetic-source');
        $import = ['id' => $id, 'race_id' => $id, 'result_count' => count($times), 'import_status' => 'SUCCEEDED',
            'source_hash' => $hash, 'converted_hash' => $hash, 'source_url' => 'https://keirin.jp/pc/synthetic-test-only',
            'parser_version' => 'synthetic-source-v1', 'imported_at' => '2025-12-31 00:00:00+00',
            'requested_result_status' => 'CONFIRMED', 'parsed_page_status' => 'RESULTS_AVAILABLE'];
        $entries = [];
        foreach ($times as $i => $time) {
            $row = ['id' => $id * 10 + $i + 1, 'race_id' => $id, 'race_result_import_id' => $id,
                'race_entry_id' => null, 'player_id' => null, 'bike_number' => $i + 1, 'result_status' => 'FINISHED',
                'agari_time_seconds' => $time, 'agari_raw_text' => $time, 'agari_status' => $time === null ? 'MISSING' : 'VALID',
                'fetched_at' => '2025-12-31 00:00:00+00'];
            $observation = [...$row, 'external_player_id' => sprintf('%06d', $i + 1), 'source_url' => $import['source_url'],
                'parser_version' => 'AGARI-STORAGE-v1', 'created_at' => '2025-12-31 00:00:00+00',
                'metadata' => ['semantic' => 'AGARI_TIME', 'source_hash' => $hash, 'converted_hash' => $hash,
                    'source_parser_version' => 'synthetic-source-v1', 'normalizer_version' => 'AGARI-TIME-v1']];
            $entries[] = [...$row, 'import' => $import, 'observation' => $observation];
        }

        return ['schema' => Contract::VERSION, 'source' => 'keirin_jp', 'race_id' => $id, 'race_date' => $date,
            'race_status' => 'CONFIRMED', 'race_type' => 'A級予選', 'context' => [
                'race_number' => 1, 'entrant_count' => count($times), 'race_grade_raw' => 'F2',
                'race_day_id' => $id, 'racetrack_id' => 1, 'day_date' => $date, 'meeting_id' => $id,
                'meeting_grade_raw' => 'F2', 'starts_on' => $date, 'ends_on' => $date,
                'meeting_track_id' => 1, 'track_code' => '11', 'meeting_race_grade_raw_values' => ['F2']], 'results' => $entries];
    }

    public static function value(array &$race, int $offset, string $field, mixed $value): void
    {
        $race['results'][$offset][$field] = $value;
        $race['results'][$offset]['observation'][$field] = $value;
    }

    public static function input(string $directory, array $rows): void
    {
        Artifacts::create($directory);
        $seal = Artifacts::write($directory, 'races.jsonl', array_map(fn ($r) => Files::canonical($r)."\n", $rows));
        Artifacts::publish($directory, [...Contract::DISCLOSURE, 'kind' => 'INPUT', 'version' => Contract::VERSION,
            'source' => 'keirin_jp', 'order' => 'race_id_ASC', 'from' => '2022-01-01', 'to' => '2025-12-31',
            'race_count' => count($rows), 'result_count' => array_sum(array_map(fn ($r) => count($r['results']), $rows)),
            'files' => ['races.jsonl' => $seal]]);
    }
}
