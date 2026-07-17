<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Scraping;

use App\Domain\Keirin\Scraping\Exceptions\ParserException;
use App\Domain\Keirin\Scraping\Parsers\PayoutParser;
use PHPUnit\Framework\TestCase;

class PayoutParserTest extends TestCase
{
    public function test_it_parses_multiple_payout_types_and_missing_popularity(): void
    {
        $payouts = (new PayoutParser)->parse(file_get_contents(__DIR__.'/../../../../Fixtures/Keirin/synthetic/race_result_normal.html'));

        $this->assertCount(3, $payouts);
        $this->assertSame('EXACTA', $payouts[0]->betTypeCode);
        $this->assertSame('1-2', $payouts[0]->combination);
        $this->assertSame(1230, $payouts[0]->payoutAmount);
        $this->assertNull($payouts[2]->popularity);
    }

    public function test_it_assigns_stable_sequence_for_same_bet_type(): void
    {
        $html = '<table><tbody id="pitbodyHarai"><tr><td>ワイド</td><td>1 - 2</td><td>100円</td><td>1</td></tr><tr><td>ワイド</td><td>1 - 3</td><td>200円</td><td>2</td></tr></tbody></table>';

        $payouts = (new PayoutParser)->parse($html);

        $this->assertSame(1, $payouts[0]->sequence);
        $this->assertSame(2, $payouts[1]->sequence);
        $this->assertSame('1-2', $payouts[0]->combination);
    }

    public function test_it_throws_when_required_marker_is_missing(): void
    {
        $this->expectException(ParserException::class);

        (new PayoutParser)->parse('<html><body>プロフィール</body></html>');
    }

    public function test_it_allows_no_payout_marker(): void
    {
        $this->assertSame([], (new PayoutParser)->parse('<html><body>払戻なし</body></html>'));
    }

    public function test_it_throws_when_payout_table_has_no_data_rows(): void
    {
        $this->expectException(ParserException::class);

        (new PayoutParser)->parse(file_get_contents(__DIR__.'/../../../../Fixtures/Keirin/synthetic/payout_empty_rows.html'));
    }

    public function test_it_throws_for_unknown_bet_type(): void
    {
        $this->expectException(ParserException::class);

        (new PayoutParser)->parse(file_get_contents(__DIR__.'/../../../../Fixtures/Keirin/synthetic/payout_unknown_type.html'));
    }

    public function test_it_throws_for_invalid_amount(): void
    {
        $this->expectException(ParserException::class);

        (new PayoutParser)->parse(file_get_contents(__DIR__.'/../../../../Fixtures/Keirin/synthetic/payout_invalid_amount.html'));
    }

    public function test_it_throws_for_partial_invalid_rows(): void
    {
        $this->expectException(ParserException::class);

        (new PayoutParser)->parse(file_get_contents(__DIR__.'/../../../../Fixtures/Keirin/synthetic/payout_partial_invalid.html'));
    }
}
