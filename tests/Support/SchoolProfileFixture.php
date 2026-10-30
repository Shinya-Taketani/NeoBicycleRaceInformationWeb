<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Keirin\Statistics\Support\SchoolProfiles\Files;

final class SchoolProfileFixture
{
    // Synthetic school records only; never evidence of real player achievement.
    public static function profile(string $person = '000001', int $log = 1, ?string $debut = '2010-01-01', string $at = '2026-07-01T00:00:00+09:00'): array
    {
        return ['version' => 'school-profile-projection-v1', 'external_player_id' => $person, 'graduation_period' => 100,
            'STAT-02' => ['school_rank_raw' => 5, 'official_cohort_size' => null, 'cohort_percentile' => null, 'status' => 'PARTIAL'],
            'STAT-03' => ['school_200m_seconds' => 11.0, 'cohort_percentile' => null, 'status' => 'PARTIAL'],
            'debut_date' => $debut, 'original_fields' => ['競輪学校順位' => '5位', '200mタイム' => '0分11秒00',
                'debut_date_raw' => $debut === null ? null : str_replace('-', '/', $debut)],
            'source' => ['fetch_log_id' => $log, 'fetched_at' => $at, 'sha256' => str_repeat('a', 64), 'bytes' => 42, 'parser_version' => 'synthetic-school-v1'],
            'data_role' => 'RETROSPECTIVE_ONLY', 'historical_as_of_available' => false, 'prediction_use' => 'NOT_AUTHORIZED',
            'points' => null, 'confidence' => null];
    }

    public static function manifest(string $root, array $profiles): array
    {
        mkdir($root, 0700);
        $sourceRows = [];
        $projectionLines = '';
        foreach ($profiles as $p) {
            $s = $p['source'];
            $sourceRows[$s['fetch_log_id']] ??= $s + ['external_player_id' => $p['external_player_id'],
                'source_url' => 'https://keirin.jp/pc/racerprofile?snum='.$p['external_player_id'], 'raw_file_path' => 'synthetic/source.html'];
            $projectionLines .= Files::json($p);
        }
        Files::write($root.'/projections.jsonl', $projectionLines);
        Files::write($root.'/sources.jsonl', implode('', array_map(Files::json(...), $sourceRows)));
        Files::write($root.'/executed-artifacts.json', Files::json(['profiles' => ['files' => [
            'sources.jsonl' => Files::seal($root.'/sources.jsonl'), 'projections.jsonl' => Files::seal($root.'/projections.jsonl')]]]));
        Files::write($root.'/manifest.json', Files::json(['contract' => 'STAT-EXISTING-DATA-COMPLETION-REVIEW-v1',
            'files' => ['executed-artifacts.json' => Files::seal($root.'/executed-artifacts.json')]]));
        $seal = Files::seal($root.'/manifest.json');
        Files::write($root.'/COMPLETE.json', Files::json(['manifest_sha256' => $seal['sha256'], 'exit_code' => 0]));

        return $seal;
    }
}
