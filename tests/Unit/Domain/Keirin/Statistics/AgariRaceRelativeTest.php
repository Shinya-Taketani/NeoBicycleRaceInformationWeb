<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Statistics;

use App\Domain\Keirin\Scraping\Enums\AgariStatus;
use App\Domain\Keirin\Scraping\Enums\RaceEntryResultStatus;
use App\Domain\Keirin\Scraping\Support\RaceCategoryPolicy;
use App\Domain\Keirin\Statistics\AgariRaceRelative\Calculator;
use App\Domain\Keirin\Statistics\AgariRaceRelative\Contract;
use App\Domain\Keirin\TrackContext\AgariSpeedCalculator;
use App\Domain\Keirin\TrackContext\TrackContextMaster;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\AgariRaceRelativeFixture as Fixture;
use Tests\Support\TrackContextFixture;

class AgariRaceRelativeTest extends TestCase
{
    private function master(): TrackContextMaster
    {
        return TrackContextMaster::load(dirname(__DIR__, 5).'/resources/data/keirin/track-context/v2', 'v2');
    }

    private function calculate(array $race, ?TrackContextMaster $master = null): array
    {
        return (new Calculator(new RaceCategoryPolicy, new AgariSpeedCalculator))->calculate($race, $master ?? $this->master());
    }

    public function test_exact_example_equal_decimal_and_output_only_rounding(): void
    {
        $result = $this->calculate(Fixture::race(times: ['12.0', '12.00', '13', '14']));
        $rows = $result['results'];
        $this->assertSame([1, 1, 3, 4], array_column($rows, 'rank_min'));
        $this->assertSame(['1.5', '1.5', '3', '4'], array_column($rows, 'rank_average'));
        $this->assertSame(['0.833333333333', '0.833333333333', '0.333333333333', '0'], array_column($rows, 'percentile'));
        $this->assertSame([5, 5, 2, 0], array_column($rows, 'percentile_numerator'));
        $this->assertSame([6, 6, 6, 6], array_column($rows, 'percentile_denominator'));
        $this->assertSame(['0', '0', '1', '2'], array_column($rows, 'gap_to_fastest_seconds'));
        $this->assertSame(['12.0', '12.00', '13', '14'], array_column($rows, 'agari_time_seconds'));
        $this->assertSame('UNKNOWN_LAYOUT_VERSION', $rows[0]['speed']['reason']);
        $this->assertSame('CALCULATED', $result['relative_status']);
        foreach (Contract::DISCLOSURE as $key => $value) {
            $this->assertSame($value, $result[$key]);
        }
    }

    #[DataProvider('fieldSizes')]
    public function test_full_fields_all_tied_input_permutation_and_faster_monotonicity(int $n): void
    {
        $race = Fixture::race(times: array_fill(0, $n, '12'));
        $result = $this->calculate($race);
        $this->assertSame(array_fill(0, $n, '0.5'), array_column($result['results'], 'percentile'));
        $this->assertSame(array_fill(0, $n, '0'), array_column($result['results'], 'gap_to_fastest_seconds'));
        $race['results'] = array_reverse($race['results']);
        $this->assertSame($result, $this->calculate($race));
        Fixture::value($race, 0, 'agari_time_seconds', '11.9999999999999999999999999');
        Fixture::value($race, 0, 'agari_raw_text', '11.9999999999999999999999999');
        $faster = $this->calculate($race)['results'][$n - 1];
        $this->assertSame('1', $faster['rank_average']);
        $this->assertSame('1', $faster['percentile']);
        $this->assertLessThanOrEqual((float) $result['results'][$n - 1]['rank_average'], (float) $faster['rank_average']);
    }

    public static function fieldSizes(): array
    {
        return [[7], [9]];
    }

    public function test_partial_normal_missing_abnormal_and_official_tie_are_separate(): void
    {
        $race = Fixture::race(times: ['12', '13', null, '14', '15', null, '16']);
        Fixture::value($race, 0, 'result_status', 'TIED');
        Fixture::value($race, 1, 'result_status', 'TIED');
        Fixture::value($race, 3, 'result_status', 'CRASHED');
        Fixture::value($race, 3, 'agari_status', 'OBSERVED_ABNORMAL_RESULT');
        Fixture::value($race, 5, 'result_status', 'DID_NOT_START');
        $result = $this->calculate($race);
        $this->assertSame('PARTIAL', $result['comparison_completeness']);
        $this->assertSame(5, $result['normal_finisher_count']);
        $this->assertSame(4, $result['valid_timing_count']);
        $this->assertSame(1, $result['missing_or_invalid_normal_finisher_count']);
        $this->assertSame(2, $result['abnormal_result_count']);
        $this->assertSame(1, $result['results'][0]['rank_min']);
        $this->assertSame(2, $result['results'][1]['rank_min']);
        $this->assertNull($result['results'][2]['rank_min']);
        $this->assertSame('ABNORMAL_RESULT', $result['results'][3]['relative_status']);
    }

    #[DataProvider('insufficient')]
    public function test_zero_or_one_valid_timing_is_not_a_rank(array $times): void
    {
        $result = $this->calculate(Fixture::race(times: $times));
        $this->assertSame('INSUFFICIENT_COMPARISON', $result['relative_status']);
        $this->assertSame(array_fill(0, count($times), null), array_column($result['results'], 'rank_average'));
    }

    public static function insufficient(): array
    {
        return [[[null, null, null]], [['12', null, null]]];
    }

    #[DataProvider('integrityCases')]
    public function test_entire_race_is_excluded_for_provenance_or_identity_conflicts(string $case, string $reason): void
    {
        $race = Fixture::race();
        switch ($case) {
            case 'mixed': $race['results'][0]['race_result_import_id'] = 99;
                break;
            case 'count': $race['results'][0]['import']['result_count'] = 6;
                break;
            case 'failed': $race['results'][0]['import']['import_status'] = 'FAILED';
                break;
            case 'import-race': $race['results'][0]['import']['race_id'] = 99;
                break;
            case 'missing': $race['results'][0]['observation'] = null;
                break;
            case 'bike': $race['results'][0]['observation']['bike_number'] = 9;
                break;
            case 'obs-race': $race['results'][0]['observation']['race_id'] = 99;
                break;
            case 'value': $race['results'][0]['observation']['agari_time_seconds'] = '20';
                break;
            case 'hash': $race['results'][0]['observation']['metadata']['source_hash'] = str_repeat('b', 64);
                break;
            case 'converted': $race['results'][0]['observation']['metadata']['converted_hash'] = null;
                break;
            case 'duplicate': $race['results'][1]['bike_number'] = 1;
                break;
            case 'definition': $race['results'][0]['observation']['metadata']['semantic'] = 'FULL_LAP';
                break;
            case 'normalizer': $race['results'][0]['observation']['metadata']['normalizer_version'] = 'unknown';
                break;
            case 'source': $race['results'][0]['import']['source_url'] = 'https://other.invalid/result';
                break;
            case 'cancelled': $race['race_status'] = 'CANCELLED';
                break;
            case 'provisional': $race['race_status'] = 'PROVISIONAL';
                break;
            case 'girls': $race['race_type'] = 'L級ガールズ';
                break;
            case 'empty': $race['results'] = [];
                break;
        }
        $result = $this->calculate($race);
        $this->assertContains($reason, $result['exclusion_reasons']);
        $this->assertSame('EXCLUDED', $result['relative_status']);
        foreach ($result['results'] as $row) {
            $this->assertNull($row['rank_min']);
            $this->assertFalse($row['speed']['calculable']);
        }
    }

    public static function integrityCases(): array
    {
        return [['mixed', 'MIXED_IMPORT_VERSIONS'], ['count', 'IMPORT_RESULT_COUNT_MISMATCH'],
            ['failed', 'INVALID_IMPORT_REFERENCE'], ['import-race', 'INVALID_IMPORT_REFERENCE'], ['missing', 'MISSING_OBSERVATION'],
            ['bike', 'OBSERVATION_IDENTITY_OR_VALUE_MISMATCH'], ['obs-race', 'OBSERVATION_IDENTITY_OR_VALUE_MISMATCH'],
            ['value', 'OBSERVATION_IDENTITY_OR_VALUE_MISMATCH'], ['hash', 'SOURCE_HASH_MISMATCH'], ['converted', 'SOURCE_HASH_MISMATCH'],
            ['duplicate', 'INVALID_OR_DUPLICATE_RESULT_IDENTITY'], ['definition', 'UNCONFIRMED_MEASUREMENT_MAPPING'],
            ['normalizer', 'UNCONFIRMED_MEASUREMENT_MAPPING'], ['source', 'UNCONFIRMED_MEASUREMENT_MAPPING'],
            ['cancelled', 'CANCELLED'], ['provisional', 'RESULT_NOT_FINAL'], ['girls', 'UNSUPPORTED_CATEGORY'], ['empty', 'NO_CURRENT_RESULTS']];
    }

    public function test_auxiliary_ids_can_differ_and_missing_external_id_is_not_invented(): void
    {
        $race = Fixture::race();
        $race['results'][0]['observation']['player_id'] = 700;
        $race['results'][0]['observation']['race_entry_id'] = 800;
        $race['results'][1]['observation']['external_player_id'] = null;
        $result = $this->calculate($race);
        $this->assertSame([], $result['exclusion_reasons']);
        $this->assertSame(700, $result['results'][0]['observation_player_id']);
        $this->assertNull($result['results'][0]['current_player_id']);
        $this->assertSame('UNRESOLVED_EXTERNAL_ID', $result['results'][1]['link_status']);
        $this->assertNull($result['results'][1]['external_player_id']);
    }

    public function test_resolved_v2_speed_exactly_reuses_existing_calculator(): void
    {
        $race = Fixture::race(date: '2023-06-29');
        $race['context']['track_code'] = '22';
        $master = $this->master();
        $expected = (new AgariSpeedCalculator)->calculate($master->resolve('keirin_jp', '22', '2023-06-29'), '12.0',
            Contract::DEFINITION, AgariStatus::Valid, RaceEntryResultStatus::Finished);
        $this->assertTrue($expected['calculable']);
        $this->assertSame($expected, $this->calculate($race)['results'][0]['speed']);
    }

    public function test_unknown_common_definition_blocks_relative_even_without_resolved_distance(): void
    {
        $directory = sys_get_temp_dir().'/agari-definition-'.bin2hex(random_bytes(8)).'/v1';
        TrackContextFixture::write($directory, TrackContextFixture::files());
        try {
            $master = TrackContextMaster::load($directory, 'v1');
            $result = $this->calculate(Fixture::race(), $master);
            $this->assertContains('UNKNOWN_MEASUREMENT_DEFINITION', $result['exclusion_reasons']);
        } finally {
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
            rmdir(dirname($directory));
        }
    }

    #[DataProvider('invalidNumbers')]
    public function test_invalid_numeric_is_rejected_not_disguised_as_missing(string $time): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calculate(Fixture::race(times: [$time, '12']));
    }

    public static function invalidNumbers(): array
    {
        return [['NaN'], ['INF'], ['-1'], ['0'], ['1e2']];
    }
}
