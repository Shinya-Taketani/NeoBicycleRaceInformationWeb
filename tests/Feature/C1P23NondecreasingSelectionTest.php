<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Keirin\Backtest\Calculators\Bt03e05MetricEvaluator;
use App\Domain\Keirin\Backtest\Experiments\C1P23NondecreasingMarginal\Contract;
use App\Domain\Keirin\Backtest\Experiments\C1P23NondecreasingMarginal\Decoder;
use App\Domain\Keirin\Backtest\Experiments\C1P23NondecreasingMarginal\Evaluation;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\C1MarginalP23Fixture as Fixture;
use Tests\TestCase;

class C1P23NondecreasingSelectionTest extends TestCase
{
    #[DataProvider('counts')]
    public function test_independent_enumeration_supporting_originals_and_reference_bounds(int $n, bool $tie, bool $reverse): void
    {
        $input = Fixture::race(n: $n);
        if ($reverse) {
            $input['entries'] = array_reverse($input['entries']);
        }
        $saved = Fixture::prediction($input, $tie);
        $bytes = Files::canonical($saved);
        $row = app(Decoder::class)->decode($input, $saved);
        $old = $saved['decision'];
        [$a, $b, $c] = array_map(fn ($p) => $old['primary_position_'.$p.'_bike'], [1, 2, 3]);
        $p = $row['candidate']['policy'];
        $entries = array_column($saved['probabilities']['entries'], null, 'bike');
        $this->enumeration($saved['probabilities']['entries'], $a, $b, $c, $p);
        $this->assertSame($a, $row['candidate']['primary_position_1_bike']);
        $this->assertSame(3, count(array_unique([$a, $p['selected_p2_bike'], $p['selected_p3_bike']])));
        $this->assertSame($old, $row['baseline']);
        $this->assertSame($bytes, Files::canonical($saved));
        $this->assertGreaterThanOrEqual(0.0, $p['p2_delta']);
        $this->assertGreaterThanOrEqual(0.0, $p['p3_delta']);
        $this->assertSame((float) $entries[$p['selected_p2_bike']]['position_2_probability'], $p['selected_p2']);
        $this->assertSame((float) $entries[$p['selected_p3_bike']]['position_3_probability'], $p['selected_p3']);
        $s = $p['reference_sums'];
        $this->assertTrue($s['C1'] <= $s['PR90'] && $s['PR90'] <= $s['candidate'] && $s['candidate'] <= $s['E05']);
        foreach (['map_ordered_top3', 'map_ordered_probability', 'map_top3_set', 'map_top3_set_probability',
            'top2_marginal_bikes', 'top3_marginal_bikes', 'expected_ndcg_top3'] as $key) {
            $this->assertSame($old[$key], $row['candidate'][$key]);
        }
        foreach ($old['decoder_tie_diagnostics'] as $key => $value) {
            if ($key !== 'PRIMARY_SECOND_THIRD') {
                $this->assertSame($value, $row['candidate']['decoder_tie_diagnostics'][$key]);
            }
        }
        foreach (['selected_q2_given_winner', 'selected_q3_given_winner', 'primary_second_third_objective_score', 'q2_given_winner'] as $key) {
            $this->assertArrayNotHasKey($key, $row['candidate']);
        }
        $this->assertSame($p['maximum_count'], $row['candidate']['second_third_tie_count']);
    }

    public static function counts(): array
    {
        $cases = [];
        foreach ([5, 7, 9] as $n) {
            foreach ([false, true] as $tie) {
                foreach ([false, true] as $reverse) {
                    $cases[] = [$n, $tie, $reverse];
                }
            }
        }

        return $cases;
    }

    private function enumeration(array $entries, int $a, int $b, int $c, array $policy): void
    {
        $lookup = array_column($entries, null, 'bike');
        $threshold2 = (float) $lookup[$b]['position_2_probability'];
        $threshold3 = (float) $lookup[$c]['position_3_probability'];
        $groups = ['feasible_pairs' => [], 'excluded_p2_only' => [], 'excluded_p3_only' => [], 'excluded_both' => []];
        foreach ($entries as $x) {
            foreach ($entries as $y) {
                if (count(array_unique([$a, $x['bike'], $y['bike']])) !== 3) {
                    continue;
                }
                $fail = [(float) $x['position_2_probability'] < $threshold2, (float) $y['position_3_probability'] < $threshold3];
                $kind = match ($fail) {
                    [false, false] => 'feasible_pairs', [true, false] => 'excluded_p2_only',
                    [false, true] => 'excluded_p3_only', [true, true] => 'excluded_both',
                };
                $groups[$kind][] = ['pair' => [$x['bike'], $y['bike']],
                    'score' => (float) $x['position_2_probability'] + (float) $y['position_3_probability']];
            }
        }
        foreach ($groups as $kind => $rows) {
            $this->assertSame(count($rows), $policy[$kind]);
        }
        $this->assertSame(array_sum(array_map('count', $groups)), $policy['all_pairs']);
        $this->assertContains([$b, $c], array_column($groups['feasible_pairs'], 'pair'));
        $max = max(array_column($groups['feasible_pairs'], 'score'));
        $best = array_values(array_filter($groups['feasible_pairs'], fn ($r) => $r['score'] === $max));
        foreach ($best as &$r) {
            $r['key'] = hash('sha256', implode('|', [Contract::TIE, 2024, 19, $a, $b, $c, ...$r['pair']]));
        }
        unset($r);
        usort($best, fn ($x, $y) => [$x['key'], ...$x['pair']] <=> [$y['key'], ...$y['pair']]);
        $expected = $threshold2 + $threshold3 === $max ? [$b, $c] : $best[0]['pair'];
        $this->assertSame($expected, [$policy['selected_p2_bike'], $policy['selected_p3_bike']]);
        $this->assertSame(count($best), $policy['maximum_count']);
        $this->assertSame($max, $policy['selected_sum']);
    }

    public function test_component_exclusions_global_not_greedy_and_original_retention(): void
    {
        $entries = $this->entries([1, 2, 3, 4, 6, 7, 8], [0.0, 0.2, 0.1, 0.3, 0.25, 0.19, 0.0], [0.0, 0.0, 0.2, 0.4, 0.21, 0.5, 0.0]);
        $p = app(Decoder::class)->select(2024, 19, $entries, 1, 2, 3);
        $this->enumeration($entries, 1, 2, 3, $p);
        $this->assertSame([4, 7], [$p['selected_p2_bike'], $p['selected_p3_bike']]);
        foreach (['excluded_p2_only', 'excluded_p3_only', 'excluded_both'] as $key) {
            $this->assertGreaterThan(0, $p[$key]);
        }
        $global = $this->entries([1, 2, 3, 4, 6], [0.0, 0.2, 0.1, 0.4, 0.35], [0.0, 0.1, 0.2, 0.5, 0.21]);
        $p = app(Decoder::class)->select(2024, 19, $global, 1, 2, 3);
        $this->assertSame([6, 4], [$p['selected_p2_bike'], $p['selected_p3_bike']]);
        $this->assertGreaterThan(0.0, $p['p2_delta']);
        $this->assertGreaterThan(0.0, $p['p3_delta']);
        $reject = $this->entries([1, 2, 3, 4, 6], [0.0, 0.4, 0.0, 0.39, 0.0], [0.0, 0.0, 0.2, 0.19, 0.0]);
        $retained = app(Decoder::class)->select(2024, 19, $reject, 1, 2, 3);
        $this->assertSame([2, 3], [$retained['selected_p2_bike'], $retained['selected_p3_bike']]);
        $this->assertTrue($retained['original_pair_retained']);
    }

    public function test_unconstrained_best_can_violate_a_component_and_be_rejected(): void
    {
        $entries = $this->entries([1, 2, 3, 4, 6], [0.0, 0.4, 0.0, 0.39, 0.0], [0.0, 0.5, 0.2, 0.19, 0.0]);
        $p = app(Decoder::class)->select(2024, 19, $entries, 1, 2, 3);
        $this->assertSame([2, 3], [$p['selected_p2_bike'], $p['selected_p3_bike']]);
        $this->assertGreaterThan($p['selected_sum'], 0.39 + 0.5);
        $this->assertGreaterThan(0, $p['excluded_p2_only']);
    }

    public function test_exact_ties_retained_or_hashed_and_tiny_differences_are_not_rounded(): void
    {
        $entries = $this->entries([1, 2, 3, 4, 6, 7, 8], [0.0, 0.2, 0.1, 0.3, 0.3, 0.0, 0.0], [0.0, 0.0, 0.2, 0.0, 0.0, 0.4, 0.4]);
        $decoder = app(Decoder::class);
        $p = $decoder->select(2024, 19, $entries, 1, 2, 3);
        $this->enumeration($entries, 1, 2, 3, $p);
        $this->assertTrue($p['hash_selection']);
        $this->assertSame(4, $p['maximum_count']);
        $this->assertSame(hash('sha256', $p['selected_tie_input']), $p['selected_tie_sha256']);
        $this->assertSame($p, $decoder->select(2024, 19, array_reverse($entries), 1, 2, 3));
        $retain = $decoder->select(2024, 19, $entries, 1, 4, 7);
        $this->assertSame([4, 7], [$retain['selected_p2_bike'], $retain['selected_p3_bike']]);
        $this->assertTrue($retain['equal_maximum_retained']);
        $this->assertFalse($retain['hash_selection']);
        $this->assertNull($retain['selected_tie_sha256']);
        $entries[4]['position_2_probability'] += 1e-14;
        $entries[6]['position_3_probability'] += 1e-14;
        $tiny = $decoder->select(2024, 19, $entries, 1, 4, 7);
        $this->assertSame([6, 8], [$tiny['selected_p2_bike'], $tiny['selected_p3_bike']]);
        $this->assertSame(1, $tiny['maximum_count']);
        $this->assertGreaterThan(0.0, $tiny['sum_delta']);
    }

    private function entries(array $bikes, array $p2, array $p3): array
    {
        return array_map(fn ($bike, $x, $y) => ['bike' => $bike, 'position_2_probability' => $x, 'position_3_probability' => $y], $bikes, $p2, $p3);
    }

    #[DataProvider('invalidSelections')]
    public function test_invalid_selections_rejected(string $kind): void
    {
        $entries = $this->entries([1, 2, 3, 4, 6], array_fill(0, 5, 0.2), array_fill(0, 5, 0.2));
        match ($kind) {
            'duplicate' => $entries[1]['bike'] = 1, 'missing' => $entries[1]['bike'] = 9,
            'zero' => $entries[0]['bike'] = 0, 'nan' => $entries[0]['position_2_probability'] = NAN,
            'negative' => $entries[0]['position_3_probability'] = -0.1,
            'string' => $entries[0]['position_2_probability'] = '0.2', 'short' => array_pop($entries),
        };
        $this->expectException(\RuntimeException::class);
        app(Decoder::class)->select(2024, 19, $entries, 1, 2, 3);
    }

    public static function invalidSelections(): array
    {
        return array_map(fn ($kind) => [$kind], ['duplicate', 'missing', 'zero', 'nan', 'negative', 'string', 'short']);
    }

    public function test_tied_results_use_only_hit3_eligible_population_and_p1_mutation_is_rejected(): void
    {
        $input = Fixture::race();
        $row = app(Decoder::class)->decode($input, Fixture::prediction($input));
        foreach ([null, 1, 2, 3] as $tie) {
            $context = $input;
            foreach ($context['entries'] as $i => &$entry) {
                $entry['rank'] = $i + 1;
                $entry['status'] = 'FINISHED';
            }
            unset($entry);
            if ($tie !== null) {
                foreach ([$tie - 1, $tie] as $i) {
                    $context['entries'][$i]['rank'] = $tie;
                    $context['entries'][$i]['status'] = 'TIED';
                }
            }
            $metrics = app(Bt03e05MetricEvaluator::class);
            $a = $metrics->raceComparison($context, $row['baseline']);
            $b = $metrics->raceComparison($context, $row['candidate']);
            $b['baseline'] = $a['candidate'];
            $audit = array_fill_keys(['races_checked', 'Hit3_eligible_races', 'eligible_P2_hit_delta', 'eligible_P3_hit_delta', 'Hit3_position_delta'], 0);
            app(Evaluation::class)->check($row, $b, $audit);
            $this->assertSame($tie === null ? 1 : 0, $audit['Hit3_eligible_races']);
            $this->assertSame($audit['eligible_P2_hit_delta'] + $audit['eligible_P3_hit_delta'], $audit['Hit3_position_delta']);
        }
        $b['candidate']['WINNER_HIT_AT_1']['numerator'] += 1.0;
        $this->expectExceptionMessage('fixed contribution P1');
        app(Evaluation::class)->check($row, $b, $audit);
    }
}
