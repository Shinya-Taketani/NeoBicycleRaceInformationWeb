<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Scraping;

use App\Domain\Keirin\Scraping\Enums\RaceEntryResultStatus;
use App\Domain\Keirin\Scraping\Exceptions\ParserException;
use App\Domain\Keirin\Scraping\Parsers\RaceResultParser;
use PHPUnit\Framework\TestCase;

class RaceResultParserTest extends TestCase
{
    public function test_it_parses_normal_7_car_result(): void
    {
        $results = (new RaceResultParser)->parse(file_get_contents(__DIR__.'/../../../../Fixtures/Keirin/synthetic/race_result_normal.html'));

        $this->assertCount(7, $results);
        $this->assertSame(1, $results[0]->rank);
        $this->assertSame(1, $results[0]->bikeNumber);
        $this->assertSame(RaceEntryResultStatus::Finished, $results[0]->status);
    }

    public function test_it_maps_tied_and_non_finished_statuses(): void
    {
        $results = (new RaceResultParser)->parse(file_get_contents(__DIR__.'/../../../../Fixtures/Keirin/synthetic/race_result_statuses.html'));

        $this->assertSame(RaceEntryResultStatus::Tied, $results[0]->status);
        $this->assertSame(RaceEntryResultStatus::Tied, $results[1]->status);
        $this->assertSame(RaceEntryResultStatus::Disqualified, $results[2]->status);
        $this->assertSame(RaceEntryResultStatus::DidNotStart, $results[3]->status);
        $this->assertSame(RaceEntryResultStatus::Withdrawn, $results[4]->status);
        $this->assertSame(RaceEntryResultStatus::DidNotFinish, $results[5]->status);
        $this->assertSame(RaceEntryResultStatus::Crashed, $results[6]->status);
        $this->assertSame(RaceEntryResultStatus::DidNotFinish, $results[7]->status);
    }

    public function test_it_throws_when_required_marker_is_missing(): void
    {
        $this->expectException(ParserException::class);

        (new RaceResultParser)->parse('<html><body>プロフィール</body></html>');
    }
}
