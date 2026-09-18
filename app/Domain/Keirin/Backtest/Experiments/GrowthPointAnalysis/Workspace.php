<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\GrowthPointAnalysis;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use Generator;
use PDO;
use RuntimeException;

final class Workspace
{
    public readonly PDO $db;

    public function __construct(string $path)
    {
        if (file_exists($path)) {
            throw new RuntimeException('Workspace must be new.');
        }
        $this->db = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $this->db->exec('PRAGMA cache_size=-4096; PRAGMA temp_store=FILE; PRAGMA journal_mode=DELETE');
        $this->db->exec('CREATE TABLE cohort(race_id INTEGER PRIMARY KEY, year INTEGER, body TEXT NOT NULL);
            CREATE TABLE targets(id INTEGER PRIMARY KEY, race_id INTEGER, player_id INTEGER, bike INTEGER, UNIQUE(race_id,bike), UNIQUE(race_id,player_id));
            CREATE INDEX target_players ON targets(player_id);
            CREATE TABLE history(id INTEGER PRIMARY KEY,race_id INTEGER,player_id INTEGER,year INTEGER,date TEXT,ts INTEGER,meeting_id INTEGER,
                score INTEGER,residual REAL,started INTEGER,normal INTEGER,rank INTEGER,n INTEGER,status TEXT,race_status TEXT,bike INTEGER,
                UNIQUE(race_id,bike),UNIQUE(race_id,player_id));
            CREATE INDEX previous_history ON history(player_id,date DESC,ts DESC);
            CREATE TABLE training(year INTEGER,signal TEXT,raw REAL);
            CREATE INDEX threshold_distribution ON training(signal,year,raw);
            CREATE TABLE observations(year INTEGER,race_id INTEGER,entry_id INTEGER,player_id INTEGER,grade TEXT,class TEXT,same TEXT,
                signal TEXT,raw REAL,point INTEGER,state TEXT,normal INTEGER,rank INTEGER,fp REAL,predicted INTEGER,unique_winner INTEGER,
                UNIQUE(entry_id,signal));
            CREATE INDEX observation_strata ON observations(year,signal,grade,class,same);
            CREATE INDEX observation_points ON observations(year,signal,point);
            CREATE INDEX observation_race ON observations(race_id,signal,predicted)');
    }

    public function cohort(iterable $rows): void
    {
        $race = $this->db->prepare('INSERT INTO cohort VALUES(?,?,?)');
        $entry = $this->db->prepare('INSERT INTO targets VALUES(?,?,?,?)');
        $this->db->beginTransaction();
        foreach ($rows as $row) {
            if (! in_array($row['context']['year'], Contract::YEARS, true) || $row['context']['race_id'] < 1) {
                throw new RuntimeException('Invalid cohort identity/year.');
            }
            $race->execute([$row['context']['race_id'], $row['context']['year'], Files::canonical($row)]);
            foreach ($row['targets'] as $target) {
                if (! is_int($target['id']) || $target['id'] < 1 || ! is_int($target['bike']) || $target['bike'] < 1 || $target['bike'] > 9
                    || ($target['player_id'] !== null && (! is_int($target['player_id']) || $target['player_id'] < 1))) {
                    throw new RuntimeException('Invalid cohort entry/player identity.');
                }
                $entry->execute([$target['id'], $row['context']['race_id'], $target['player_id'], $target['bike']]);
            }
        }
        $this->db->commit();
    }

    public function history(iterable $races): void
    {
        $insert = $this->db->prepare('INSERT INTO history VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $this->db->beginTransaction();
        foreach ($races as $race) {
            Contract::year($race['year']);
            if ($race['year'] !== (int) substr($race['date'], 0, 4) || $race['race_id'] < 1 || $race['n'] < 2 || $race['n'] > 9) {
                throw new RuntimeException('Invalid historical race identity/year/count.');
            }
            $ts = Signals::timestamp($race['date'], $race['scheduled_start_at']);
            if ($race['meeting_id'] !== null && ($race['day_date'] !== $race['date']
                || $race['date'] < $race['starts_on'] || $race['date'] > $race['ends_on'])) {
                throw new RuntimeException('Historical meeting/date mismatch.');
            }
            $scores = array_map(fn ($e) => Signals::score($e['race_score']), $race['entries']);
            $complete = count($scores) === $race['n'] && count($scores) > 1 && ! in_array(null, $scores, true);
            $rankGroups = [];
            foreach ($race['entries'] as $e) {
                if (in_array($e['status'], ['FINISHED', 'TIED'], true)) {
                    if (! is_int($e['rank']) || $e['rank'] < 1 || $e['rank'] > $race['n']) {
                        throw new RuntimeException('Invalid normal historical rank.');
                    }
                    $rankGroups[$e['rank']][] = $e['status'];
                } elseif ($e['rank'] !== null) {
                    throw new RuntimeException('Abnormal historical rank must be null.');
                }
            }
            foreach ($rankGroups as $statuses) {
                $expected = count($statuses) > 1 ? 'TIED' : 'FINISHED';
                if (array_filter($statuses, fn ($s) => $s !== $expected)) {
                    throw new RuntimeException('Historical tie group inconsistent.');
                }
            }
            foreach ($race['entries'] as $i => $e) {
                if ($e['id'] < 1 || $e['bike'] < 1 || $e['bike'] > 9
                    || ($e['result_entry_id'] !== null && $e['result_entry_id'] !== $e['id'])
                    || ($e['result_player_id'] !== null && $e['result_player_id'] !== $e['player_id'])) {
                    throw new RuntimeException('Historical entry/result identity mismatch.');
                }
                $confirmed = in_array($race['race_status'], ['CONFIRMED', 'CORRECTED'], true);
                $normal = $confirmed && in_array($e['status'], ['FINISHED', 'TIED'], true);
                $started = $race['race_status'] === 'CANCELLED' || in_array($e['status'], ['DID_NOT_START', 'WITHDRAWN'], true) ? 0
                    : ($confirmed && in_array($e['status'], Contract::plan()['started'], true) ? 1 : null);
                $residual = null;
                if ($normal && $complete) {
                    // Formal Batch02 HistoricalRaceRepository scoreContextSummary/finish formula.
                    $rank = 1 + count(array_filter($scores, fn ($score) => $score > $scores[$i]));
                    $residual = ($race['n'] - $e['rank']) / ($race['n'] - 1) - ($race['n'] - $rank) / ($race['n'] - 1);
                }
                $insert->execute([$e['id'], $race['race_id'], $e['player_id'], $race['year'], $race['date'], $ts, $race['meeting_id'],
                    $scores[$i], $residual === null ? null : sprintf('%.17g', $residual), $started, $normal ? 1 : 0, $e['rank'], $race['n'], $e['status'], $race['race_status'], $e['bike']]);
            }
        }
        $this->db->commit();
        $mismatch = $this->db->query('SELECT t.id FROM targets t LEFT JOIN history h ON h.id=t.id
            WHERE h.id IS NULL OR t.race_id<>h.race_id OR t.bike<>h.bike OR t.player_id IS NOT h.player_id LIMIT 1')->fetch();
        if ($mismatch !== false) {
            throw new RuntimeException('Cohort/historical target identity mismatch.');
        }
    }

    public function previous(array $target): array
    {
        Contract::year($target['year']);
        if ($target['player_id'] === null || $target['ts'] === null) {
            return [];
        }
        $q = $this->db->prepare('SELECT * FROM history WHERE player_id=? AND race_id<>? AND (started=1 OR started IS NULL)
            AND (date<? OR (date=? AND (ts<? OR ts IS NULL)))
            ORDER BY date DESC, COALESCE(ts,9223372036854775807) DESC LIMIT 3');
        $q->execute([$target['player_id'], $target['race_id'], $target['date'], $target['date'], $target['ts']]);

        return $q->fetchAll();
    }

    public static function target(array $history): array
    {
        return array_intersect_key($history, array_flip(['id', 'race_id', 'player_id', 'year', 'date', 'ts', 'meeting_id', 'score', 'bike', 'n']));
    }

    public function training(Signals $signals): void
    {
        $insert = $this->db->prepare('INSERT INTO training VALUES(?,?,?)');
        $this->db->beginTransaction();
        $q = $this->db->query("SELECT * FROM history WHERE year BETWEEN 2022 AND 2024 AND race_status IN ('CONFIRMED','CORRECTED')
            AND player_id IN (SELECT player_id FROM targets WHERE player_id IS NOT NULL) ORDER BY id");
        while ($row = $q->fetch()) {
            $target = self::target($row);
            $growth = $signals->calculate($target, $this->previous($target));
            foreach (['SCORE', 'PERFORMANCE'] as $signal) {
                if ($growth[$signal]['raw'] !== null) {
                    $insert->execute([$target['year'], $signal, sprintf('%.17g', $growth[$signal]['raw'])]);
                }
            }
        }
        $this->db->commit();
    }

    public function thresholds(): array
    {
        $all = [];
        foreach (Contract::YEARS as $year) {
            foreach (['SCORE', 'PERFORMANCE'] as $signal) {
                $filter = ' FROM training WHERE signal=? AND year>=2022 AND year<?';
                $count = $this->db->prepare('SELECT count(*)'.$filter);
                $count->execute([$signal, $year]);
                $n = (int) $count->fetchColumn();
                $quantiles = [];
                foreach (Contract::QUANTILES as $probability) {
                    if ($n === 0) {
                        break;
                    }
                    $p = ($n - 1) * $probability;
                    $low = (int) floor($p);
                    $q = $this->db->prepare('SELECT raw'.$filter.' ORDER BY raw LIMIT 2 OFFSET '.$low);
                    $q->execute([$signal, $year]);
                    $a = (float) $q->fetchColumn();
                    $b = $q->fetchColumn();
                    $quantiles[] = $a + (($b === false ? $a : (float) $b) - $a) * ($p - $low);
                }
                $all[$year][$signal] = ['training_years' => range(2022, $year - 1), 'n' => $n, 'type' => 7,
                    'values' => $n === 0 ? null : $quantiles, 'unique_boundaries' => count(array_unique($quantiles, SORT_REGULAR))];
            }
        }

        return $all;
    }

    public function inputs(): Generator
    {
        $q = $this->db->query('SELECT body FROM cohort ORDER BY year,race_id');
        $get = $this->db->prepare('SELECT * FROM history WHERE id=?');
        while ($body = $q->fetchColumn()) {
            $race = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            $ranks = array_column($race['context']['entries'], 'rank');
            $uniqueWinner = count(array_filter($ranks, fn ($r) => $r === 1)) === 1;
            foreach ($race['context']['entries'] as $entry) {
                $get->execute([$entry['id']]);
                $history = $get->fetch();
                if ($history['year'] !== $race['context']['year'] || $history['date'] !== $race['date']
                    || $history['rank'] !== $entry['rank'] || $history['status'] !== $entry['status']) {
                    throw new RuntimeException('Saved cohort outcome drifted from historical snapshot.');
                }
                $target = self::target($history);
                yield ['target' => $target, 'previous' => $this->previous($target), 'grade' => $race['grade'], 'class' => $race['class'],
                    'outcome' => ['rank' => $entry['rank'], 'status' => $entry['status'], 'n' => $history['n']],
                    'predicted' => $entry['bike'] === $race['decision']['primary_position_1_bike'], 'unique_winner' => $uniqueWinner];
            }
        }
    }
}
