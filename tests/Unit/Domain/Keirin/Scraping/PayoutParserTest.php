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

    public function test_it_preserves_column_positions_when_popularity_is_empty(): void
    {
        $html = '<table><tbody id="pitbodyHarai"><tr><td>2車単</td><td>1-2</td><td>1,230円</td><td></td></tr></tbody></table>';

        $payout = (new PayoutParser)->parse($html)[0];

        $this->assertSame('EXACTA', $payout->betTypeCode);
        $this->assertSame('1-2', $payout->combination);
        $this->assertSame(1230, $payout->payoutAmount);
        $this->assertNull($payout->popularity);
    }

    public function test_it_rejects_missing_required_cells_without_shifting_columns(): void
    {
        $invalidRows = [
            'bet type' => '<td></td><td>1-2</td><td>100円</td><td>1</td>',
            'combination' => '<td>2車単</td><td></td><td>100円</td><td>1</td>',
            'amount' => '<td>2車単</td><td>1-2</td><td></td><td>1</td>',
            'missing popularity column' => '<td>2車単</td><td>1-2</td><td>100円</td>',
        ];

        foreach ($invalidRows as $case => $cells) {
            try {
                (new PayoutParser)->parse("<table><tbody id=\"pitbodyHarai\"><tr>{$cells}</tr></tbody></table>");
                $this->fail("ParserException was not thrown for missing {$case}.");
            } catch (ParserException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
