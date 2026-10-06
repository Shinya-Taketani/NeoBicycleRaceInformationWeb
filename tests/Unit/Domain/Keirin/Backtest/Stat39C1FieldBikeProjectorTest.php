<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Experiments\Stat39C1FieldBikeComparison\Contract;
use App\Domain\Keirin\Backtest\Experiments\Stat39C1FieldBikeComparison\FieldBikeProjector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\Stat39C1FieldBikeFixture;

class Stat39C1FieldBikeProjectorTest extends TestCase
{
    public function test_all_card_sizes_and_gap_bikes_preserve_original_values_types_and_order(): void
    {
        foreach (range(5, 9) as $count) {
            $race = iterator_to_array(Stat39C1FieldBikeFixture::races(2024, 1))[0];
            $template = $race['entries'][0];
            $race['entries'] = [];
            $bikes = [...range(1, $count - 1), 9];
            foreach (array_reverse($bikes) as $bike) {
                $entry = $template;
                $entry['id'] += $bike;
                $entry['bike'] = $bike;
                $entry['signals'] = [0, 0.0, null, 'A', ...array_fill(0, 8, -1.0)];
                $race['entries'][] = $entry;
            }
            $projected = FieldBikeProjector::race($race, 2024);
            foreach ($race['entries'] as $i => $entry) {
                $expected = $entry;
                $expected['signals'] = [...$entry['signals'], ...$entry['history'], 'N'.$count.'_B'.$entry['bike']];
                unset($expected['history'], $expected['history_status']);
                $this->assertSame($expected, $projected['entries'][$i]);
            }
            $this->assertSame('N'.$count.'_B9', $projected['entries'][0]['signals'][16]);
            $race['entries'] = array_reverse($race['entries']);
            $reordered = FieldBikeProjector::race($race, 2024);
            $this->assertSame(array_reverse($projected['entries']), $reordered['entries']);
        }
        $this->assertContains('STAT-10', Contract::features());
        $this->assertContains('STAT-39', Contract::features());
        $this->assertCount(17, Contract::features());
        $this->assertNotSame(FieldBikeProjector::category(5, 1), FieldBikeProjector::category(7, 1));
        $this->assertNotSame(FieldBikeProjector::category(7, 1), FieldBikeProjector::category(7, 2));
    }

    public function test_unavailable_history_never_recounts_the_card(): void
    {
        $race = iterator_to_array(Stat39C1FieldBikeFixture::races(2024, 1))[0];
        $this->assertSame('NO_HISTORY', $race['entries'][4]['history_status']);
        $projected = FieldBikeProjector::race($race, 2024);
        foreach ($projected['entries'] as $entry) {
            $this->assertSame('N5_B'.$entry['bike'], $entry['signals'][16]);
        }
        $this->assertSame([null, null, null, null], array_slice($projected['entries'][4]['signals'], 12, 4));
    }

    #[DataProvider('invalid')]
    public function test_invalid_cards_are_rejected_instead_of_unknown_or_renumbering(string $kind): void
    {
        $race = iterator_to_array(Stat39C1FieldBikeFixture::races(2024, 1))[0];
        match ($kind) {
            'count' => array_pop($race['entries']),
            'duplicate' => $race['entries'][1]['bike'] = 1,
            'bike0' => $race['entries'][0]['bike'] = 0,
            'bike10' => $race['entries'][0]['bike'] = 10,
            'string' => $race['entries'][0]['bike'] = '1',
            'year' => $race['year'] = 2026,
            'rank' => $race['entries'][0]['rank'] = 1,
            'result' => $race['result'] = [],
        };
        $this->expectException(\RuntimeException::class);
        FieldBikeProjector::race($race, 2024);
    }

    public static function invalid(): array
    {
        return array_map(fn ($v) => [$v], ['count', 'duplicate', 'bike0', 'bike10', 'string', 'year', 'rank', 'result']);
    }
}
