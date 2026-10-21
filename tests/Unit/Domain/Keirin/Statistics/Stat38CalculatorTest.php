<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Statistics;

use App\Domain\Keirin\Scraping\DTO\RaceDistanceObservationDto;
use App\Domain\Keirin\Scraping\Parsers\EmbeddedJsonExtractor;
use App\Domain\Keirin\Scraping\Parsers\RaceDetailParser;
use App\Domain\Keirin\Scraping\Support\CharacterEncodingConverter;
use App\Domain\Keirin\Statistics\Calculators\Stat38Calculator;
use App\Domain\Keirin\Statistics\DTO\Stat38InputDto;
use App\Domain\Keirin\Statistics\Support\DeterministicJsonHasher;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class Stat38CalculatorTest extends TestCase
{
    public function test_actual_raw_dto_uses_official_planned_values_without_actual_distance_inference(): void
    {
        $args = $this->actualArguments();
        $dto = new RaceDistanceObservationDto(...$args);
        $attrs = $dto->attributes('STAT38-PJ0315-PLANNED-OBSERVATION-v1');
        $this->assertSame(1700, $dto->distance);
        $this->assertSame(5, $dto->laps);
        $this->assertSame($args['rawSha256'], $attrs['raw_sha256']);
        $this->assertNull($attrs['actual_distance_m']);
        $this->assertNull($attrs['actual_laps']);
        $this->assertSame('AT_OR_AFTER_SAME_RAW_START', $attrs['timing_status']);
        $this->assertSame('NOT_AUTHORIZED', $attrs['metadata']['prediction_use']);
    }

    public function test_invalid_identity_ranges_and_seals_cannot_construct_an_observation(): void
    {
        $valid = $this->actualArguments();
        foreach (['raceId' => 0, 'fetchLogId' => 0, 'raceDate' => '2026-01-01', 'trackCode' => 'unknown',
            'raceNumber' => 0, 'distance' => 0, 'laps' => 0, 'rawSha256' => 'invalid', 'rawBytes' => 0,
            'entrants' => [['bike_number' => 1, 'external_player_id' => 'unknown']]] as $key => $bad) {
            try {
                new RaceDistanceObservationDto(...array_replace($valid, [$key => $bad]));
                $this->fail('Invalid observation accepted: '.$key);
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_null_is_not_experience_zero_when_no_eligible_asof_history_is_established(): void
    {
        $args = $this->actualArguments();
        $input = new Stat38InputDto($args['raceId'], $args['raceDate'], new DateTimeImmutable('2025-01-01T10:53:00+09:00'),
            ['id' => 89422, 'race_id' => $args['raceId'], 'bike_number' => 1, 'player_id' => null,
                'external_player_id' => $args['entrants'][0]['external_player_id']], null, null, []);
        $result = (new Stat38Calculator(new DeterministicJsonHasher))->calculate($input);
        $this->assertSame('MISSING_INPUT', $result->status->value);
        $this->assertNull($result->features['PREDICTION_EXPERIENCE_COUNT']);
        $this->assertNull($result->features['PREDICTION_SAME_DISTANCE_COUNT']);
        $this->assertSame('NOT_ESTABLISHED', $result->features['PREDICTION_EXPERIENCE_STATUS']);
        $this->assertNull($result->features['POINTS']);
        $this->assertNull($result->features['CONFIDENCE']);
        $this->assertFalse($result->evidence['historical_as_of_available']);
        $this->assertSame('NOT_AUTHORIZED', $result->evidence['prediction_use']);
    }

    public function test_artificial_forbidden_year_input_is_rejected_before_history_iteration(): void
    {
        $history = (function (): \Generator {
            throw new RuntimeException('Forbidden history must not be read.');
            yield [];
        })();
        $input = new Stat38InputDto(12542, '2026-01-01', new DateTimeImmutable('2026-01-01T10:53:00+09:00'), [], null, null, $history);
        $this->expectException(InvalidArgumentException::class);
        (new Stat38Calculator(new DeterministicJsonHasher))->calculate($input);
    }

    public function test_control_records_distinguish_started_zero_unknown_and_absent_history(): void
    {
        // State mutations exercise arithmetic only; they are not evidence about real race outcomes.
        foreach ([
            [['FINISHED', 'FINISHED'], [true, true], 2],
            [['DID_NOT_START', 'WITHDRAWN'], [true, true], 0],
            [['DID_NOT_START', null], [true, false], null],
            [['FINISHED', 'FINISHED'], [true, false], null],
            [[], [], null],
        ] as [$states, $verified, $expected]) {
            $result = (new Stat38Calculator(new DeterministicJsonHasher))->calculate($this->participationInput($states, $verified));
            $this->assertSame($expected, $result->features['RETROSPECTIVE']['confirmed_started_count']);
            $this->assertSame($expected === null ? null : 0, $result->features['RETROSPECTIVE']['same_distance_started_count']);
            $this->assertNull($result->features['PREDICTION_EXPERIENCE_COUNT']);
            $this->assertSame('RETROSPECTIVE_ONLY', $result->features['RETROSPECTIVE']['use']);
        }
    }

    public function test_control_prediction_counts_require_strict_acquisition_and_save_cutoffs(): void
    {
        // Artificial clocks isolate cutoff arithmetic; they do not establish any actual prestart availability.
        foreach ([['FINISHED', 'FINISHED'], ['DID_NOT_START', 'WITHDRAWN']] as $states) {
            $base = $this->participationInput($states, [true, true]);
            $target = $base->retrospectiveObservation + ['fetched_at' => '2024-12-31T12:00:00+09:00',
                'saved_at' => '2024-12-31T12:00:01+09:00', 'scheduled_start_at' => '2025-01-01T10:56:00+09:00'];
            $rows = [];
            foreach ($base->history as $row) {
                $rows[] = array_replace($row, ['scheduled_start_at' => $row['race_date'].'T23:00:00+09:00',
                    'result_fetched_at' => '2024-12-30T12:00:00+09:00', 'result_saved_at' => '2024-12-30T12:00:01+09:00',
                    'prediction_observation' => $row['observation'] + ['fetched_at' => '2024-12-29T12:00:00+09:00',
                        'saved_at' => '2024-12-29T12:00:01+09:00']]);
            }
            $calculate = fn ($history) => (new Stat38Calculator(new DeterministicJsonHasher))->calculate(
                new Stat38InputDto($base->raceId, $base->raceDate, $base->inputAsOf, $base->entry, $target, $target, $history));
            $this->assertSame($states[0] === 'FINISHED' ? 2 : 0, $calculate($rows)->features['PREDICTION_EXPERIENCE_COUNT']);
            foreach (['result_fetched_at', 'result_saved_at', 'fetched_at', 'saved_at'] as $key) {
                foreach ([null, $base->inputAsOf->format(DATE_ATOM), $base->inputAsOf->modify('+1 second')->format(DATE_ATOM)] as $stamp) {
                    $changed = $rows;
                    if (str_starts_with($key, 'result_')) {
                        $changed[0][$key] = $stamp;
                    } else {
                        $changed[0]['prediction_observation'][$key] = $stamp;
                    }
                    $this->assertNull($calculate($changed)->features['PREDICTION_EXPERIENCE_COUNT']);
                }
            }
        }
    }

    private function participationInput(array $states, array $verified): Stat38InputDto
    {
        $path = getenv('STAT38_TEST_FIXTURE') ?: '/home/shinya/neo-keirin-artifacts/stat38-distance-laps-mvp-01-20261021-01/actual-fixture.json';
        if (hash_file('sha256', $path) !== '7ec6fae7e6ff5f8137754986c8f9e8d10bd766b9c65f61aa1252944494ba04dc') {
            throw new RuntimeException('Real target identity fixture seal mismatch.');
        }
        $target = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR)['cases'][0];
        $historyPath = dirname($path).'/history-fixture.json';
        if (hash_file('sha256', $historyPath) !== '0344d20e9db4918a6a1a8cc0f691cdea724c22fc1b90c9ce2520ce12a02379ca') {
            throw new RuntimeException('Real identity fixture seal mismatch.');
        }
        $cases = json_decode(file_get_contents($historyPath), true, 512, JSON_THROW_ON_ERROR)['cases'];
        $entry = array_values(array_filter($target['target']['entries'], fn ($e) => $e['external_player_id'] === '014236'))[0];
        $rows = [];
        foreach ($states as $index => $state) {
            $case = $cases[$index];
            $pastEntry = array_values(array_filter($case['target']['entries'], fn ($e) => $e['external_player_id'] === '014236'))[0];
            $rows[] = ['race_id' => $case['target']['race']['race_id'], 'race_date' => $case['target']['race']['race_date'],
                'entry_id' => $pastEntry['id'], 'player_id' => null, 'external_player_id' => '014236', 'bike_number' => $pastEntry['bike_number'],
                'result_status' => $state, 'result_link_verified' => $verified[$index],
                'scheduled_start_at' => null, 'result_fetched_at' => null, 'result_saved_at' => null,
                'prediction_observation' => null, 'observation' => [
                    'race_id' => $case['target']['race']['race_id'], 'race_date' => $case['target']['race']['race_date'],
                    'planned_distance_m' => $case['expected']['distance'],
                    'entrants' => [['bike_number' => $pastEntry['bike_number'], 'external_player_id' => '014236']]]];
        }
        $race = $target['target']['race'];

        return new Stat38InputDto($race['race_id'], $race['race_date'], new DateTimeImmutable('2025-01-01T10:53:00+09:00'),
            ['id' => $entry['id'], 'race_id' => $race['race_id'], 'bike_number' => $entry['bike_number'], 'player_id' => null, 'external_player_id' => '014236'],
            null, ['race_id' => $race['race_id'], 'race_date' => $race['race_date'], 'planned_distance_m' => $target['expected']['distance'],
                'planned_laps' => $target['expected']['laps'], 'entrants' => [['bike_number' => $entry['bike_number'], 'external_player_id' => '014236']]], $rows);
    }

    private function actualArguments(): array
    {
        $path = getenv('STAT38_TEST_FIXTURE') ?: '/home/shinya/neo-keirin-artifacts/stat38-distance-laps-mvp-01-20261021-01/actual-fixture.json';
        if (! is_file($path) || hash_file('sha256', $path) !== '7ec6fae7e6ff5f8137754986c8f9e8d10bd766b9c65f61aa1252944494ba04dc') {
            throw new RuntimeException('Actual fixture missing/seal drift; no synthetic success fallback.');
        }
        $case = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR)['cases'][0];
        $bytes = file_get_contents(dirname($path).'/'.$case['fixture_file']);
        if (strlen($bytes) !== $case['original']['bytes'] || hash('sha256', $bytes) !== $case['original']['sha256']) {
            throw new RuntimeException('Actual Raw seal drift.');
        }
        [$html] = (new CharacterEncodingConverter)->convertToUtf8($bytes, $case['log']['content_type']);
        $extractor = new EmbeddedJsonExtractor;
        $page = (new RaceDetailParser($extractor))->parse($html);
        $raw = $extractor->extract($html, 'PC0201')['C0201data']['C0201racedtl'];

        return ['raceId' => $case['target']['race']['race_id'], 'raceDate' => $case['target']['race']['race_date'],
            'trackCode' => $page->trackCode, 'raceNumber' => $page->raceNumber, 'fetchLogId' => $case['log']['id'],
            'distance' => $page->distance, 'laps' => $page->laps, 'fetchedAt' => new DateTimeImmutable($case['log']['fetched_at']),
            'scheduledStartAt' => DateTimeImmutable::createFromFormat('!Ymd H:i', $page->raceDate.' '.$page->startTime, new DateTimeZone('Asia/Tokyo')),
            'sourceUrl' => $case['log']['request_url'], 'rawFilePath' => $case['log']['raw_file_path'],
            'rawSha256' => $case['original']['sha256'], 'rawBytes' => strlen($bytes), 'convertedSha256' => hash('sha256', $html),
            'sourceMetadataHash' => hash('sha256', json_encode($case['log'], JSON_THROW_ON_ERROR)),
            'sourceParserVersion' => $case['log']['parser_version'],
            'rawFields' => ['kyori' => ['present' => true, 'value' => $raw['kyori']], 'syukai' => ['present' => true, 'value' => $raw['syukai']]],
            'entrants' => array_map(fn ($entry) => ['bike_number' => $entry->bikeNumber, 'external_player_id' => $entry->externalPlayerId], $page->entries)];
    }
}
