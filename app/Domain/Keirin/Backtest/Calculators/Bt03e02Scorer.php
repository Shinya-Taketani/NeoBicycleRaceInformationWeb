<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Calculators;

use App\Domain\Keirin\Backtest\DTO\Bt03e02FitResultDto;
use App\Domain\Keirin\Backtest\Services\Bt03e02Contract;
use RuntimeException;

final class Bt03e02Scorer
{
    /**
     * @param  callable(): iterable<array<string, mixed>>  $raceSource
     * @return array<string, array{scale:?float,status:string,training_race_count:int,training_entry_count:int}>
     */
    public function trainingScales(callable $raceSource, Bt03e02FitResultDto $fit): array
    {
        $varianceSums = array_fill_keys(Bt03e02Contract::CHANNELS, null);
        foreach ($varianceSums as $channel => $_) {
            $varianceSums[$channel] = new Bt03e02CompensatedSum;
        }
        $raceCounts = array_fill_keys(Bt03e02Contract::CHANNELS, 0);
        $entryCounts = array_fill_keys(Bt03e02Contract::CHANNELS, 0);
        foreach ($raceSource() as $race) {
            $scores = $this->rawScores($race['entries'], $fit);
            foreach (Bt03e02Contract::CHANNELS as $channel) {
                $values = array_column($scores, $channel);
                $mean = $this->mean($values);
                $variance = new Bt03e02CompensatedSum;
                foreach ($values as $value) {
                    $variance->add(($value - $mean) ** 2);
                }
                $varianceSums[$channel]->add($variance->value() / count($values));
                $raceCounts[$channel]++;
                $entryCounts[$channel] += count($values);
            }
        }
        $scales = [];
        foreach (Bt03e02Contract::CHANNELS as $channel) {
            if ($raceCounts[$channel] < 1) {
                throw new RuntimeException("BT-03E-02 {$channel} scale had no training races.");
            }
            $scale = sqrt($varianceSums[$channel]->value() / $raceCounts[$channel]);
            $degenerate = ! is_finite($scale) || $scale <= 0.0;
            $scales[$channel] = [
                'scale' => $degenerate ? null : $scale,
                'status' => $degenerate ? 'DEGENERATE_CHANNEL' : 'AVAILABLE',
                'training_race_count' => $raceCounts[$channel],
                'training_entry_count' => $entryCounts[$channel],
            ];
        }

        return $scales;
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return list<array<string, float>>
     */
    public function rawScores(array $entries, Bt03e02FitResultDto $fit): array
    {
        $scores = [];
        foreach ($entries as $entry) {
            $row = [];
            foreach (Bt03e02Contract::CHANNELS as $channel) {
                $sum = new Bt03e02CompensatedSum;
                $sum->add((float) $entry['anchor']);
                foreach ($entry['bins'] as $index) {
                    if ($index !== null) {
                        $sum->add($fit->coefficients[$channel][$index]);
                    }
                }
                $row[$channel] = $sum->value();
            }
            $scores[] = $row;
        }

        return $scores;
    }

    /**
     * @param  array<string, array{scale:?float,status:string,training_race_count:int,training_entry_count:int}>  $scales
     * @return list<array<string, mixed>>
     */
    public function predictions(array $race, Bt03e02FitResultDto $fit, array $scales): array
    {
        $rawScores = $this->rawScores($race['entries'], $fit);
        $predictions = [];
        foreach ($race['entries'] as $offset => $entry) {
            $normalized = [];
            foreach (Bt03e02Contract::CHANNELS as $channel) {
                $scale = $scales[$channel]['scale'] ?? null;
                $normalized[$channel] = $scale === null ? null : $rawScores[$offset][$channel] / $scale;
            }
            $predictions[] = [
                'id' => $entry['id'],
                'bike' => $entry['bike'],
                'raw' => $entry['raw'],
                'stat01_rank' => $entry['stat01_rank'],
                'normalized' => $normalized,
                'rank' => $entry['rank'],
                'status' => $entry['status'],
            ];
        }

        return $predictions;
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @param  array{IS_WIN:float,IS_TOP2:float,IS_TOP3:float,key:string}  $alpha
     * @return array{entries:list<array<string,mixed>>,diagnostics:array<string,int|float|null>}
     */
    public function rank(int $raceId, array $entries, array $alpha): array
    {
        $ranked = [];
        foreach ($entries as $entry) {
            $score = new Bt03e02CompensatedSum;
            foreach (Bt03e02Contract::CHANNELS as $channel) {
                $value = $entry['normalized'][$channel] ?? null;
                if ($value === null) {
                    if ($alpha[$channel] !== 0.0) {
                        throw new RuntimeException("BT-03E-02 {$channel} was degenerate but had non-zero alpha.");
                    }

                    continue;
                }
                $score->add($alpha[$channel] * $value);
            }
            $ranked[] = [
                ...$entry,
                'ranking_score' => $score->value(),
                'technical_key' => hash('sha256', Bt03e02Contract::TIE_RULE_VERSION.'|'.$raceId.'|'.$entry['bike']),
            ];
        }
        usort($ranked, static function (array $left, array $right): int {
            foreach (['ranking_score', ['normalized', 'IS_WIN'], ['normalized', 'IS_TOP2'], ['normalized', 'IS_TOP3'], 'raw'] as $key) {
                $leftValue = is_array($key) ? ($left[$key[0]][$key[1]] ?? -INF) : $left[$key];
                $rightValue = is_array($key) ? ($right[$key[0]][$key[1]] ?? -INF) : $right[$key];
                if ($leftValue !== $rightValue) {
                    return $leftValue > $rightValue ? -1 : 1;
                }
            }

            return $left['technical_key'] <=> $right['technical_key'];
        });

        return ['entries' => $ranked, 'diagnostics' => $this->tieDiagnostics($ranked)];
    }

    /** @param list<float> $values */
    private function mean(array $values): float
    {
        $sum = new Bt03e02CompensatedSum;
        foreach ($values as $value) {
            $sum->add($value);
        }

        return $sum->value() / count($values);
    }

    /** @param list<array<string,mixed>> $entries @return array<string,int|float|null> */
    private function tieDiagnostics(array $entries): array
    {
        $scoreGroups = [];
        foreach ($entries as $entry) {
            $scoreGroups[sprintf('%.17g', $entry['ranking_score'])][] = $entry;
        }
        $tiedEntries = $resolvedWin = $resolvedTop2 = $resolvedTop3 = $resolvedRaw = 0;
        foreach ($scoreGroups as $group) {
            if (count($group) < 2) {
                continue;
            }
            $tiedEntries += count($group);
            $levels = [
                'WIN' => array_column(array_column($group, 'normalized'), 'IS_WIN'),
                'TOP2' => array_column(array_column($group, 'normalized'), 'IS_TOP2'),
                'TOP3' => array_column(array_column($group, 'normalized'), 'IS_TOP3'),
                'RAW' => array_column($group, 'raw'),
            ];
            if (count(array_unique($levels['WIN'], SORT_REGULAR)) > 1) {
                $resolvedWin += count($group);
            } elseif (count(array_unique($levels['TOP2'], SORT_REGULAR)) > 1) {
                $resolvedTop2 += count($group);
            } elseif (count(array_unique($levels['TOP3'], SORT_REGULAR)) > 1) {
                $resolvedTop3 += count($group);
            } elseif (count(array_unique($levels['RAW'], SORT_REGULAR)) > 1) {
                $resolvedRaw += count($group);
            }
        }
        $technicalGroups = [];
        foreach ($entries as $entry) {
            $key = implode('|', [
                sprintf('%.17g', $entry['ranking_score']),
                $this->canonicalNullableScore($entry['normalized']['IS_WIN'] ?? null),
                $this->canonicalNullableScore($entry['normalized']['IS_TOP2'] ?? null),
                $this->canonicalNullableScore($entry['normalized']['IS_TOP3'] ?? null),
                sprintf('%.17g', $entry['raw']),
            ]);
            $technicalGroups[$key] = ($technicalGroups[$key] ?? 0) + 1;
        }
        $technicalGroups = array_filter($technicalGroups, static fn (int $count): bool => $count > 1);
        $technicalEntries = array_sum($technicalGroups);
        $technicalRaces = (int) ($technicalEntries > 0);
        $gaps = [];
        for ($index = 1; $index < count($entries); $index++) {
            $gaps[] = $entries[$index - 1]['ranking_score'] - $entries[$index]['ranking_score'];
        }

        return [
            'exact_ranking_score_tied_race' => (int) ($tiedEntries > 0),
            'exact_ranking_score_tied_entries' => $tiedEntries,
            'resolved_by_win_score' => $resolvedWin,
            'resolved_by_top2_score' => $resolvedTop2,
            'resolved_by_top3_score' => $resolvedTop3,
            'resolved_by_stat01_raw' => $resolvedRaw,
            'technical_tiebreak_race' => $technicalRaces,
            'technical_tiebreak_entries' => $technicalEntries,
            'minimum_score_gap' => $gaps === [] ? null : min($gaps),
        ];
    }

    private function canonicalNullableScore(?float $value): string
    {
        return $value === null ? 'NULL' : sprintf('%.17g', $value);
    }
}
