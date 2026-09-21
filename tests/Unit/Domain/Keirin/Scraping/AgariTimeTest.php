<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Scraping;

use App\Domain\Keirin\Scraping\Enums\RaceEntryResultStatus;
use App\Domain\Keirin\Scraping\Parsers\EmbeddedJsonExtractor;
use App\Domain\Keirin\Scraping\Parsers\RaceLiveResultParser;
use App\Domain\Keirin\Scraping\Parsers\RaceResultParser;
use App\Domain\Keirin\Scraping\Support\AgariTimeNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AgariTimeTest extends TestCase
{
    #[DataProvider('values')]
    public function test_normalizes_without_float_rounding_or_losing_raw(?string $raw, ?string $seconds, string $status): void
    {
        $value = (new AgariTimeNormalizer)->normalize($raw, RaceEntryResultStatus::Finished);
        $this->assertSame(['agari_raw_text' => $raw, 'agari_time_seconds' => $seconds, 'agari_status' => $status], $value);
    }

    public static function values(): array
    {
        return [[null, null, 'MISSING'], ['', null, 'MISSING'], ['  ', null, 'MISSING'], ['－', null, 'MISSING'],
            ['11.5000', '11.5', 'VALID'], [' 0011.5000 ', '11.5', 'VALID'], ['12', '12', 'VALID'],
            ['11.12345678901234567890123456789', '11.12345678901234567890123456789', 'VALID'],
            ['0.000000000000000000001', '0.000000000000000000001', 'VALID'],
            ['0', null, 'INVALID_FORMAT'], ['0.00', null, 'INVALID_FORMAT'], ['-11.5', null, 'INVALID_FORMAT'],
            ['NaN', null, 'INVALID_FORMAT'], ['INF', null, 'INVALID_FORMAT'], ['unknown', null, 'INVALID_FORMAT'],
            ['1e1', null, 'INVALID_FORMAT'], ['+11.5', null, 'INVALID_FORMAT']];
    }

    public function test_abnormal_numeric_is_observed_but_blank_is_missing(): void
    {
        $normalizer = new AgariTimeNormalizer;
        foreach (RaceEntryResultStatus::cases() as $status) {
            $expected = in_array($status, [RaceEntryResultStatus::Finished, RaceEntryResultStatus::Tied], true)
                ? 'VALID' : 'OBSERVED_ABNORMAL_RESULT';
            $this->assertSame($expected, $normalizer->normalize('11.5', $status)['agari_status']);
            $this->assertSame('MISSING', $normalizer->normalize('', $status)['agari_status']);
        }
    }

    public function test_live_parser_keeps_the_original_agari_display(): void
    {
        $fixture = file_get_contents(base_path('tests/Fixtures/Keirin/synthetic/race-sync-pj0326.html'));
        $extractor = new EmbeddedJsonExtractor;
        $context = $extractor->extract($fixture, 'PC0201');
        $data = $extractor->extract($fixture, 'PJ0326');
        foreach (['11.50', '', '0', '-1.0', 'unknown', '－', '11.1234567890123456789'] as $i => $value) {
            $data['tyakujyunItemSubData'][$i]['agari'] = $value;
        }
        $html = '<script>jsonData["PC0201"] = '.json_encode($context).'; jsonData["PJ0326"] = '.json_encode($data).';</script>';
        $page = app(RaceLiveResultParser::class)->parse($html);
        $this->assertCount(7, $page->resultPage->results);
        foreach ($page->resultPage->results as $i => $result) {
            $this->assertSame($data['tyakujyunItemSubData'][$i]['agari'], $result->agariRawText);
        }
        $this->assertNull($page->resultPage->results[1]->finishTime);
    }

    #[DataProvider('headerPositions')]
    public function test_html_agari_is_header_driven_and_survives_ties(int $position): void
    {
        $headers = ['着', 'H/B', '車番', '選手名', '余分'];
        $row = ['1', '', '1', 'Synthetic', 'ignore'];
        array_splice($headers, $position, 0, ['上り']);
        array_splice($row, $position, 0, [' 11.500 ']);
        $html = '<table><thead><tr><th>'.implode('</th><th>', $headers).'</th></tr></thead><tbody id="pitbodyBs">';
        foreach ([1, 2] as $bike) {
            $row[array_search('車番', $headers, true)] = (string) $bike;
            $html .= '<tr><td>'.implode('</td><td>', $row).'</td></tr>';
        }
        $results = (new RaceResultParser)->parse($html.'</tbody></table>');
        foreach ($results as $result) {
            $this->assertSame(RaceEntryResultStatus::Tied, $result->status);
            $this->assertSame('11.500', $result->finishTime);
            $this->assertSame(' 11.500 ', $result->agariRawText);
        }
        $this->assertSame([1, 2], array_column($results, 'bikeNumber'));
    }

    public static function headerPositions(): array
    {
        return [[0], [4], [5]];
    }

    public function test_unknown_or_absent_header_does_not_guess_agari(): void
    {
        foreach (['', '<thead><tr><th>着</th><th>H/B</th><th>車番</th><th>選手名</th><th>Time</th></tr></thead>'] as $header) {
            $result = (new RaceResultParser)->parse('<table>'.$header.'<tbody id="pitbodyBs"><tr><td>1</td><td></td><td>1</td><td>Synthetic</td><td>11.5</td></tr></tbody></table>')[0];
            $this->assertNull($result->finishTime);
            $this->assertNull($result->agariRawText);
        }
    }

    public function test_td_header_in_thead_is_recognized(): void
    {
        $html = '<table><thead><tr><td>着</td><td>車番</td><td>選手名</td><td>上り</td></tr></thead><tbody id="pitbodyBs"><tr><td>1</td><td>2</td><td>Synthetic</td><td>11.50</td></tr></tbody></table>';
        $result = (new RaceResultParser)->parse($html)[0];
        $this->assertSame(2, $result->bikeNumber);
        $this->assertSame('11.50', $result->agariRawText);
    }
}
