<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Scraping;

use App\Domain\Keirin\Scraping\Enums\RaceCategory;
use App\Domain\Keirin\Scraping\Enums\RaceEntryResultStatus;
use App\Domain\Keirin\Scraping\Enums\RaceResultStatus;
use App\Domain\Keirin\Scraping\Exceptions\ParserException;
use App\Domain\Keirin\Scraping\Parsers\EmbeddedJsonExtractor;
use App\Domain\Keirin\Scraping\Parsers\RaceDayMetadataParser;
use App\Domain\Keirin\Scraping\Parsers\RaceDetailParser;
use App\Domain\Keirin\Scraping\Parsers\RaceEntryListParser;
use App\Domain\Keirin\Scraping\Parsers\RaceListConsistencyValidator;
use App\Domain\Keirin\Scraping\Parsers\RaceLiveResultParser;
use Tests\TestCase;

class AutomatedRaceParserTest extends TestCase
{
    public function test_it_parses_six_days_and_twelve_race_parameters(): void
    {
        $page = $this->metadataParser()->parse($this->fixture('race-sync-jsj001.json'));

        $this->assertSame('20260616', $page->selectedDate);
        $this->assertSame('56', $page->trackCode);
        $this->assertSame(12, $page->selectedRaceNumber);
        $this->assertCount(6, $page->days);
        $this->assertSame('20260621', $page->days[5]->raceDate);
        $this->assertCount(12, $page->races);
        $this->assertSame('enc-r1', $page->races[0]->encryptedParameter);
        $this->assertSame('enc-r12', $page->races[11]->encryptedParameter);
    }

    public function test_it_extracts_single_quoted_embedded_pc0201_json(): void
    {
        $page = $this->metadataParser()->parse($this->fixture('race-sync-racelist.html'));

        $this->assertCount(6, $page->days);
        $this->assertCount(12, $page->races);
    }

    public function test_it_parses_twelve_races_five_through_nine_car_fields_and_leading_zero_ids(): void
    {
        $page = (new RaceEntryListParser)->parse($this->fixture('race-sync-jsj017.json'));

        $this->assertCount(12, $page->races);
        $this->assertCount(7, $page->races[0]->entries);
        $this->assertCount(9, $page->races[1]->entries);
        $this->assertCount(8, $page->races[2]->entries);
        $this->assertCount(6, $page->races[3]->entries);
        $this->assertSame(RaceCategory::Men, $page->races[0]->category);
        $this->assertSame('000001', $page->races[0]->entries[0]->externalPlayerId);
    }

    public function test_it_accepts_only_five_through_nine_entrants_for_mens_races(): void
    {
        $fixture = json_decode($this->fixture('race-sync-jsj017.json'), true, flags: JSON_THROW_ON_ERROR);

        foreach ([5, 6, 7, 8, 9] as $count) {
            $json = $fixture;
            $json['rInfo'][0]['sInfo'] = array_slice($fixture['rInfo'][1]['sInfo'], 0, $count);
            $page = (new RaceEntryListParser)->parse(json_encode($json, JSON_THROW_ON_ERROR));
            $this->assertCount($count, $page->races[0]->entries);
        }

        foreach ([0, 1, 2, 3, 4] as $count) {
            $json = $fixture;
            $json['rInfo'][0]['sInfo'] = array_slice($fixture['rInfo'][0]['sInfo'], 0, $count);
            try {
                (new RaceEntryListParser)->parse(json_encode($json, JSON_THROW_ON_ERROR));
                $this->fail("ParserException was not thrown for {$count} entrants.");
            } catch (ParserException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_it_accepts_non_contiguous_bike_numbers_for_supported_mens_races(): void
    {
        $fixture = json_decode($this->fixture('race-sync-jsj017.json'), true, flags: JSON_THROW_ON_ERROR);
        $cases = [
            [1, 2, 3, 4, 6],
            [1, 2, 3, 4, 5, 7],
        ];

        foreach ($cases as $expectedBikeNumbers) {
            $json = $fixture;
            $json['rInfo'][0]['sInfo'] = array_values(array_filter(
                $fixture['rInfo'][0]['sInfo'],
                fn (array $entry): bool => in_array((int) $entry['syaban'], $expectedBikeNumbers, true),
            ));

            $page = (new RaceEntryListParser)->parse(json_encode($json, JSON_THROW_ON_ERROR));

            $this->assertSame($expectedBikeNumbers, array_map(
                fn ($entry): int => $entry->bikeNumber,
                $page->races[0]->entries,
            ));
        }
    }

    public function test_it_classifies_l_grade_and_girls_races_as_unsupported_categories(): void
    {
        foreach (['Ｌ級ガールズ予選', 'ガールズ決勝'] as $raceType) {
            $json = json_decode($this->fixture('race-sync-jsj017.json'), true, flags: JSON_THROW_ON_ERROR);
            $json['rInfo'][0]['syumoku'] = $raceType;
            $page = (new RaceEntryListParser)->parse(json_encode($json, JSON_THROW_ON_ERROR));

            $this->assertSame(RaceCategory::Girls, $page->races[0]->category);
        }
    }

    public function test_unsupported_categories_bypass_mens_entrant_count_validation(): void
    {
        foreach ([
            'Ｌ級ガールズ予選' => RaceCategory::Girls,
            'カテゴリ未定' => RaceCategory::Unknown,
        ] as $raceType => $expectedCategory) {
            foreach ([5, 8] as $count) {
                $json = json_decode($this->fixture('race-sync-jsj017.json'), true, flags: JSON_THROW_ON_ERROR);
                $json['rInfo'][0]['syumoku'] = $raceType;
                $json['rInfo'][0]['sInfo'] = array_slice($json['rInfo'][2]['sInfo'], 0, $count);

                $page = (new RaceEntryListParser)->parse(json_encode($json, JSON_THROW_ON_ERROR));

                $this->assertSame($expectedCategory, $page->races[0]->category);
                $this->assertCount($count, $page->races[0]->entries);
            }
        }
    }

    public function test_structurally_invalid_entrants_are_rejected_independently_of_count_validation(): void
    {
        $fixture = json_decode($this->fixture('race-sync-jsj017.json'), true, flags: JSON_THROW_ON_ERROR);
        $invalidCases = [
            'duplicate bike number' => function (array &$json): void {
                $json['rInfo'][0]['sInfo'][1]['syaban'] = 1;
            },
            'out of range bike number' => function (array &$json): void {
                $json['rInfo'][0]['sInfo'][0]['syaban'] = 10;
            },
            'zero bike number' => function (array &$json): void {
                $json['rInfo'][0]['sInfo'][0]['syaban'] = 0;
            },
            'invalid registration number' => function (array &$json): void {
                $json['rInfo'][0]['sInfo'][0]['senNo'] = 'invalid';
            },
        ];

        foreach ($invalidCases as $case => $mutate) {
            $json = $fixture;
            $mutate($json);
            try {
                (new RaceEntryListParser)->parse(json_encode($json, JSON_THROW_ON_ERROR));
                $this->fail("ParserException was not thrown for {$case}.");
            } catch (ParserException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_it_pairs_the_first_race_with_the_first_array_parameter_even_when_selected_race_is_twelve(): void
    {
        $metadata = $this->metadataParser()->parse($this->fixture('race-sync-jsj001.json'));
        $entries = (new RaceEntryListParser)->parse($this->fixture('race-sync-jsj017.json'));

        $parameters = (new RaceListConsistencyValidator)->validate($metadata, $entries);

        $this->assertSame('enc-r1', $parameters[1]->encryptedParameter);
        $this->assertSame('enc-r12', $parameters[12]->encryptedParameter);
    }

    public function test_it_rejects_mismatched_race_counts(): void
    {
        $metadata = $this->metadataParser()->parse($this->fixture('race-sync-jsj001.json'));
        $json = json_decode($this->fixture('race-sync-jsj017.json'), true, flags: JSON_THROW_ON_ERROR);
        array_pop($json['rInfo']);
        $entries = (new RaceEntryListParser)->parse(json_encode($json, JSON_THROW_ON_ERROR));

        $this->expectException(ParserException::class);
        (new RaceListConsistencyValidator)->validate($metadata, $entries);
    }

    public function test_it_rejects_duplicate_race_numbers(): void
    {
        $metadata = $this->metadataParser()->parse($this->fixture('race-sync-jsj001.json'));
        $json = json_decode($this->fixture('race-sync-jsj017.json'), true, flags: JSON_THROW_ON_ERROR);
        $json['rInfo'][1]['raceNo'] = 1;
        $entries = (new RaceEntryListParser)->parse(json_encode($json, JSON_THROW_ON_ERROR));

        $this->expectException(ParserException::class);
        (new RaceListConsistencyValidator)->validate($metadata, $entries);
    }

    public function test_it_rejects_missing_race_numbers_after_count_validation(): void
    {
        $metadataJson = json_decode($this->fixture('race-sync-jsj001.json'), true, flags: JSON_THROW_ON_ERROR);
        array_pop($metadataJson['C0201data']['C0201race']);
        $metadata = $this->metadataParser()->parse(json_encode($metadataJson, JSON_THROW_ON_ERROR));
        $entryJson = json_decode($this->fixture('race-sync-jsj017.json'), true, flags: JSON_THROW_ON_ERROR);
        array_splice($entryJson['rInfo'], 1, 1);
        $entries = (new RaceEntryListParser)->parse(json_encode($entryJson, JSON_THROW_ON_ERROR));

        $this->expectException(ParserException::class);
        (new RaceListConsistencyValidator)->validate($metadata, $entries);
    }

    public function test_it_parses_embedded_race_detail_entries(): void
    {
        $page = (new RaceDetailParser(new EmbeddedJsonExtractor))->parse($this->fixture('race-sync-pj0315.html'));

        $this->assertSame(1, $page->raceNumber);
        $this->assertCount(7, $page->entries);
        $this->assertSame('000001', $page->entries[0]->externalPlayerId);
        $this->assertSame('110.50', $page->entries[0]->raceScore);
    }

    public function test_it_preserves_blank_rank_abnormal_results_and_parses_all_payout_types(): void
    {
        $page = (new RaceLiveResultParser(new EmbeddedJsonExtractor))->parse($this->fixture('race-sync-pj0326.html'));

        $this->assertCount(7, $page->resultPage->results);
        $this->assertSame(RaceResultStatus::Confirmed, $page->detectedStatus);
        $this->assertNull($page->resultPage->results[5]->rank);
        $this->assertSame(RaceEntryResultStatus::Crashed, $page->resultPage->results[5]->status);
        $this->assertSame(RaceEntryResultStatus::Disqualified, $page->resultPage->results[6]->status);
        $this->assertSame('000001', $page->resultPage->results[0]->externalPlayerId);
        $this->assertCount(8, $page->resultPage->payouts);
        $this->assertSame(180, $page->resultPage->payouts[0]->payoutAmount);
        $this->assertEqualsCanonicalizing([
            'FRAME_QUINELLA',
            'FRAME_EXACTA',
            'QUINELLA',
            'EXACTA',
            'TRIO',
            'TRIFECTA',
            'QUINELLA_PLACE',
        ], array_unique(array_map(fn ($payout): string => $payout->betTypeCode, $page->resultPage->payouts)));
    }

    public function test_it_skips_explicitly_unavailable_frame_payouts_for_a_five_car_result(): void
    {
        $extractor = new EmbeddedJsonExtractor;
        $fixture = $this->fixture('race-sync-pj0326.html');
        $context = $extractor->extract($fixture, 'PC0201');
        $result = $extractor->extract($fixture, 'PJ0326');
        $result['tyakujyunItemSubData'] = array_values(array_filter(
            $result['tyakujyunItemSubData'],
            fn (array $row): bool => (int) $row['syaban'] <= 5,
        ));
        $unavailable = [[
            'haraiGaku' => '【未発売】',
            'ninkiDispFlg' => false,
            'kumiDispFlg' => false,
        ]];
        $result['haraiGakuSubData']['WH2HaraiGakuDispItemSubData'] = $unavailable;
        $result['haraiGakuSubData']['WT2HaraiGakuDispItemSubData'] = $unavailable;
        $html = '<!doctype html><html><body><script>jsonData["PC0201"] = '
            .json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
            .'; jsonData["PJ0326"] = '
            .json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
            .';</script></body></html>';

        $page = (new RaceLiveResultParser($extractor))->parse($html);

        $this->assertCount(5, $page->resultPage->results);
        $this->assertCount(6, $page->resultPage->payouts);
        $this->assertNotContains('FRAME_QUINELLA', array_map(fn ($payout): string => $payout->betTypeCode, $page->resultPage->payouts));
        $this->assertNotContains('FRAME_EXACTA', array_map(fn ($payout): string => $payout->betTypeCode, $page->resultPage->payouts));
    }

    private function metadataParser(): RaceDayMetadataParser
    {
        return new RaceDayMetadataParser(new EmbeddedJsonExtractor);
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/../../../../Fixtures/Keirin/synthetic/'.$name);
    }
}
