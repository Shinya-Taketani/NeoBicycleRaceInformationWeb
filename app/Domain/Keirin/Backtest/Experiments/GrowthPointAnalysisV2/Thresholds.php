<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\GrowthPointAnalysisV2;

use App\Domain\Keirin\Backtest\Experiments\GrowthPointAnalysis\Workspace;

final class Thresholds
{
    public function calculate(Workspace $workspace): array
    {
        $all = [];
        foreach ([2024, 2025] as $year) {
            foreach (['SCORE', 'PERFORMANCE'] as $signal) {
                $base = ' FROM training WHERE year>=2022 AND year<? AND signal=?';
                $args = [$year, $signal];
                $zero = $workspace->db->prepare('SELECT count(*)'.$base.' AND raw=0');
                $zero->execute($args);
                $result = ['training_years' => range(2022, $year - 1), 'zero_n' => (int) $zero->fetchColumn(),
                    'type' => 7, 'probabilities' => Contract::QUANTILES];
                foreach (['negative' => '<', 'positive' => '>'] as $side => $operator) {
                    $where = $base.' AND raw'.$operator.'0';
                    $count = $workspace->db->prepare('SELECT count(*)'.$where);
                    $count->execute($args);
                    $n = (int) $count->fetchColumn();
                    $values = null;
                    if ($n >= Contract::MINIMUM_SIDE) {
                        $values = [];
                        foreach (Contract::QUANTILES as $p) {
                            $index = ($n - 1) * $p;
                            $low = (int) floor($index);
                            $q = $workspace->db->prepare('SELECT abs(raw)'.$where.' ORDER BY abs(raw) LIMIT 2 OFFSET '.$low);
                            $q->execute($args);
                            $a = (float) $q->fetchColumn();
                            $b = $q->fetchColumn();
                            $values[] = $a + (($b === false ? $a : (float) $b) - $a) * ($index - $low);
                        }
                    }
                    $result[$side] = compact('n', 'values');
                }
                $all[$year][$signal] = $result;
            }
        }

        return $all;
    }
}
