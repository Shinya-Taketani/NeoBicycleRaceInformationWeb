<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\GrowthTrendAnalysis;

final class Audit
{
    public function run(Workspace $w, Statistics $stats): array
    {
        $insert = $w->db->prepare('INSERT INTO audit_values VALUES(?,?)');
        $last = $lastChange = null;
        $same = $since = 0;
        $yearChanges = [];
        $w->db->beginTransaction();
        $flush = function () use (&$same, &$yearChanges, $insert): void {
            if ($same > 0) {
                $insert->execute(['same_score_run_meetings', $same]);
            }
            foreach ($yearChanges as $n) {
                $insert->execute(['player_year_score_changes', $n]);
            }
            $yearChanges = [];
        };
        foreach ($w->db->query('SELECT body FROM meetings ORDER BY player_id,order_date,meeting_id') as $r) {
            $m = json_decode($r['body'], true, flags: JSON_THROW_ON_ERROR);
            if ($last === null || $last['player_id'] !== $m['player_id']) {
                $flush();
                $last = $lastChange = null;
                $same = $since = 0;
            }
            $year = substr($m['order_date'], 0, 4);
            $yearChanges[$year] ??= 0;
            if ($m['score'] === null) {
                if ($same > 0) {
                    $insert->execute(['same_score_run_meetings', $same]);
                }
                $same = $since = 0;
                $lastChange = null;
                $last = $m;

                continue;
            }
            if ($last !== null && $last['start'] !== null && $m['start'] !== null) {
                $insert->execute(['days_between_meetings', Trend::day($m['start']) - Trend::day($last['start'])]);
            }
            if ($last !== null && $last['score'] !== null && $m['score'] !== $last['score']) {
                $insert->execute(['same_score_run_meetings', $same]);
                $insert->execute(['score_change_magnitude', ($m['score'] - $last['score']) / 100.0]);
                $yearChanges[$year]++;
                if ($lastChange !== null) {
                    $insert->execute(['meetings_between_score_changes', $since + 1]);
                    if ($lastChange['start'] !== null && $m['start'] !== null) {
                        $insert->execute(['days_between_score_changes', Trend::day($m['start']) - Trend::day($lastChange['start'])]);
                    }
                }
                $lastChange = $m;
                $same = 1;
                $since = 0;
            } else {
                $same++;
                $since++;
            }
            $last = $m;
        }
        $flush();
        $w->db->commit();
        $distributions = [];
        foreach ($w->db->query('SELECT DISTINCT kind FROM audit_values ORDER BY kind') as $row) {
            $distributions[$row['kind']] = $stats->distribution('audit_values WHERE kind=?', [$row['kind']], 'value');
        }
        $counts = $w->db->query('SELECT count(*) AS observations,count(DISTINCT player_id) AS players,count(DISTINCT meeting_id) AS meetings,sum(score IS NULL) AS missing_score FROM observations')->fetch();
        $drift = $w->db->query("SELECT count(*) AS player_meetings,sum(json_extract(body,'$.intra_meeting_score_drift')) AS drifting,
            sum(json_extract(body,'$.status')='PARTIAL_TIME_ORDER') AS partial_order FROM meetings")->fetch();

        return ['years' => [2022, 2023, 2024, 2025], 'counts' => $counts, 'missing_score_rate' => $counts['missing_score'] / max(1, $counts['observations']),
            'drift' => $drift, 'drift_rate' => $drift['drifting'] / max(1, $drift['player_meetings']), 'distributions' => $distributions,
            'meaning' => 'SCORE_OBSERVATIONS_NOT_STARTED_RACES'];
    }
}
