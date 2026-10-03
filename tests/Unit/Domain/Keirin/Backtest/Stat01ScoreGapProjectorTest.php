<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Experiments\Stat01C1ScoreGapComparison\ScoreGapProjector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class Stat01ScoreGapProjectorTest extends TestCase
{
    #[DataProvider('knownScores')]
    public function test_all_five_to_nine_entrants_keep_signed_raw_scale(array $scores, array $expected): void
    {
        $race = self::race($scores);
        $before = $race;
        $rows = ScoreGapProjector::project($race, 2024);
        $this->assertSame($expected, array_column($rows, 'gap'));
        $this->assertSame($before, $race);
        $this->assertSame(array_column($race['entries'], 'id'), array_column($rows, 'entry_id'));
        foreach ($rows as $row) {
            $this->assertSame(count($scores), $row['entrant_count']);
            $this->assertSame(array_sum(array_map(floatval(...), $scores)) / count($scores), $row['mean']);
        }
        $this->assertSame($rows, ScoreGapProjector::project($race, 2024));
    }

    public static function knownScores(): array
    {
        return [
            [[102, 101, 100, 99, 98], [2.0, 1.0, 0.0, -1.0, -2.0]],
            [[120, 110, 100, 90, 80], [20.0, 10.0, 0.0, -10.0, -20.0]],
            [[100, 100, 100, 100, 100], [0.0, 0.0, 0.0, 0.0, 0.0]],
            [[105, 103, 101, 99, 97, 95], [5.0, 3.0, 1.0, -1.0, -3.0, -5.0]],
            [[103, 102, 101, 100, 99, 98, 97], [3.0, 2.0, 1.0, 0.0, -1.0, -2.0, -3.0]],
            [[107, 105, 103, 101, 99, 97, 95, 93], [7.0, 5.0, 3.0, 1.0, -1.0, -3.0, -5.0, -7.0]],
            [[104, 103, 102, 101, 100, 99, 98, 97, 96], [4.0, 3.0, 2.0, 1.0, 0.0, -1.0, -2.0, -3.0, -4.0]],
        ];
    }

    public function test_shift_scale_reorder_history_and_outcome_isolation(): void
    {
        $scores = [102, 101, 100, 99, 98];
        $a = ScoreGapProjector::project(self::race($scores), 2024);
        $b = ScoreGapProjector::project(self::race(array_map(fn ($v) => $v + 32, $scores)), 2024);
        $c = ScoreGapProjector::project(self::race(array_map(fn ($v) => $v * 4, $scores)), 2024);
        $this->assertSame(array_column($a, 'gap'), array_column($b, 'gap'));
        $this->assertSame(array_map(fn ($v) => $v * 4, array_column($a, 'gap')), array_column($c, 'gap'));
        $race = self::race($scores);
        $race['entries'][0]['history'] = [null, null, null, null];
        $race['entries'][0]['history_status'] = 'NO_HISTORY';
        $this->assertSame($a, ScoreGapProjector::project($race, 2024));
        $race['entries'] = array_reverse($race['entries']);
        $this->assertSame(array_reverse($a), ScoreGapProjector::project($race, 2024));
        $race['entries'][0]['rank'] = 1;
        $this->expectException(RuntimeException::class);
        ScoreGapProjector::project($race, 2024);
    }

    public function test_binary64_path_no_rounding_clipping_or_epsilon_zero(): void
    {
        $scores = [100.00000000001, 100.0, 99.99999999999, 100.0, 100.0];
        $rows = ScoreGapProjector::project(self::race($scores), 2024);
        $mean = array_sum($scores) / 5;
        foreach ($rows as $i => $row) {
            $this->assertSame($scores[$i] - $mean, $row['gap']);
        }
        $this->assertGreaterThan(0.0, $rows[0]['gap']);
        $this->assertLessThan(0.0, $rows[2]['gap']);
        $zeros = ScoreGapProjector::project(self::race(array_fill(0, 5, -0.0)), 2024);
        $this->assertSame('[0.0,0.0,0.0,0.0,0.0]', json_encode(array_column($zeros, 'gap'), JSON_PRESERVE_ZERO_FRACTION));
        $reordered = self::race([0.1, 0.2, 0.3, 0.4, 0.5]);
        $before = ScoreGapProjector::project($reordered, 2024);
        $reordered['entries'] = array_reverse($reordered['entries']);
        // Floating permutations are compared numerically, not assumed bit-exact.
        foreach (ScoreGapProjector::project($reordered, 2024) as $i => $row) {
            $this->assertEqualsWithDelta($before[4 - $i]['gap'], $row['gap'], 1e-15);
        }
    }

    #[DataProvider('invalidInput')]
    public function test_invalid_scores_overflow_count_identity_and_year_are_rejected(string $kind): void
    {
        $race = self::race([102, 101, 100, 99, 98]);
        match ($kind) {
            'null' => $race['entries'][0]['raw'] = null,
            'string' => $race['entries'][0]['raw'] = '102',
            'nan' => $race['entries'][0]['raw'] = NAN,
            'inf' => $race['entries'][0]['raw'] = INF,
            'overflow' => $race = self::race(array_fill(0, 5, PHP_FLOAT_MAX)),
            'four' => array_pop($race['entries']),
            'ten' => $race['entries'] = [...$race['entries'], ...$race['entries']],
            'duplicate' => $race['entries'][1] = $race['entries'][0],
            'year' => $race['year'] = 2026,
        };
        $this->expectException(RuntimeException::class);
        ScoreGapProjector::project($race, 2024);
    }

    public static function invalidInput(): array
    {
        return array_map(fn ($kind) => [$kind], ['null', 'string', 'nan', 'inf', 'overflow', 'four', 'ten', 'duplicate', 'year']);
    }

    private static function race(array $scores): array
    {
        $mean = array_sum(array_map(floatval(...), $scores)) / count($scores);
        $sd = sqrt(array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $scores)) / count($scores));
        $entries = [];
        foreach ($scores as $i => $score) {
            $entries[] = ['id' => 100 + $i, 'bike' => $i + 1, 'raw' => $score, 'stat01_rank' => $i + 1,
                'anchor' => is_finite($sd) && $sd > 0 ? ($score - $mean) / $sd : 0.0,
                'anchor_status' => $sd > 0 ? 'AVAILABLE' : 'ZERO_VARIANCE',
                'signals' => array_fill(0, 12, 0), 'history' => [1, 2, 3, 4], 'history_status' => 'AVAILABLE'];
        }

        return ['year' => 2024, 'race_id' => 1, 'entries' => $entries];
    }
}
