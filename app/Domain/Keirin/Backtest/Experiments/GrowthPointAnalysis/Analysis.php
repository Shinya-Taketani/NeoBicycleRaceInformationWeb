<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\GrowthPointAnalysis;

use App\Domain\Keirin\Backtest\Experiments\TacticalGradeAnalysis\Aggregator as Wilson;
use Generator;
use PDO;
use RuntimeException;

final class Analysis
{
    public function __construct(private readonly Signals $signals) {}

    public function details(iterable $inputs, array $thresholds, Workspace $workspace): Generator
    {
        $insert = $workspace->db->prepare('INSERT INTO observations VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $workspace->db->beginTransaction();
        foreach ($inputs as $input) {
            $target = $input['target'];
            if (! in_array($target['year'], Contract::YEARS, true)) {
                throw new RuntimeException('Invalid evaluation year.');
            }
            $growth = $this->signals->calculate($target, $input['previous']);
            $values = [];
            foreach (['SCORE', 'PERFORMANCE'] as $signal) {
                $values[$signal] = $growth[$signal] + ['point' => Contract::point($growth[$signal]['raw'], $thresholds[$target['year']][$signal]['values'])];
            }
            $a = $values['SCORE']['point'];
            $b = $values['PERFORMANCE']['point'];
            $values['COMPOSITE'] = ['raw' => null, 'status' => $a !== null && $b !== null ? 'VALID' : 'MISSING_COMPONENT',
                'point' => $a !== null && $b !== null ? $a + $b : null];
            $outcome = $input['outcome'];
            $normal = in_array($outcome['status'], ['FINISHED', 'TIED'], true);
            if ($normal && (! is_int($outcome['rank']) || $outcome['rank'] < 1 || $outcome['rank'] > $target['n'])) {
                throw new RuntimeException('Invalid evaluation rank.');
            }
            $fp = $normal ? ($target['n'] - $outcome['rank']) / ($target['n'] - 1) : null;
            $same = match ($growth['same_meeting_previous']) {
                true => 'PREVIOUS_SAME_MEETING', false => 'PREVIOUS_OTHER_MEETING', default => 'UNKNOWN',
            };
            foreach ($values as $signal => $value) {
                $insert->execute([$target['year'], $target['race_id'], $target['id'], $target['player_id'], $input['grade'], $input['class'], $same,
                    $signal, $value['raw'] === null ? null : sprintf('%.17g', $value['raw']), $value['point'], $value['status'],
                    $normal ? 1 : 0, $outcome['rank'], $fp === null ? null : sprintf('%.17g', $fp), $input['predicted'] ? 1 : 0, $input['unique_winner'] ? 1 : 0]);
            }
            yield ['year' => $target['year'], 'race_id' => $target['race_id'], 'entry_id' => $target['id'], 'player_id' => $target['player_id'],
                'grade' => $input['grade'], 'class' => $input['class'], 'same' => $same, 'signals' => $values,
                'prev1_id' => $growth['prev1']['id'] ?? null, 'prev2_id' => $growth['prev2']['id'] ?? null,
                'normal' => $normal, 'rank' => $outcome['rank'], 'result_status' => $outcome['status'], 'finish_percentile' => $fp,
                'predicted_p1' => $input['predicted'], 'unique_winner' => $input['unique_winner']];
        }
        $workspace->db->commit();
    }

    public function aggregate(Workspace $workspace): array
    {
        $db = $workspace->db;
        $cells = $correlations = $classifications = $coverage = [];
        foreach ($this->groups($db) as $group) {
            [$where, $args] = $this->where($group);
            foreach (Contract::SIGNALS as $signal) {
                $filter = $where.' AND signal=?';
                $bindings = [...$args, $signal];
                $q = $db->prepare('SELECT point, count(*) AS entries, sum(normal) AS normal, count(DISTINCT player_id) AS players,
                    count(DISTINCT race_id) AS races, sum(normal=0) AS abnormal, sum(point IS NULL) AS missing,
                    sum(normal=1 AND rank=1) AS win, sum(normal=1 AND rank=2) AS second, sum(normal=1 AND rank=3) AS third,
                    sum(normal=1 AND rank<=2) AS top2, sum(normal=1 AND rank<=3) AS top3, avg(fp) AS mean_fp
                    FROM observations WHERE '.$filter.' GROUP BY point ORDER BY point');
                $q->execute($bindings);
                $byPoint = [];
                foreach ($q as $row) {
                    $byPoint[$row['point'] === null ? 'MISSING' : (string) $row['point']] = $row;
                }
                $pointCells = [];
                foreach ([...range($signal === 'COMPOSITE' ? -6 : -3, $signal === 'COMPOSITE' ? 6 : 3), 'MISSING'] as $point) {
                    $row = $byPoint[$point] ?? ['entries' => 0, 'normal' => 0, 'players' => 0, 'races' => 0, 'abnormal' => 0, 'missing' => 0,
                        'win' => 0, 'second' => 0, 'third' => 0, 'top2' => 0, 'top3' => 0, 'mean_fp' => null];
                    unset($row['point']);
                    $row = $group + ['signal' => $signal, 'point' => $point] + $row;
                    $pFilter = $filter.($point === 'MISSING' ? ' AND point IS NULL' : ' AND point=?');
                    $pArgs = $point === 'MISSING' ? $bindings : [...$bindings, $point];
                    $row['median_fp'] = $this->median($db, $pFilter, $pArgs, (int) $row['normal']);
                    foreach (['win', 'second', 'third', 'top2', 'top3'] as $metric) {
                        $row[$metric.'_rate'] = $row['normal'] ? (float) $row[$metric] / $row['normal'] : null;
                    }
                    $row['win_ci'] = Wilson::interval((int) $row['win'], (int) $row['normal']);
                    $row['top3_ci'] = Wilson::interval((int) $row['top3'], (int) $row['normal']);
                    $cells[] = $row;
                    if ($point !== 'MISSING') {
                        $pointCells[] = $row;
                    }
                }
                $rhoRaw = $signal === 'COMPOSITE' ? null : $this->spearman($db, $filter, $bindings, 'raw');
                $rhoPoint = $this->spearman($db, $filter, $bindings, 'point');
                $correlations[] = $group + ['signal' => $signal, 'raw' => $rhoRaw, 'point' => $rhoPoint];
                $classifications[] = $group + ['signal' => $signal] + $this->classify($pointCells, $rhoRaw, $rhoPoint);
                if ($group['dimension'] === 'year' || $group['dimension'] === 'same') {
                    $s = $db->prepare('SELECT state,count(*) AS n,sum(raw=0) AS zero_raw FROM observations WHERE '.$filter.' GROUP BY state ORDER BY state');
                    $s->execute($bindings);
                    $coverage[] = $group + ['signal' => $signal, 'states' => $s->fetchAll()];
                }
            }
        }
        $totals = $db->query("SELECT year,count(DISTINCT race_id) AS races,count(*) AS entries,sum(normal) AS normal,sum(normal=0) AS abnormal FROM observations WHERE signal='SCORE' GROUP BY year ORDER BY year")->fetchAll();

        return ['summary' => ['version' => Contract::plan()['version'], 'year_totals' => $totals, 'coverage' => $coverage, 'cells' => $cells,
            'ci_caveat' => 'REFERENCE_ONLY_UNCORRECTED_RACE_PLAYER_MEETING_CORRELATION', 'gate' => 'NOT_APPLICABLE'],
            'correlations' => $correlations, 'classification' => $classifications, 'c1_diagnostics' => $this->c1($db)];
    }

    public function spearman(PDO $db, string $where, array $args, string $field): array
    {
        if (! in_array($field, ['raw', 'point'], true)) {
            throw new RuntimeException('Unsupported correlation field.');
        }
        $sql = 'WITH ranks AS (SELECT player_id,race_id,
            rank() OVER(ORDER BY '.$field.')+(count(*) OVER(PARTITION BY '.$field.')-1)/2.0 AS x,
            rank() OVER(ORDER BY fp)+(count(*) OVER(PARTITION BY fp)-1)/2.0 AS y,
            count(*) OVER() AS n
            FROM observations WHERE '.$where.' AND normal=1 AND '.$field.' IS NOT NULL),
            centered AS (SELECT *,x-(n+1)/2.0 AS dx,y-(n+1)/2.0 AS dy FROM ranks)
            SELECT count(*) AS n,count(DISTINCT player_id) AS players,count(DISTINCT race_id) AS races,
                sum(dx*dy) AS xy,sum(dx*dx) AS xx,sum(dy*dy) AS yy FROM centered';
        $q = $db->prepare($sql);
        $q->execute($args);
        $r = $q->fetch();

        return ['n' => (int) $r['n'], 'players' => (int) $r['players'], 'races' => (int) $r['races'],
            'rho' => $r['xx'] > 0 && $r['yy'] > 0 ? $r['xy'] / sqrt($r['xx'] * $r['yy']) : null];
    }

    public function classify(array $cells, ?array $raw, array $point): array
    {
        $supported = array_values(array_filter($cells, fn ($c) => $c['normal'] >= 30));
        $adjacent = [];
        $up = $down = ['win' => 0, 'top3' => 0];
        for ($i = 1; $i < count($supported); $i++) {
            $delta = ['from' => $supported[$i - 1]['point'], 'to' => $supported[$i]['point']];
            foreach (['win', 'top3'] as $metric) {
                $d = $supported[$i][$metric.'_rate'] - $supported[$i - 1][$metric.'_rate'];
                $delta[$metric] = $d;
                $up[$metric] += $d < 0 ? 1 : 0;
                $down[$metric] += $d > 0 ? 1 : 0;
            }
            $adjacent[] = $delta;
        }
        $allAdjacent = [];
        for ($i = 1; $i < count($cells); $i++) {
            $allAdjacent[] = ['from' => $cells[$i - 1]['point'], 'to' => $cells[$i]['point'],
                'win' => $cells[$i]['normal'] && $cells[$i - 1]['normal'] ? $cells[$i]['win_rate'] - $cells[$i - 1]['win_rate'] : null,
                'top3' => $cells[$i]['normal'] && $cells[$i - 1]['normal'] ? $cells[$i]['top3_rate'] - $cells[$i - 1]['top3_rate'] : null];
        }
        $r = ($raw ?? $point)['rho'];
        $p = $point['rho'];
        $label = 'NON_MONOTONIC';
        if ($point['n'] < 1000 || $point['players'] < 30 || $point['races'] < 100 || count($supported) < 3) {
            $label = 'INSUFFICIENT_SAMPLE';
        } elseif ($r === null || $p === null) {
            $label = 'NO_CLEAR_RELATION';
        } elseif (abs($r) < 0.03 && abs($p) < 0.03
            && max(array_column($supported, 'win_rate')) - min(array_column($supported, 'win_rate')) < 0.02
            && max(array_column($supported, 'top3_rate')) - min(array_column($supported, 'top3_rate')) < 0.03) {
            $label = 'NO_CLEAR_RELATION';
        } elseif ($r >= 0.03 && $p >= 0.03 && array_sum($up) === 0) {
            $label = 'POSITIVE_MONOTONIC_TENDENCY';
        } elseif ($r <= -0.03 && $p <= -0.03 && array_sum($down) === 0) {
            $label = 'NEGATIVE_MONOTONIC_TENDENCY';
        }

        return ['label' => $label, 'supported_points' => array_column($supported, 'point'), 'increasing_violations' => $up,
            'decreasing_violations' => $down, 'adjacent_supported_points' => $adjacent, 'adjacent_all_points' => $allAdjacent];
    }

    private function median(PDO $db, string $filter, array $args, int $n): ?float
    {
        if ($n === 0) {
            return null;
        }
        $q = $db->prepare('SELECT fp,count(*) AS n FROM observations WHERE '.$filter.' AND normal=1 GROUP BY fp ORDER BY fp');
        $q->execute($args);
        $low = (int) floor(($n - 1) / 2);
        $high = (int) ceil(($n - 1) / 2);
        $count = 0;
        $a = $b = null;
        foreach ($q as $row) {
            if ($low >= $count && $low < $count + $row['n']) {
                $a = (float) $row['fp'];
            }
            if ($high >= $count && $high < $count + $row['n']) {
                $b = (float) $row['fp'];
            }
            $count += $row['n'];
        }

        return ($a + $b) / 2.0;
    }

    private function groups(PDO $db): array
    {
        $groups = [];
        foreach (Contract::YEARS as $year) {
            $base = ['dimension' => 'year', 'year' => $year, 'grade' => null, 'class' => null, 'same' => null];
            $groups[] = $base;
            foreach (['GP', 'G1', 'G2', 'G3', 'F1', 'F2', 'UNKNOWN'] as $grade) {
                $groups[] = array_replace($base, ['dimension' => 'grade', 'grade' => $grade]);
            }
            foreach (['S_CLASS', 'A1_A2', 'A_CHALLENGE', 'UNKNOWN'] as $class) {
                $groups[] = array_replace($base, ['dimension' => 'class', 'class' => $class]);
            }
            foreach (['PREVIOUS_SAME_MEETING', 'PREVIOUS_OTHER_MEETING', 'UNKNOWN'] as $same) {
                $groups[] = array_replace($base, ['dimension' => 'same', 'same' => $same]);
            }
            $q = $db->prepare("SELECT DISTINCT grade,class FROM observations WHERE year=? AND signal='SCORE' ORDER BY grade,class");
            $q->execute([$year]);
            foreach ($q as $row) {
                $groups[] = array_replace($base, ['dimension' => 'grade_class'] + $row);
            }
        }

        return $groups;
    }

    private function where(array $group): array
    {
        $sql = 'year=?';
        $values = [$group['year']];
        foreach (['grade', 'class', 'same'] as $key) {
            if ($group[$key] !== null) {
                $sql .= ' AND '.$key.'=?';
                $values[] = $group[$key];
            }
        }

        return [$sql, $values];
    }

    private function c1(PDO $db): array
    {
        $rows = [];
        foreach (Contract::YEARS as $year) {
            foreach (Contract::SIGNALS as $signal) {
                $distributions = [];
                foreach (['predicted_p1' => 'predicted=1', 'actual_unique_winner' => 'unique_winner=1 AND rank=1',
                    'missed_unique_winner' => 'unique_winner=1 AND rank=1 AND predicted=0'] as $name => $condition) {
                    $q = $db->prepare('SELECT point,count(*) AS n FROM observations WHERE year=? AND signal=? AND '.$condition.' GROUP BY point ORDER BY point');
                    $q->execute([$year, $signal]);
                    $distributions[$name] = $q->fetchAll();
                }
                $q = $db->prepare('SELECT a.point-p.point AS actual_minus_predicted,count(*) AS n FROM observations a JOIN observations p
                    ON a.race_id=p.race_id AND a.signal=p.signal AND p.predicted=1 WHERE a.year=? AND a.signal=?
                    AND a.unique_winner=1 AND a.rank=1 AND a.predicted=0 GROUP BY a.point-p.point ORDER BY a.point-p.point');
                $q->execute([$year, $signal]);
                $rows[] = ['year' => $year, 'signal' => $signal, 'distributions' => $distributions, 'error_race_point_gaps' => $q->fetchAll()];
            }
        }

        return $rows;
    }
}
