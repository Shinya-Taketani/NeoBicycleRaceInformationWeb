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

    public function test_it_parses_normal_9_car_result(): void
    {
        $results = (new RaceResultParser)->parse(file_get_contents(__DIR__.'/../../../../Fixtures/Keirin/synthetic/race_result_normal_9.html'));

        $this->assertCount(9, $results);
        $this->assertSame(9, $results[8]->bikeNumber);
        $this->assertSame(9, $results[8]->rank);
    }

    public function test_it_accepts_confirmed_full_status_labels(): void
    {
        $html = '<table><tbody id="pitbodyBs">'
            .'<tr><td>失格</td><td></td><td>1</td><td>A</td></tr>'
            .'<tr><td>欠場</td><td></td><td>2</td><td>B</td></tr>'
            .'<tr><td>取消</td><td></td><td>3</td><td>C</td></tr>'
            .'<tr><td>棄権</td><td></td><td>4</td><td>D</td></tr>'
            .'<tr><td>落車</td><td></td><td>5</td><td>E</td></tr>'
            .'</tbody></table>';

        $results = (new RaceResultParser)->parse($html);

        $this->assertSame(RaceEntryResultStatus::Disqualified, $results[0]->status);
        $this->assertSame(RaceEntryResultStatus::DidNotStart, $results[1]->status);
        $this->assertSame(RaceEntryResultStatus::Withdrawn, $results[2]->status);
        $this->assertSame(RaceEntryResultStatus::DidNotFinish, $results[3]->status);
        $this->assertSame(RaceEntryResultStatus::Crashed, $results[4]->status);
    }

    public function test_it_rejects_any_incomplete_or_invalid_result_row(): void
    {
        $fixture = file_get_contents(__DIR__.'/../../../../Fixtures/Keirin/synthetic/race_result_normal.html');
        $validRow = '<tr><td>7</td><td></td><td>7</td><td>渡辺　七郎</td><td></td></tr>';
        $invalidRows = [
            'column shortage' => '<tr><td>7</td><td></td><td>7</td></tr>',
            'empty bike number' => '<tr><td>7</td><td></td><td></td><td>渡辺　七郎</td><td></td></tr>',
            'non-numeric bike number' => '<tr><td>7</td><td></td><td>車七</td><td>渡辺　七郎</td><td></td></tr>',
            'out-of-range bike number' => '<tr><td>7</td><td></td><td>10</td><td>渡辺　七郎</td><td></td></tr>',
            'duplicate bike number' => '<tr><td>7</td><td></td><td>1</td><td>渡辺　七郎</td><td></td></tr>',
            'empty rank/status' => '<tr><td></td><td></td><td>7</td><td>渡辺　七郎</td><td></td></tr>',
            'unknown status' => '<tr><td>不明</td><td></td><td>7</td><td>渡辺　七郎</td><td></td></tr>',
            'empty player identifier' => '<tr><td>7</td><td></td><td>7</td><td></td><td></td></tr>',
            'duplicate player identifier' => '<tr><td>7</td><td></td><td>7</td><td>山田　太郎</td><td></td></tr>',
        ];

        foreach ($invalidRows as $case => $invalidRow) {
            try {
                (new RaceResultParser)->parse(str_replace($validRow, $invalidRow, $fixture));
                $this->fail("ParserException was not thrown for {$case}.");
            } catch (ParserException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_it_throws_when_required_marker_is_missing(): void
    {
        $this->expectException(ParserException::class);

        (new RaceResultParser)->parse('<html><body>プロフィール</body></html>');
    }
}
