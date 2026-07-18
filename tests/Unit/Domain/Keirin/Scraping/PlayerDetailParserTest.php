<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Scraping;

use App\Domain\Keirin\Scraping\Parsers\PlayerDetailParser;
use PHPUnit\Framework\TestCase;

class PlayerDetailParserTest extends TestCase
{
    public function test_it_parses_real_player_detail_fixture(): void
    {
        $html = file_get_contents(__DIR__.'/../../../../Fixtures/Keirin/actual/player_detail_014934.html');

        $detail = (new PlayerDetailParser)->parse($html, 'https://www.keirin.jp/pc/racerprofile?snum=014934');

        $this->assertSame('014934', $detail->externalPlayerId);
        $this->assertSame('渋谷　錬', $detail->name);
        $this->assertSame('male', $detail->gender);
        $this->assertSame('A1', $detail->grade);
        $this->assertSame('103期', $detail->graduationPeriod);
        $this->assertNotNull($detail->recentStats);
        $this->assertGreaterThan(0, count($detail->gradeHistories));
    }
}
