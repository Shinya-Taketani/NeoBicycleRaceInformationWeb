<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Statistics;

use App\Domain\Keirin\Statistics\AgariPlayerHistory\Exact;
use App\Domain\Keirin\Statistics\AgariPlayerHistory\History;
use Brick\Math\BigRational;
use PHPUnit\Framework\TestCase;

class AgariPlayerHistoryTest extends TestCase
{
    public function test_exact_mean_median_population_variance_and_half_even_without_float(): void
    {
        $values = array_map(fn ($v) => Exact::value(BigRational::of($v)), ['1/3', '2/3', '1']);
        $result = Exact::statistics($values);
        $this->assertSame(['numerator' => '2', 'denominator' => '3', 'decimal' => '0.666666666667'], $result['mean']);
        $this->assertSame($result['mean'], $result['median']);
        $this->assertSame(['numerator' => '2', 'denominator' => '27', 'decimal' => '0.074074074074'], $result['population_variance']);
        $this->assertSame('0.000000000000', Exact::value(BigRational::of('1/2000000000000'))['decimal']);
        $this->assertSame('0.000000000002', Exact::value(BigRational::of('3/2000000000000'))['decimal']);
        $this->assertSame('1/2', (string) Exact::rational(Exact::statistics(array_slice($values, 0, 2))['median']));
        $this->assertNull(Exact::statistics([$values[0]])['population_variance']);
        $this->assertSame(['mean' => null, 'median' => null, 'population_variance' => null], Exact::statistics([]));
    }

    public function test_null_meeting_consumes_slot_and_nonoverlapping_trend_needs_all_six_values(): void
    {
        $rows = [];
        for ($i = 12; $i >= 1; $i--) {
            $rows[] = ['id' => (string) $i, 'meeting' => ['meeting_id' => $i, 'starts_on' => sprintf('2023-%02d-01', $i),
                'ends_on' => sprintf('2023-%02d-03', $i)], 'meeting_percentile_mean' => Exact::value(BigRational::ofFraction($i, 12)),
                'adopted_races' => $i, 'exclusion_reasons' => [], 'context_flags' => []];
        }
        $history = History::calculate($rows, '2024-01-01', '2022-01-01');
        $this->assertSame([3, 6, 12], array_column($history['windows'], 'observed_meetings'));
        $this->assertSame('11/12', (string) Exact::rational($history['windows'][3]['mean']));
        $this->assertSame('1/4', (string) Exact::rational($history['recent3_minus_previous3_meeting_percentile']));
        $rows[1]['meeting_percentile_mean'] = null;
        $history = History::calculate($rows, '2024-01-01', '2022-01-01');
        $this->assertSame([12, 11, 10], array_column($history['windows'][3]['meetings'], 'meeting_id'));
        $this->assertSame(2, $history['windows'][3]['valid_meetings']);
        $this->assertTrue($history['windows'][3]['flags']['missing_meeting_values']);
        $this->assertFalse($history['windows'][3]['flags']['insufficient_observed_meetings']);
        $this->assertNull($history['recent3_minus_previous3_meeting_percentile']);
        $this->assertSame('MISSING_MEETING_VALUES', $history['trend_null_reason']);
    }
}
