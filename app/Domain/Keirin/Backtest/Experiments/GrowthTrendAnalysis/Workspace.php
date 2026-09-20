<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\GrowthTrendAnalysis;

use App\Domain\Keirin\Backtest\Experiments\GrowthTrendScoreSource\Contract;
use App\Domain\Keirin\Backtest\Experiments\GrowthTrendScoreSource\Workspace as ScoreWorkspace;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalMeetingGradeAnalysis\Classification;
use Generator;
use RuntimeException;

final class Workspace extends ScoreWorkspace
{
    public function __construct(string $path)
    {
        parent::__construct($path);
        $this->db->exec('CREATE TABLE meetings(player_id INTEGER,meeting_id INTEGER,order_date TEXT,last_date TEXT,body TEXT,PRIMARY KEY(player_id,meeting_id));
            CREATE TABLE metadata(race_id INTEGER PRIMARY KEY,year INTEGER,grade TEXT,class TEXT,body TEXT);
            CREATE TABLE entries(entry_id INTEGER PRIMARY KEY,race_id INTEGER,year INTEGER,player_id INTEGER,bike INTEGER,score INTEGER,p1 REAL,margin REAL,predicted INTEGER,first_obs INTEGER,grade TEXT,class TEXT,n INTEGER,score_bin INTEGER,p1_bin INTEGER,margin_bin INTEGER,normal INTEGER,rank INTEGER,fp REAL,unique_winner INTEGER);
            CREATE TABLE signals(entry_id INTEGER,candidate TEXT,raw REAL,state TEXT,PRIMARY KEY(candidate,entry_id)) WITHOUT ROWID;
            CREATE INDEX entries_race ON entries(race_id);
            CREATE INDEX entries_year ON entries(year);
            CREATE TABLE audit_values(kind TEXT,value REAL);
            CREATE TABLE day_exclusions(year INTEGER,candidate TEXT,excluded_missing_start_meetings INTEGER,targets_with_excluded_missing_start INTEGER,PRIMARY KEY(year,candidate));
            CREATE INDEX audit_dist ON audit_values(kind,value)');
    }

    public function representatives(Trend $trend): void
    {
        $q = $this->db->prepare('INSERT INTO meetings VALUES(?,?,?,?,?)');
        $audit = $this->db->prepare('INSERT INTO audit_values VALUES(?,?)');
        $this->db->beginTransaction();
        $rows = [];
        $key = null;
        $flush = function () use (&$rows, $trend, $q, $audit): void {
            if ($rows === []) {
                return;
            }
            $m = $trend->representative($rows);
            $q->execute([$m['player_id'], $m['meeting_id'], $m['order_date'], $m['last_date'], Files::canonical($m)]);
            $audit->execute(['observations_per_player_meeting', $m['observation_count']]);
            $rows = [];
        };
        foreach ($this->db->query('SELECT * FROM observations WHERE player_id IS NOT NULL AND meeting_id IS NOT NULL ORDER BY player_id,meeting_id,date,ts,entry_id') as $row) {
            $new = $row['player_id'].':'.$row['meeting_id'];
            if ($key !== $new) {
                $flush();
                $key = $new;
            }
            $rows[] = $row;
        }
        $flush();
        $this->db->commit();
    }

    public function metadata(string $path): void
    {
        $groups = Files::json($path.'/meetings.json');
        $q = $this->db->prepare('INSERT INTO metadata VALUES(?,?,?,?,?)');
        $this->db->beginTransaction();
        foreach (JsonlArtifact::read($path.'/metadata.jsonl') as $row) {
            Classification::validate($row);
            $meeting = $groups[(string) ($row['meeting_id'] ?? 'missing:'.$row['race_id'])] ?? null;
            if ($meeting === null) {
                throw new RuntimeException('Missing fixed meeting classification.');
            }
            $q->execute([$row['race_id'], $row['year'], $meeting['grade'], Classification::raceClass($row['race_type_raw']), Files::canonical($row)]);
        }
        $this->db->commit();
    }

    public function inputs(array $outer, Trend $trend): Generator
    {
        $get = $this->db->prepare('SELECT o.*,t.year,m.grade,m.class,m.body AS metadata FROM targets t JOIN observations o ON o.entry_id=t.entry_id JOIN metadata m ON m.race_id=t.race_id WHERE t.entry_id=?');
        $history = $this->db->prepare('SELECT body FROM meetings WHERE player_id=? AND order_date<? AND last_date<? AND meeting_id<>? ORDER BY order_date DESC,meeting_id');
        $same = $this->db->prepare('SELECT * FROM observations WHERE player_id=? AND meeting_id=?');
        $insert = $this->db->prepare('INSERT INTO entries(entry_id,race_id,year,player_id,bike,score,p1,margin,predicted,first_obs,grade,class,n) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $signal = $this->db->prepare('INSERT INTO signals VALUES(?,?,?,?)');
        $exclusions = $this->db->prepare('INSERT INTO day_exclusions VALUES(?,?,?,?) ON CONFLICT(year,candidate) DO UPDATE SET
            excluded_missing_start_meetings=excluded_missing_start_meetings+excluded.excluded_missing_start_meetings,
            targets_with_excluded_missing_start=targets_with_excluded_missing_start+excluded.targets_with_excluded_missing_start');
        $n = 0;
        $this->db->beginTransaction();
        foreach ($outer['years'] as $year => $paths) {
            Contract::year($year, true);
            $races = $entries = 0;
            foreach (JsonlArtifact::read($paths['prediction']) as $prediction) {
                $race = $prediction['probabilities'];
                $decision = $prediction['decision'];
                if ($race['year'] !== $year || $decision['race_id'] !== $race['race_id'] || $decision['year'] !== $year) {
                    throw new RuntimeException('Prediction identity mismatch.');
                }
                $probabilities = array_column($race['entries'], 'position_1_probability');
                if (count($probabilities) < 5 || count($probabilities) > 9
                    || ! in_array($decision['primary_position_1_bike'], array_column($race['entries'], 'bike'), true)) {
                    throw new RuntimeException('Invalid prediction entrant/decision set.');
                }
                foreach ($probabilities as $p) {
                    if (! is_numeric($p) || ! is_finite((float) $p) || $p < 0 || $p > 1) {
                        throw new RuntimeException('Invalid P1 probability.');
                    }
                }
                rsort($probabilities, SORT_NUMERIC);
                $margin = $probabilities[0] - $probabilities[1];
                $races++;
                foreach ($race['entries'] as $entry) {
                    $get->execute([$entry['id']]);
                    $t = $get->fetch();
                    if ($t === false || $t['race_id'] !== $race['race_id'] || $t['year'] !== $year || $t['bike'] !== $entry['bike']) {
                        throw new RuntimeException('Prediction/score target mismatch.');
                    }
                    $meta = json_decode($t['metadata'], true, flags: JSON_THROW_ON_ERROR);
                    if ($meta['meeting_id'] !== $t['meeting_id'] || $meta['race_date'] !== $t['date']) {
                        throw new RuntimeException('Fixed meeting/source identity drift.');
                    }
                    $target = array_intersect_key($t, array_flip(['entry_id', 'race_id', 'player_id', 'bike', 'date', 'ts', 'meeting_id', 'start', 'score']));
                    $anchor = $t['start'] ?? $t['date'];
                    $history->execute([$t['player_id'], $anchor, $anchor, $t['meeting_id']]);
                    $meetings = [];
                    foreach ($history as $m) {
                        $meetings[] = json_decode($m['body'], true, flags: JSON_THROW_ON_ERROR);
                    }
                    $growth = $trend->calculate($target, $meetings);
                    foreach ($growth['day_exclusions'] as $id => $excluded) {
                        $exclusions->execute([$year, $id, $excluded, (int) ($excluded > 0)]);
                    }
                    $same->execute([$t['player_id'], $t['meeting_id']]);
                    $first = $t['player_id'] === null || $t['meeting_id'] === null ? null : $trend->firstObservation($target, $same->fetchAll());
                    $p1 = (float) $entry['position_1_probability'];
                    $insert->execute([$t['entry_id'], $t['race_id'], $year, $t['player_id'], $t['bike'], $t['score'], sprintf('%.17g', $p1), sprintf('%.17g', $margin),
                        $t['bike'] === $decision['primary_position_1_bike'] ? 1 : 0, $first === null ? null : (int) $first, $t['grade'], $t['class'], count($race['entries'])]);
                    foreach ($growth['candidates'] as $id => $value) {
                        $signal->execute([$t['entry_id'], $id, $value['raw'] === null ? null : sprintf('%.17g', $value['raw']), $value['status']]);
                    }
                    foreach (['LAST_SCORE_CHANGE_DELTA', 'MEETINGS_SINCE_LAST_SCORE_CHANGE', 'DAYS_SINCE_LAST_SCORE_CHANGE'] as $id) {
                        $raw = $growth['events'][$id];
                        $signal->execute([$t['entry_id'], $id, $raw === null ? null : sprintf('%.17g', $raw), $raw === null ? $growth['events']['level_status'] : 'VALID']);
                    }
                    $entries++;
                    yield ['year' => $year, 'race_id' => $t['race_id'], 'entry_id' => $t['entry_id'], 'player_id' => $t['player_id'], 'bike' => $t['bike'],
                        'target_date' => $t['date'], 'target_meeting_id' => $t['meeting_id'], 'target_score_hundredths' => $t['score'],
                        'first_score_observation_in_target_meeting' => $first,
                        'previous_meeting_representatives' => array_map(fn ($m) => array_intersect_key($m, array_flip(['meeting_id', 'start', 'representative_entry_ids', 'score', 'status'])), $growth['previous']),
                        'candidates' => $growth['candidates'], 'score_change_diagnostics' => $growth['events'], 'c1_p1_probability' => $p1, 'c1_p1_margin' => $margin];
                    if (++$n % 1000 === 0) {
                        $this->db->commit();
                        $this->db->beginTransaction();
                    }
                }
            }
            if ($races !== $outer['counts'][$year] || $entries !== $outer['entries'][$year]) {
                throw new RuntimeException('Prediction universe count mismatch.');
            }
        }
        $this->db->commit();
    }

    public function outcomes(array $outer, TemporalAccess $access, \App\Domain\Keirin\Backtest\Experiments\GrowthTrendScoreSource\OuterSource $reader): void
    {
        $get = $this->db->prepare('SELECT * FROM entries WHERE race_id=? ORDER BY entry_id');
        $q = $this->db->prepare('UPDATE entries SET normal=?,rank=?,fp=?,unique_winner=? WHERE entry_id=?');
        foreach ($outer['years'] as $year => $paths) {
            $n = $count = 0;
            $this->db->beginTransaction();
            foreach ($access->outcomes($year, $outer['root'], $reader) as $race) {
                if ($race['year'] !== $year) {
                    throw new RuntimeException('Outcome year mismatch.');
                }
                $get->execute([$race['race_id']]);
                $target = array_column($get->fetchAll(), null, 'entry_id');
                if (count($target) !== count($race['entries']) || $target === []) {
                    throw new RuntimeException('Outcome race universe mismatch.');
                }
                $unique = count(array_filter($race['entries'], fn ($e) => $e['rank'] === 1)) === 1;
                $seen = [];
                foreach ($race['entries'] as $entry) {
                    $t = $target[$entry['id']] ?? null;
                    if ($t === null || $t['bike'] !== $entry['bike'] || $t['year'] !== $year || $t['normal'] !== null || isset($seen[$entry['id']])) {
                        throw new RuntimeException('Outcome entry identity/duplicate mismatch.');
                    }
                    $seen[$entry['id']] = true;
                    $normal = in_array($entry['status'], ['FINISHED', 'TIED'], true);
                    if (! in_array($entry['status'], ['FINISHED', 'TIED', 'DISQUALIFIED', 'DID_NOT_START', 'DID_NOT_FINISH', 'WITHDRAWN', 'CRASHED', 'UNKNOWN'], true)) {
                        throw new RuntimeException('Unknown outcome status.');
                    }
                    if (($normal && (! is_int($entry['rank']) || $entry['rank'] < 1 || $entry['rank'] > count($target)))
                        || (! $normal && $entry['rank'] !== null)) {
                        throw new RuntimeException('Invalid outcome rank.');
                    }
                    $fp = $normal ? (count($target) - $entry['rank']) / (count($target) - 1) : null;
                    $q->execute([(int) $normal, $entry['rank'], $fp === null ? null : sprintf('%.17g', $fp), (int) $unique, $entry['id']]);
                    $count++;
                }
                $n++;
            }
            $this->db->commit();
            if ($n !== $outer['counts'][$year] || $count !== $outer['entries'][$year]) {
                throw new RuntimeException('Incomplete outcome universe.');
            }
        }
    }
}
