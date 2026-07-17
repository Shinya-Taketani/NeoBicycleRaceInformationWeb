<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Scraping;

use App\Domain\Keirin\Scraping\Parsers\RaceScheduleParser;
use PHPUnit\Framework\TestCase;

class RaceScheduleParserTest extends TestCase
{
    public function test_it_parses_real_race_schedule_fixture(): void
    {
        $html = file_get_contents(__DIR__.'/../../../../Fixtures/Keirin/race_schedule_2026_07.html');

        $items = (new RaceScheduleParser)->parse($html);

        $this->assertNotEmpty($items);
        $this->assertNotSame('', $items[0]->trackCode);
        $this->assertNotSame('', $items[0]->trackName);
        $this->assertNotNull($items[0]->encryptedParameter);
    }
}
