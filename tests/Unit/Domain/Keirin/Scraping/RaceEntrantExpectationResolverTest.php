<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Scraping;

use App\Domain\Keirin\Scraping\Enums\RaceEntrantExpectationSource;
use App\Domain\Keirin\Scraping\Exceptions\RaceResultCompletenessException;
use App\Domain\Keirin\Scraping\Services\RaceEntrantExpectationResolver;
use PHPUnit\Framework\TestCase;

class RaceEntrantExpectationResolverTest extends TestCase
{
    public function test_race_entries_take_priority_and_preserve_the_exact_bike_set(): void
    {
        $expected = (new RaceEntrantExpectationResolver)->resolveFromValues(7, [7, 1, 5, 3, 2, 6, 4]);

        $this->assertSame(7, $expected->count);
        $this->assertSame([1, 2, 3, 4, 5, 6, 7], $expected->bikeNumbers);
        $this->assertSame(RaceEntrantExpectationSource::RaceEntries, $expected->source);
    }

    public function test_entrant_count_is_used_only_when_entries_are_absent(): void
    {
        $expected = (new RaceEntrantExpectationResolver)->resolveFromValues(9, []);

        $this->assertSame(9, $expected->count);
        $this->assertNull($expected->bikeNumbers);
        $this->assertSame(RaceEntrantExpectationSource::RaceEntrantCount, $expected->source);
    }

    public function test_invalid_expectation_inputs_are_rejected(): void
    {
        $invalidCases = [
            'missing expected count' => [null, []],
            'unsupported entrant count' => [4, []],
            'entry count mismatch' => [9, [1, 2, 3, 4, 5, 6, 7]],
            'duplicate bike number' => [7, [1, 2, 3, 4, 5, 6, 6]],
            'non-contiguous bike numbers' => [5, [1, 2, 3, 4, 6]],
            'missing bike number' => [7, [1, 2, 3, 4, 5, 6, null]],
            'out-of-range bike number' => [7, [1, 2, 3, 4, 5, 6, 10]],
        ];

        foreach ($invalidCases as $case => [$entrantCount, $bikeNumbers]) {
            try {
                (new RaceEntrantExpectationResolver)->resolveFromValues($entrantCount, $bikeNumbers);
                $this->fail("RaceResultCompletenessException was not thrown for {$case}.");
            } catch (RaceResultCompletenessException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_five_through_nine_entrants_are_supported_with_or_without_entry_rows(): void
    {
        $resolver = new RaceEntrantExpectationResolver;
        foreach ([5, 6, 7, 8, 9] as $count) {
            $fromEntries = $resolver->resolveFromValues($count, range(1, $count));
            $fromCount = $resolver->resolveFromValues($count, []);

            $this->assertSame($count, $fromEntries->count);
            $this->assertSame(range(1, $count), $fromEntries->bikeNumbers);
            $this->assertSame($count, $fromCount->count);
            $this->assertNull($fromCount->bikeNumbers);
        }
    }
}
