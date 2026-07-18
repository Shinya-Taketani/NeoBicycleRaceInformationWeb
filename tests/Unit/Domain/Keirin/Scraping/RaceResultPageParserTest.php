<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Scraping;

use App\Domain\Keirin\Scraping\Enums\ParsedRaceResultPageStatus;
use App\Domain\Keirin\Scraping\Exceptions\ParserException;
use App\Domain\Keirin\Scraping\Parsers\PayoutParser;
use App\Domain\Keirin\Scraping\Parsers\RaceResultPageParser;
use App\Domain\Keirin\Scraping\Parsers\RaceResultParser;
use PHPUnit\Framework\TestCase;

class RaceResultPageParserTest extends TestCase
{
    public function test_it_parses_available_result_page(): void
    {
        $page = $this->parser()->parse(file_get_contents(__DIR__.'/../../../../Fixtures/Keirin/synthetic/race_result_normal.html'));

        $this->assertSame(ParsedRaceResultPageStatus::ResultsAvailable, $page->pageStatus);
        $this->assertTrue($page->resultMarkerFound);
        $this->assertTrue($page->payoutMarkerFound);
        $this->assertCount(7, $page->results);
        $this->assertCount(3, $page->payouts);
    }

    public function test_it_distinguishes_unavailable_under_review_and_cancelled(): void
    {
        $this->assertSame(ParsedRaceResultPageStatus::Unavailable, $this->parser()->parse(file_get_contents(__DIR__.'/../../../../Fixtures/Keirin/synthetic/race_result_unavailable.html'))->pageStatus);
        $this->assertSame(ParsedRaceResultPageStatus::UnderReview, $this->parser()->parse(file_get_contents(__DIR__.'/../../../../Fixtures/Keirin/synthetic/race_result_under_review.html'))->pageStatus);
        $this->assertSame(ParsedRaceResultPageStatus::Cancelled, $this->parser()->parse(file_get_contents(__DIR__.'/../../../../Fixtures/Keirin/synthetic/race_result_cancelled.html'))->pageStatus);
    }

    public function test_it_throws_for_empty_html(): void
    {
        $this->expectException(ParserException::class);

        $this->parser()->parse('');
    }

    private function parser(): RaceResultPageParser
    {
        return new RaceResultPageParser(new RaceResultParser, new PayoutParser);
    }
}
