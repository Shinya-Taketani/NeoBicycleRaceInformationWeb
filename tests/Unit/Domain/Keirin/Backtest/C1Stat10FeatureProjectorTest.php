<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Experiments\C1Stat10Ablation\Contract;
use App\Domain\Keirin\Backtest\Experiments\C1Stat10Ablation\FeatureProjector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\C1Stat10AblationFixture;

class C1Stat10FeatureProjectorTest extends TestCase
{
    public function test_named_map_retains_types_zero_null_and_last_history_value(): void
    {
        $full = [1, 2.0, 'excluded', null, 0, 0.0, 'A', 8, 9, 10, 11, 12, 13, 14, 15, 16];
        $this->assertSame([1, 2.0, null, 0, 0.0, 'A', 8, 9, 10, 11, 12, 13, 14, 15, 16], FeatureProjector::vector($full));
        $this->assertSame(['full_index' => 2, 'retained_index' => null], Contract::projection()['full_to_retained_mapping']['STAT-10']);
        $this->assertCount(16, Contract::baselineFeatures());
        $this->assertCount(15, Contract::features());
        $this->assertSame(false, Contract::plan()['baseline_retraining']);
    }

    #[DataProvider('invalidNames')]
    public function test_wrong_offset_duplicate_unknown_and_order_rejected(string $kind): void
    {
        $names = Contract::baselineFeatures();
        match ($kind) {
            'order' => $names = array_reverse($names),
            'offset' => [$names[1], $names[2]] = [$names[2], $names[1]],
            'duplicate' => $names[2] = $names[1],
            'unknown' => $names[2] = 'UNKNOWN',
        };
        $this->expectException(RuntimeException::class);
        FeatureProjector::vector(range(0, 15), $names);
    }

    public static function invalidNames(): array
    {
        return array_map(fn ($v) => [$v], ['order', 'offset', 'duplicate', 'unknown']);
    }

    #[DataProvider('invalidOriginal')]
    public function test_unused_stat10_still_validates_full_original(string $kind): void
    {
        $race = iterator_to_array(C1Stat10AblationFixture::races(2024, 1))[0];
        match ($kind) {
            'missing' => array_splice($race['entries'][0]['signals'], 2, 1),
            'type' => $race['entries'][0]['signals'][2] = [],
            'outcome' => $race['entries'][0]['rank'] = 1,
            'year' => $race['year'] = 2026,
        };
        $this->expectException(RuntimeException::class);
        FeatureProjector::race($race, 2024);
    }

    public static function invalidOriginal(): array
    {
        return array_map(fn ($v) => [$v], ['missing', 'type', 'outcome', 'year']);
    }
}
