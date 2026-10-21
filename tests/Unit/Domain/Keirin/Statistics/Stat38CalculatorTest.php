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
