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
            'unsupported entrant count' => [6, []],
            'entry count mismatch' => [9, [1, 2, 3, 4, 5, 6, 7]],
            'duplicate bike number' => [7, [1, 2, 3, 4, 5, 6, 6]],
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
}
