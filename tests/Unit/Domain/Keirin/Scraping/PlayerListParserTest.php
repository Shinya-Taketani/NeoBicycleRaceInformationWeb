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
        $html = file_get_contents(__DIR__.'/../../../../Fixtures/Keirin/actual/player_search_s_class.html');

        $page = (new PlayerListParser)->parse($html, 'https://keirin.jp/sp/racersearchresult?dppg=1&seibetuCD=1&kyuhanCD=15&stgt=1');

        $this->assertSame(9, $page->totalCount);
        $this->assertSame(1, $page->currentPage);
        $this->assertSame(1, $page->lastPage);
        $this->assertCount(9, $page->players);
        $this->assertSame('015035', $page->players[0]->externalPlayerId);
        $this->assertSame('阿部　拓真', $page->players[0]->name);
        $this->assertSame('SS', $page->players[0]->grade);
        $this->assertSame('male', $page->players[0]->gender);
    }

    public function test_it_rejects_missing_last_page_when_total_count_exceeds_parsed_players(): void
    {
        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('totalCount=20 exceeded parsed players=10');

        (new PlayerListParser)->parse(
            file_get_contents(__DIR__.'/../../../../Fixtures/Keirin/synthetic/player_search_missing_pagination_20_of_10.html'),
            'https://keirin.jp/sp/racersearchresult?dppg=1',
        );
    }

    public function test_it_rejects_missing_total_count_and_last_page(): void
    {
        $html = file_get_contents(__DIR__.'/../../../../Fixtures/Keirin/actual/player_search_s_class.html');
        $html = preg_replace('/<span id="UNQ_orlabel_2">9<\/span>件見つかりました。/u', '', $html);

        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('Player totalCount and lastPage could not be determined.');

        (new PlayerListParser)->parse((string) $html, 'https://keirin.jp/sp/racersearchresult?dppg=1');
    }

    public function test_explicit_page_fraction_takes_priority(): void
    {
        $html = file_get_contents(__DIR__.'/../../../../Fixtures/Keirin/actual/player_search_s_class.html');

        $page = (new PlayerListParser)->parse($html.'<div>ページ 1/3</div>', 'https://keirin.jp/sp/racersearchresult?dppg=1');

        $this->assertSame(1, $page->currentPage);
        $this->assertSame(3, $page->lastPage);
    }

    public function test_it_rejects_single_page_fallback_when_a_next_page_indicator_exists(): void
    {
        $html = file_get_contents(__DIR__.'/../../../../Fixtures/Keirin/actual/player_search_s_class.html');
        $indicators = [
            'link' => '<a href="/sp/racersearchresult?dppg=2">次へ</a>',
            'button' => '<button type="button" onclick="pageChange(2)">次ページ</button>',
            'form value' => '<input type="submit" name="dppg" value="2">',
        ];

        foreach ($indicators as $case => $indicator) {
            try {
                (new PlayerListParser)->parse($html.$indicator, 'https://keirin.jp/sp/racersearchresult?dppg=1');
                $this->fail("ParserException was not thrown for {$case}.");
            } catch (ParserException $exception) {
                $this->assertStringContainsString('a next-page indicator was present', $exception->getMessage());
            }
        }
    }

    public function test_it_rejects_too_broad_search_results(): void
    {
        $this->expectException(ParserException::class);

        (new PlayerListParser)->parse('<html><body>検索結果が1000件を超えています。(2200件)</body></html>', 'https://keirin.jp/sp/racersearchresult');
    }

    public function test_it_parses_domestic_and_foreign_riders_by_span_id_suffix(): void
    {
        $page = (new PlayerListParser)->parse(
            $this->foreignRiderFixture(),
            'https://keirin.jp/sp/racersearchresult?dppg=23&seibetuCD=1&kyuhanCD=12&stgt=1',
        );

        $this->assertSame(458, $page->totalCount);
        $this->assertSame(23, $page->currentPage);
        $this->assertSame(46, $page->lastPage);
        $this->assertCount(2, $page->players);

        $domestic = $page->players[0];
        $this->assertSame('コクナイ　センシュ', $domestic->nameKana);
        $this->assertSame('国内　選手', $domestic->name);
        $this->assertSame('900001', $domestic->externalPlayerId);
        $this->assertSame('S2', $domestic->grade);
        $this->assertSame('関東', $domestic->district);
        $this->assertSame('東京都', $domestic->prefecture);
        $this->assertSame('100期', $domestic->graduationPeriod);
        $this->assertSame(30, $domestic->age);
        $this->assertSame('京王閣', $domestic->homeBank);
        $this->assertSame('逃', $domestic->ridingStyle);

        $foreign = $page->players[1];
        $this->assertNull($foreign->nameKana);
        $this->assertSame('テスト　ライダー', $foreign->name);
        $this->assertSame('900002', $foreign->externalPlayerId);
        $this->assertSame('S2', $foreign->grade);
        $this->assertSame('外国', $foreign->district);
        $this->assertSame('イギリス', $foreign->prefecture);
        $this->assertNull($foreign->graduationPeriod);
        $this->assertSame(29, $foreign->age);
        $this->assertNull($foreign->homeBank);
        $this->assertSame('両', $foreign->ridingStyle);
    }

    public function test_it_rejects_registration_number_mismatch(): void
    {
        $html = str_replace(
            'id="j_idt25:1:UNQ_orlabel_9">900002',
            'id="j_idt25:1:UNQ_orlabel_9">900099',
            $this->foreignRiderFixture(),
        );

        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('Player registration number mismatch for 900002');

        (new PlayerListParser)->parse($html, 'https://keirin.jp/sp/racersearchresult?dppg=23');
    }

    public function test_it_rejects_missing_required_name_id(): void
    {
        $html = str_replace('j_idt25:1:UNQ_orlabel_8', 'j_idt25:1:UNQ_orlabel_7', $this->foreignRiderFixture());

        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('Player name was missing for 900002');

        (new PlayerListParser)->parse($html, 'https://keirin.jp/sp/racersearchresult?dppg=23');
    }

    public function test_foreign_rider_age_is_null_when_empty_or_dash(): void
    {
        foreach (['－', ''] as $ageText) {
            $html = str_replace(
                'id="j_idt25:1:UNQ_orlabel_14">29',
                'id="j_idt25:1:UNQ_orlabel_14">'.$ageText,
                $this->foreignRiderFixture(),
            );

            $page = (new PlayerListParser)->parse($html, 'https://keirin.jp/sp/racersearchresult?dppg=23');

            $this->assertNull($page->players[1]->age);
        }
    }

    public function test_it_rejects_a_player_row_with_an_invalid_onclick_identifier(): void
    {
        $html = str_replace("sensyuLink('900002')", "sensyuLink('invalid')", $this->foreignRiderFixture());

        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('Player external ID could not be parsed from onclick');

        (new PlayerListParser)->parse($html, 'https://keirin.jp/sp/racersearchresult?dppg=23');
    }

    private function foreignRiderFixture(): string
    {
        return file_get_contents(__DIR__.'/../../../../Fixtures/Keirin/synthetic/player_search_foreign_rider_page.html');
    }
}
