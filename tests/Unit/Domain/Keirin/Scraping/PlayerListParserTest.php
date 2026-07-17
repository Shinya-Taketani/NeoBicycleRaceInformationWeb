<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Scraping;

use App\Domain\Keirin\Scraping\Exceptions\ParserException;
use App\Domain\Keirin\Scraping\Parsers\PlayerListParser;
use PHPUnit\Framework\TestCase;

class PlayerListParserTest extends TestCase
{
    public function test_it_parses_real_player_search_fixture(): void
    {
        $html = file_get_contents(__DIR__.'/../../../../Fixtures/Keirin/player_search_s_class.html');

        $page = (new PlayerListParser)->parse($html, 'https://keirin.jp/sp/racersearchresult?dppg=1&seibetuCD=1&kyuhanCD=15&stgt=1');

        $this->assertSame(9, $page->totalCount);
        $this->assertSame(1, $page->currentPage);
        $this->assertCount(9, $page->players);
        $this->assertSame('015035', $page->players[0]->externalPlayerId);
        $this->assertSame('阿部　拓真', $page->players[0]->name);
        $this->assertSame('SS', $page->players[0]->grade);
        $this->assertSame('male', $page->players[0]->gender);
    }

    public function test_it_rejects_too_broad_search_results(): void
    {
        $this->expectException(ParserException::class);

        (new PlayerListParser)->parse('<html><body>検索結果が1000件を超えています。(2200件)</body></html>', 'https://keirin.jp/sp/racersearchresult');
    }
}
