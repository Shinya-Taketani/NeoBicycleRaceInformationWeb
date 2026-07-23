<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Scraping;

use App\Domain\Keirin\Scraping\Enums\RaceCategory;
use App\Domain\Keirin\Scraping\Enums\RaceEntryResultStatus;
use App\Domain\Keirin\Scraping\Enums\RaceResultStatus;
use App\Domain\Keirin\Scraping\Exceptions\ParserException;
use App\Domain\Keirin\Scraping\Exceptions\RaceDayMetadataUnavailableException;
use App\Domain\Keirin\Scraping\Exceptions\RaceEntryListUnavailableException;
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

    public function test_it_parses_race_specific_cancellation_metadata_as_a_normal_page(): void
    {
        $metadata = $this->metadataParser()->parse(
            $this->fixture('race-sync-jsj001-partial-race-cancelled.json'),
        );
        $entries = (new RaceEntryListParser)->parse($this->partialRaceCancelledEntries());
        $parameters = (new RaceListConsistencyValidator)->validate($metadata, $entries);

        $this->assertSame('20240331', $metadata->selectedDate);
        $this->assertSame('22', $metadata->trackCode);
        $this->assertSame(11, $metadata->selectedRaceNumber);
        $this->assertCount(3, $metadata->days);
        $this->assertCount(11, $metadata->races);
        $this->assertCount(11, $entries->races);
        $this->assertSame(range(1, 11), array_map(fn ($race): int => $race->raceNumber, $entries->races));
        $this->assertCount(11, $parameters);
        $this->assertSame('enc-partial-r11', $parameters[11]->encryptedParameter);
        $this->assertTrue($parameters[11]->raceEnded);
        $this->assertTrue($parameters[11]->resultAvailable);
    }

    public function test_it_reports_only_strict_race_meeting_cancellation_responses(): void
    {
        $fixture = $this->cancelledMeetingFixture();
        $cases = [
            [$fixture, '中止となりました。', 'missing'],
            [[...$fixture, 'resultCd' => '0'], '中止となりました。', 'missing'],
            [$this->mutateCancelledMeeting($fixture, ['flgRaceCancel' => 1]), '中止となりました。', 'missing'],
            [$this->mutateCancelledMeeting($fixture, ['flgRaceCancel' => '1']), '中止となりました。', 'missing'],
            [$this->mutateCancelledMeeting($fixture, ['flgSectionCancel' => 0]), '中止となりました。', 'missing'],
            [$this->mutateCancelledMeeting($fixture, ['flgSectionCancel' => '0']), '中止となりました。', 'missing'],
            [$this->mutateCancelledMeeting($fixture, ['hhMessage' => '  中止となりました。  ']), '中止となりました。', 'missing'],
            [$this->mutateCancelledMeeting($fixture, ['hhMessage' => '中止となりました']), '中止となりました', 'missing'],
            [$this->mutateCancelledMeeting($fixture, ['C0201race' => null]), '中止となりました。', 'null'],
            [$this->mutateCancelledMeeting($fixture, ['C0201race' => []]), '中止となりました。', 'empty_array'],
        ];

        foreach ($cases as [$json, $expectedMessage, $expectedRaceInfoState]) {
            try {
                $this->metadataParser()->parse(json_encode($json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
                $this->fail('RaceDayMetadataUnavailableException was not thrown.');
            } catch (RaceDayMetadataUnavailableException $exception) {
                $this->assertSame(RaceDayMetadataUnavailableException::REASON_RACE_MEETING_CANCELLED, $exception->reason);
                $this->assertSame($expectedMessage, $exception->getMessage());
                $this->assertSame($json['resultCd'], $exception->evidence['resultCd']);
                $this->assertSame('20251203', $exception->evidence['selKaisai']);
                $this->assertSame('25', $exception->evidence['selKjyoCd']);
                $this->assertSame(['20251203', '20251204', '20251205'], $exception->evidence['raceDates']);
                $this->assertSame(3, $exception->evidence['raceDayCount']);
                $this->assertSame($expectedRaceInfoState, $exception->evidence['raceInfoState']);
            }
        }
    }

    public function test_it_rejects_responses_that_do_not_meet_every_race_meeting_cancellation_condition(): void
    {
        $fixture = $this->cancelledMeetingFixture();
        $invalidCases = [
            'non-zero result code' => fn (array $json): array => [...$json, 'resultCd' => 1],
            'disabled cancellation flag' => fn (array $json): array => $this->mutateCancelledMeeting($json, ['flgRaceCancel' => false]),
            'section cancellation flag' => fn (array $json): array => $this->mutateCancelledMeeting($json, ['flgSectionCancel' => true]),
            'missing message' => fn (array $json): array => $this->withoutCancelledMeetingKey($json, 'hhMessage'),
            'sales cancellation message' => fn (array $json): array => $this->mutateCancelledMeeting($json, ['hhMessage' => '発売中止']),
            'partial cancellation message' => fn (array $json): array => $this->mutateCancelledMeeting($json, ['hhMessage' => '一部中止']),
            'planned cancellation message' => fn (array $json): array => $this->mutateCancelledMeeting($json, ['hhMessage' => '中止予定']),
            'selected race number' => fn (array $json): array => $this->mutateCancelledMeeting($json, ['selRaceNo' => 1]),
            'non-zero race count' => fn (array $json): array => $this->mutateCancelledMeeting($json, ['cntRace' => 1]),
            'missing meeting days' => fn (array $json): array => $this->withoutCancelledMeetingKey($json, 'C0201kaisai'),
            'null meeting days' => fn (array $json): array => $this->mutateCancelledMeeting($json, ['C0201kaisai' => null]),
            'empty meeting days' => fn (array $json): array => $this->mutateCancelledMeeting($json, ['C0201kaisai' => []]),
            'normal races present' => fn (array $json): array => $this->mutateCancelledMeeting($json, ['C0201race' => [['encParaR' => 'enc-r1']]]),
            'selected date invalid' => fn (array $json): array => $this->mutateCancelledMeeting($json, ['selKaisai' => '20250231']),
            'track code invalid' => fn (array $json): array => $this->mutateCancelledMeeting($json, ['selKjyoCd' => 'track']),
            'day row invalid' => fn (array $json): array => $this->mutateCancelledMeeting($json, ['C0201kaisai' => ['invalid']]),
            'day date invalid' => fn (array $json): array => $this->mutateCancelledMeetingDay($json, 0, ['txtEventDate' => '12-03']),
            'day parameter empty' => fn (array $json): array => $this->mutateCancelledMeetingDay($json, 0, ['encParaK' => '']),
            'day parameter not a string' => fn (array $json): array => $this->mutateCancelledMeetingDay($json, 0, ['encParaK' => 123]),
        ];

        foreach ($invalidCases as $case => $mutate) {
            try {
                $this->metadataParser()->parse(json_encode($mutate($fixture), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
                $this->fail("ParserException was not thrown for {$case}.");
            } catch (RaceDayMetadataUnavailableException) {
                $this->fail("{$case} was incorrectly classified as a race-meeting cancellation.");
            } catch (ParserException) {
                $this->addToAssertionCount(1);
            }
        }

        $normalWithoutRaces = json_decode($this->fixture('race-sync-jsj001.json'), true, flags: JSON_THROW_ON_ERROR);
        unset($normalWithoutRaces['C0201data']['C0201race']);
        foreach ([json_encode($normalWithoutRaces, JSON_THROW_ON_ERROR), '{}', '[]', '{invalid'] as $source) {
            try {
                $this->metadataParser()->parse($source);
                $this->fail('ParserException was not thrown for an invalid normal response.');
            } catch (RaceDayMetadataUnavailableException) {
                $this->fail('An invalid normal response was incorrectly classified as a race-meeting cancellation.');
            } catch (ParserException) {
                $this->addToAssertionCount(1);
            }
        }
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

    public function test_it_reports_postponed_jsj017_responses_with_strict_evidence(): void
    {
        $fixture = json_decode($this->fixture('race-sync-jsj017-postponed.json'), true, flags: JSON_THROW_ON_ERROR);
        $cases = [
            [0, 0, '順延となりました。'],
            ['0', false, '順延となりました。'],
            [0, '0', '  順延となりました。  '],
        ];

        foreach ($cases as [$resultCode, $displayFlag, $message]) {
            $json = $fixture;
            $json['resultCd'] = $resultCode;
            $json['syusouDispFlag'] = $displayFlag;
            $json['kaisaiMsg'] = $message;

            try {
                (new RaceEntryListParser)->parse(json_encode($json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
                $this->fail('RaceEntryListUnavailableException was not thrown.');
            } catch (RaceEntryListUnavailableException $exception) {
                $this->assertSame(RaceEntryListUnavailableException::REASON_RACE_DAY_POSTPONED, $exception->reason);
                $this->assertSame('順延となりました。', $exception->getMessage());
                $this->assertSame([
                    'resultCd' => $resultCode,
                    'syusouDispFlag' => $displayFlag,
                    'kaisaiMsg' => '順延となりました。',
                ], $exception->evidence);
            }
        }
    }

    public function test_it_rejects_responses_that_do_not_meet_every_postponed_condition(): void
    {
        $postponed = json_decode($this->fixture('race-sync-jsj017-postponed.json'), true, flags: JSON_THROW_ON_ERROR);
        $normalWithoutTrackCode = json_decode($this->fixture('race-sync-jsj017.json'), true, flags: JSON_THROW_ON_ERROR);
        unset($normalWithoutTrackCode['keirinCd']);
        $cases = [
            'non-zero result code' => [...$postponed, 'resultCd' => 1],
            'missing display flag' => array_diff_key($postponed, ['syusouDispFlag' => true]),
            'enabled display flag' => [...$postponed, 'syusouDispFlag' => 1],
            'missing message' => array_diff_key($postponed, ['kaisaiMsg' => true]),
            'different message' => [...$postponed, 'kaisaiMsg' => '発売を終了しました。'],
            'normal response missing only track code' => $normalWithoutTrackCode,
        ];

        foreach ($cases as $case => $json) {
            try {
                (new RaceEntryListParser)->parse(json_encode($json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
                $this->fail("ParserException was not thrown for {$case}.");
            } catch (RaceEntryListUnavailableException) {
                $this->fail("{$case} was incorrectly classified as postponed.");
            } catch (ParserException) {
                $this->addToAssertionCount(1);
            }
        }

        foreach (['{}', '[]', '{invalid'] as $json) {
            try {
                (new RaceEntryListParser)->parse($json);
                $this->fail("ParserException was not thrown for {$json}.");
            } catch (ParserException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_it_reports_only_strict_race_day_cancellation_responses(): void
    {
        $fixture = json_decode($this->fixture('race-sync-jsj017-cancelled.json'), true, flags: JSON_THROW_ON_ERROR);
        $cases = [
            [$fixture, '中止となりました。', 'missing'],
            [[...$fixture, 'syusouDispFlag' => false], '中止となりました。', 'missing'],
            [[...$fixture, 'syusouDispFlag' => '0'], '中止となりました。', 'missing'],
            [[...$fixture, 'resultCd' => '0'], '中止となりました。', 'missing'],
            [[...$fixture, 'kaisaiMsg' => '  中止となりました。  '], '中止となりました。', 'missing'],
            [[...$fixture, 'kaisaiMsg' => '中止となりました'], '中止となりました', 'missing'],
            [[...$fixture, 'rInfo' => null], '中止となりました。', 'null'],
            [[...$fixture, 'rInfo' => []], '中止となりました。', 'empty_array'],
        ];

        foreach ($cases as [$json, $expectedMessage, $expectedRaceInfoState]) {
            try {
                (new RaceEntryListParser)->parse(json_encode($json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
                $this->fail('RaceEntryListUnavailableException was not thrown.');
            } catch (RaceEntryListUnavailableException $exception) {
                $this->assertSame(RaceEntryListUnavailableException::REASON_RACE_DAY_CANCELLED, $exception->reason);
                $this->assertSame($expectedMessage, $exception->getMessage());
                $this->assertSame($json['resultCd'], $exception->evidence['resultCd']);
                $this->assertSame($json['syusouDispFlag'], $exception->evidence['syusouDispFlag']);
                $this->assertSame($expectedMessage, $exception->evidence['kaisaiMsg']);
                $this->assertSame('56', $exception->evidence['reqprm.bkcd']);
                $this->assertSame('20260616', $exception->evidence['reqprm.kday']);
                $this->assertFalse($exception->evidence['hasKeirinCd']);
                $this->assertFalse($exception->evidence['hasKaisaihi']);
                $this->assertSame($expectedRaceInfoState, $exception->evidence['rInfoState']);
            }
        }
    }

    public function test_it_rejects_responses_that_do_not_meet_every_race_day_cancellation_condition(): void
    {
        $fixture = json_decode($this->fixture('race-sync-jsj017-cancelled.json'), true, flags: JSON_THROW_ON_ERROR);
        $invalidCases = [
            'different message' => function (array &$json): void {
                $json['kaisaiMsg'] = '開催情報を確認してください。';
            },
            'sales cancellation message' => function (array &$json): void {
                $json['kaisaiMsg'] = '発売中止';
            },
            'enabled display flag' => function (array &$json): void {
                $json['syusouDispFlag'] = 1;
            },
            'non-zero result code' => function (array &$json): void {
                $json['resultCd'] = 1;
            },
            'keirinCd present' => function (array &$json): void {
                $json['keirinCd'] = '56';
            },
            'kaisaihi present' => function (array &$json): void {
                $json['kaisaihi'] = '20260616';
            },
            'normal race array present' => function (array &$json): void {
                $json['rInfo'] = [['raceNo' => 1]];
            },
            'request parameters missing' => function (array &$json): void {
                unset($json['reqprm']);
            },
            'track code invalid' => function (array &$json): void {
                $json['reqprm']['bkcd'] = 'track';
            },
            'race date length invalid' => function (array &$json): void {
                $json['reqprm']['kday'] = '2026061';
            },
            'race date calendar invalid' => function (array &$json): void {
                $json['reqprm']['kday'] = '20260231';
            },
        ];

        foreach ($invalidCases as $case => $mutate) {
            $json = $fixture;
            $mutate($json);
            try {
                (new RaceEntryListParser)->parse(json_encode($json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
                $this->fail("ParserException was not thrown for {$case}.");
            } catch (RaceEntryListUnavailableException) {
                $this->fail("{$case} was incorrectly classified as a race-day cancellation.");
            } catch (ParserException) {
                $this->addToAssertionCount(1);
            }
        }
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

    public function test_it_skips_only_strict_unavailable_and_full_refund_payout_rows(): void
    {
        $keys = [
            'WH2HaraiGakuDispItemSubData',
            'WT2HaraiGakuDispItemSubData',
            'SH2HaraiGakuDispItemSubData',
            'ST2HaraiGakuDispItemSubData',
            'RH3HaraiGakuDispItemSubData',
            'RT3HaraiGakuDispItemSubData',
            'WHaraiGakuDispItemSubData',
        ];
        $rows = [
            ['haraiGaku' => '【未発売】', 'kumiDispFlg' => false],
            ['haraiGaku' => '未発売', 'kumiDispFlg' => 0],
            ['haraiGaku' => '【全返還】', 'kumiDispFlg' => false],
            ['haraiGaku' => '全返還', 'kumiDispFlg' => 0],
            ['haraiGaku' => '【全返還】', 'kumiDispFlg' => '0'],
            ['haraiGaku' => '  【全返還】  ', 'kumiDispFlg' => false],
            ['haraiGaku' => '【未発売】', 'kumiDispFlg' => '0'],
        ];
        $html = $this->liveResultHtmlWith(function (array $result) use ($keys, $rows): array {
            foreach ($keys as $index => $key) {
                $result['haraiGakuSubData'][$key] = [[
                    ...$rows[$index],
                    'ninkiDispFlg' => false,
                ]];
            }

            return $result;
        });

        $page = (new RaceLiveResultParser(new EmbeddedJsonExtractor))->parse($html);

        $this->assertCount(7, $page->resultPage->results);
        $this->assertSame([], $page->resultPage->payouts);
    }

    public function test_it_rejects_non_exact_refund_markers_and_incomplete_payable_rows(): void
    {
        $invalidRows = [
            'displayed boolean full refund' => ['haraiGaku' => '【全返還】', 'kumiDispFlg' => true],
            'displayed integer full refund' => ['haraiGaku' => '【全返還】', 'kumiDispFlg' => 1],
            'displayed string full refund' => ['haraiGaku' => '【全返還】', 'kumiDispFlg' => '1'],
            'partial refund' => ['haraiGaku' => '【一部返還】', 'kumiDispFlg' => false],
            'generic refund' => ['haraiGaku' => '【返還】', 'kumiDispFlg' => false],
            'unknown refund amount' => ['haraiGaku' => '返還額不明', 'kumiDispFlg' => false],
            'empty amount' => ['haraiGaku' => '', 'kumiDispFlg' => false],
            'null amount' => ['haraiGaku' => null, 'kumiDispFlg' => false],
            'unknown text' => ['haraiGaku' => '払戻情報なし', 'kumiDispFlg' => false],
            'numeric amount without combination' => ['haraiGaku' => '1,110', 'kumiDispFlg' => true],
        ];

        foreach ($invalidRows as $case => $invalidRow) {
            $html = $this->liveResultHtmlWith(function (array $result) use ($invalidRow): array {
                $result['haraiGakuSubData']['WH2HaraiGakuDispItemSubData'] = [$invalidRow];

                return $result;
            });

            try {
                (new RaceLiveResultParser(new EmbeddedJsonExtractor))->parse($html);
                $this->fail("ParserException was not thrown for {$case}.");
            } catch (ParserException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function metadataParser(): RaceDayMetadataParser
    {
        return new RaceDayMetadataParser(new EmbeddedJsonExtractor);
    }

    /** @return array<string, mixed> */
    private function cancelledMeetingFixture(): array
    {
        return (new EmbeddedJsonExtractor)->extract(
            $this->fixture('race-sync-pj0301-meeting-cancelled.html'),
            'PC0201',
        );
    }

    /**
     * @param  array<string, mixed>  $json
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function mutateCancelledMeeting(array $json, array $changes): array
    {
        $json['C0201data'] = [...$json['C0201data'], ...$changes];

        return $json;
    }

    /** @param array<string, mixed> $json */
    private function withoutCancelledMeetingKey(array $json, string $key): array
    {
        unset($json['C0201data'][$key]);

        return $json;
    }

    /**
     * @param  array<string, mixed>  $json
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function mutateCancelledMeetingDay(array $json, int $index, array $changes): array
    {
        $json['C0201data']['C0201kaisai'][$index] = [
            ...$json['C0201data']['C0201kaisai'][$index],
            ...$changes,
        ];

        return $json;
    }

    private function partialRaceCancelledEntries(): string
    {
        $json = json_decode($this->fixture('race-sync-jsj017.json'), true, flags: JSON_THROW_ON_ERROR);
        $json['keirinCd'] = '22';
        $json['kaisaihi'] = '20240331';
        $json['reqprm']['bkcd'] = '22';
        $json['reqprm']['kday'] = '20240331';
        $json['syusouDispFlag'] = 1;
        $json['kaisaiMsg'] = '11レースは中止となりました。';
        $json['rInfo'] = array_slice($json['rInfo'], 0, 11);

        return json_encode($json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    private function liveResultHtmlWith(callable $mutate): string
    {
        $extractor = new EmbeddedJsonExtractor;
        $fixture = $this->fixture('race-sync-pj0326.html');
        $context = $extractor->extract($fixture, 'PC0201');
        $result = $mutate($extractor->extract($fixture, 'PJ0326'));

        return '<!doctype html><html><body><script>jsonData["PC0201"] = '
            .json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
            .'; jsonData["PJ0326"] = '
            .json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
            .';</script></body></html>';
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/../../../../Fixtures/Keirin/synthetic/'.$name);
    }
}
