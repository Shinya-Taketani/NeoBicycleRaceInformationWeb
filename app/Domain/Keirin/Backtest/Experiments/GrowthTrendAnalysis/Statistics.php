<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\GrowthTrendAnalysis;

use PDO;

final class Statistics
{
    public function __construct(private readonly PDO $db) {}

    public function query(string $sql, array $args = []): \PDOStatement
    {
        $q = $this->db->prepare($sql);
        $q->execute($args);

        return $q;
    }

    public function distribution(string $from, array $args, string $column): array
    {
        $row = $this->query('SELECT count('.$column.') AS n,avg('.$column.') AS mean FROM '.$from, $args)->fetch();
        $n = (int) $row['n'];
        $out = ['n' => $n, 'mean' => $row['mean']];
        foreach (['P10' => 0.1, 'P25' => 0.25, 'P50' => 0.5, 'P75' => 0.75, 'P90' => 0.9, 'P95' => 0.95] as $name => $p) {
            $out[$name] = $this->quantile($from, $args, $column, $n, $p);
        }
        $out['median'] = $out['P50'];

        return $out;
    }

    private function quantile(string $from, array $args, string $column, int $n, float $p): ?float
    {
        if ($n === 0) {
            return null;
        }
        $h = ($n - 1) * $p;
        $q = $this->query('SELECT '.$column.' FROM '.$from.' AND '.$column.' IS NOT NULL ORDER BY '.$column.' LIMIT 2 OFFSET '.(int) floor($h), $args);
        $a = (float) $q->fetchColumn();
        $b = $q->fetchColumn();

        return $a + (($b === false ? $a : (float) $b) - $a) * ($h - floor($h));
    }

    public function bins(): array
    {
        $out = [];
        foreach ([2024, 2025] as $year) {
            foreach (['score' => ['score_bin', 10], 'p1' => ['p1_bin', 10], 'margin' => ['margin_bin', 4]] as $column => [$dest, $k]) {
                $from = $column === 'margin' ? '(SELECT race_id,max(margin) AS margin,year FROM entries GROUP BY race_id) WHERE year=?' : 'entries WHERE year=?';
                $n = (int) $this->query('SELECT count('.$column.') FROM '.$from, [$year])->fetchColumn();
                $cuts = [];
                for ($i = 1; $i < $k; $i++) {
                    $q = $this->quantile($from, [$year], $column, $n, $i / $k);
                    if ($q !== null && ! in_array($q, $cuts, true)) {
                        $cuts[] = $q;
                    }
                }
                $sql = 'CASE WHEN '.$column.' IS NULL THEN NULL ';
                $args = [];
                foreach ($cuts as $i => $cut) {
                    $sql .= 'WHEN '.$column.'<=? THEN '.($i + 1).' ';
                    $args[] = sprintf('%.17g', $cut);
                }
                $sql .= 'ELSE '.(count($cuts) + 1).' END';
                $this->query('UPDATE entries SET '.$dest.'='.$sql.' WHERE year=?', [...$args, $year]);
                $counts = $this->query('SELECT '.$dest.' AS bin,count(*) AS entries FROM entries WHERE year=? GROUP BY '.$dest.' ORDER BY '.$dest, [$year])->fetchAll();
                $out[$year][$column] = ['cuts' => $cuts, 'effective_bins' => count(array_filter($counts, fn ($r) => $r['bin'] !== null)), 'counts' => $counts];
            }
        }

        return $out;
    }

    public function rho(string $where, array $args): array
    {
        $r = $this->query('WITH ranks AS (SELECT rank() OVER(ORDER BY s.raw)+(count(*) OVER(PARTITION BY s.raw)-1)/2.0 AS x,
            rank() OVER(ORDER BY e.fp)+(count(*) OVER(PARTITION BY e.fp)-1)/2.0 AS y,count(*) OVER() AS n
            FROM signals s JOIN entries e ON e.entry_id=s.entry_id WHERE '.$where.' AND e.normal=1 AND s.raw IS NOT NULL),
            centered AS (SELECT x-(n+1)/2.0 AS dx,y-(n+1)/2.0 AS dy FROM ranks)
            SELECT count(*) AS n,sum(dx*dy) AS xy,sum(dx*dx) AS xx,sum(dy*dy) AS yy FROM centered', $args)->fetch();

        return ['n' => (int) $r['n'], 'rho' => $r['xx'] > 0 && $r['yy'] > 0 ? $r['xy'] / sqrt($r['xx'] * $r['yy']) : null];
    }

    public static function weighted(array $bins): array
    {
        $sum = 0.0;
        $count = $excluded = 0;
        foreach ($bins as $bin) {
            if ($bin['n'] < 100 || $bin['rho'] === null) {
                $excluded += $bin['n'];

                continue;
            }
            $sum += $bin['n'] * $bin['rho'];
            $count += $bin['n'];
        }

        return ['rho' => $count > 0 ? $sum / $count : null, 'included_entries' => $count, 'excluded_entries' => $excluded, 'bins' => $bins];
    }

    public function metrics(string $id, int $year, string $extra = '1=1', array $extraArgs = [], bool $conditional = true): array
    {
        $where = 's.candidate=? AND e.year=? AND '.$extra;
        $args = [$id, $year, ...$extraArgs];
        $from = 'signals s JOIN entries e ON e.entry_id=s.entry_id WHERE '.$where;
        $row = $this->query('SELECT count(*) AS entries,count(s.raw) AS valid_entries,sum(s.raw IS NOT NULL AND e.normal=1) AS normal_entries,
            sum(s.raw=0) AS zero_count FROM '.$from, $args)->fetch();
        foreach ($row as &$value) {
            $value = (int) $value;
        }
        unset($value);
        $signs = [];
        foreach (['NEGATIVE' => '<0', 'ZERO' => '=0', 'POSITIVE' => '>0', 'ALL_VALID' => ' IS NOT NULL'] as $sign => $condition) {
            $signs[$sign] = $this->query('SELECT count(*) AS entries,sum(e.normal) AS normal,
                sum(e.normal=1 AND e.rank=1) AS win,sum(e.normal=1 AND e.rank<=2) AS top2,sum(e.normal=1 AND e.rank<=3) AS top3,avg(e.fp) AS mean_fp FROM '.$from.' AND s.raw'.$condition, $args)->fetch();
            foreach (['win', 'top2', 'top3'] as $key) {
                $signs[$sign][$key.'_rate'] = $signs[$sign]['normal'] > 0 ? $signs[$sign][$key] / $signs[$sign]['normal'] : null;
            }
            $signs[$sign]['median_fp'] = $this->quantile($from.' AND s.raw'.$condition.' AND e.normal=1', $args, 'e.fp', (int) $signs[$sign]['normal'], 0.5);
        }
        $out = $row + ['coverage' => $row['entries'] > 0 ? $row['valid_entries'] / $row['entries'] : null,
            'zero_rate' => $row['valid_entries'] > 0 ? $row['zero_count'] / $row['valid_entries'] : null,
            'distribution' => $this->distribution($from, $args, 's.raw'), 'overall' => $this->rho($where, $args), 'signs' => $signs,
            'fp_pos_minus_neg' => $signs['POSITIVE']['mean_fp'] !== null && $signs['NEGATIVE']['mean_fp'] !== null ? $signs['POSITIVE']['mean_fp'] - $signs['NEGATIVE']['mean_fp'] : null];
        if ($conditional) {
            foreach (['score_bin' => 'score_conditional', 'p1_bin' => 'c1_conditional'] as $column => $name) {
                $bins = [];
                $available = $this->query('SELECT DISTINCT '.$column.' FROM entries WHERE year=? AND '.$column.' IS NOT NULL ORDER BY '.$column, [$year])->fetchAll(PDO::FETCH_COLUMN);
                foreach ($available as $bin) {
                    $bins[] = ['bin' => $bin] + $this->rho($where.' AND e.'.$column.'=?', [...$args, $bin]);
                }
                $out[$name] = self::weighted($bins);
            }
        }

        return $out;
    }
}
