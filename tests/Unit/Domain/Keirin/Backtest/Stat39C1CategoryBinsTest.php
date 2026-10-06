<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Calculators\EffectBinBuilder;
use App\Domain\Keirin\Backtest\Calculators\ExternalSortEffectBinBoundaryProvider;
use App\Domain\Keirin\Backtest\Experiments\Stat39C1FieldBikeComparison\CategoryBins;
use App\Domain\Keirin\Backtest\Experiments\Stat39C1FieldBikeComparison\Contract;
use App\Domain\Keirin\Backtest\Experiments\Stat39C1FieldBikeComparison\Dataset;
use App\Domain\Keirin\Backtest\Experiments\Stat39C1FieldBikeComparison\LayoutBuilder;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class Stat39C1CategoryBinsTest extends TestCase
{
    public function test_all_45_categories_are_observed_only_ordered_positive_and_not_shared_numeric_bins(): void
    {
        $values = [];
        foreach (range(5, 9) as $n) {
            foreach (range(1, 9) as $bike) {
                $values[] = 'N'.$n.'_B'.$bike;
            }
        }
        $bins = CategoryBins::build([...array_reverse($values), 'N5_B1']);
        $this->assertCount(45, $bins);
        $this->assertSame($values, array_map(fn ($b) => $b->categoryValue, $bins));
        $this->assertSame(46, array_sum(array_map(fn ($b) => $b->trainingSampleCount, $bins)));
        foreach ($bins as $i => $bin) {
            $this->assertSame($i + 1, $bin->index);
            $this->assertSame('CATEGORY', $bin->kind);
            $this->assertNull($bin->lowerBound);
            $this->assertNull($bin->upperBound);
            $this->assertSame($i === 0 ? 2 : 1, $bin->trainingSampleCount);
        }
        $this->assertCount(1, CategoryBins::build(['N5_B9']));
        $this->expectExceptionMessage('High-cardinality effect bins must be numeric');
        (new ExternalSortEffectBinBoundaryProvider)->build($values);
    }

    public function test_training_only_layout_keeps_unseen_card_entries_and_audits_them(): void
    {
        $builder = new EffectBinBuilder(new ExternalSortEffectBinBoundaryProvider);
        $training = fn () => [['entries' => array_map(fn ($bike) => ['signals' => [...array_fill(0, 16, 0), 'N5_B'.$bike]], range(1, 5))]];
        $layout = (new LayoutBuilder($builder))->build($training);
        $this->assertSame(5, $layout->trainingEntryCount);
        $this->assertSame(5, array_sum(array_column($layout->canonicalBins()[Contract::EXTRA_FEATURE], 'training_support')));
        $this->assertSame([], $layout->smoothEdges());
        $rows = fn () => [['entries' => array_map(fn ($bike) => ['bike' => $bike, 'signals' => [...array_fill(0, 16, 0), 'N5_B'.$bike]], [1, 2, 3, 4, 9])]];
        $binned = iterator_to_array((new Dataset)->binned($rows, $layout, $builder));
        $this->assertCount(5, $binned[0]['entries']);
        $this->assertNull($binned[0]['entries'][4]['bins'][16]);
        $this->assertNotNull($binned[0]['entries'][0]['bins'][16]);
        $audit = (new Dataset)->categoryUsage($rows, $layout);
        $this->assertSame(1, $audit['unseen_entries']);
        $this->assertSame(1, $audit['races_with_unseen']);
        $this->assertSame(['N5_B9' => 1], $audit['unseen_category_counts']);
        $this->assertNotContains('N5_B9', array_column($layout->canonicalBins()[Contract::EXTRA_FEATURE], 'category_value'));
        $this->expectException(InvalidArgumentException::class);
        $layout->assign([...array_fill(0, 16, 0), 'N05_B9'], $builder);
    }

    #[DataProvider('invalidValues')]
    public function test_noncanonical_values_are_rejected_not_unseen(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        CategoryBins::build([$value]);
    }

    public static function invalidValues(): array
    {
        return array_map(fn ($v) => [$v], [null, 51, 71.0, 'N05_B1', 'N7_B01', ' N7_B1', 'N7_B1 ', 'N4_B1', 'N5_B0', 'N9_B10', 'UNKNOWN', true, []]);
    }
}
