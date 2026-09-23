<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\TrackContext;

use App\Domain\Keirin\Scraping\Enums\AgariStatus;
use App\Domain\Keirin\Scraping\Enums\RaceEntryResultStatus;
use App\Domain\Keirin\TrackContext\AgariSpeedCalculator;
use App\Domain\Keirin\TrackContext\CoverageReporter;
use App\Domain\Keirin\TrackContext\OfficialStructureParser;
use App\Domain\Keirin\TrackContext\StructureValues;
use App\Domain\Keirin\TrackContext\TrackContextMaster;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\TrackContextFixture;

final class TrackContextTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/track-context-test-'.bin2hex(random_bytes(8)).'/v1';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
            rmdir(dirname($this->directory));
        }
    }

    private function master(?array $files = null): TrackContextMaster
    {
        TrackContextFixture::write($this->directory, $files ?? TrackContextFixture::files());

        return TrackContextMaster::load($this->directory, 'v1');
    }

    public function test_official_excerpt_extracts_units_and_dms_without_decimal_degree_confusion(): void
    {
        $parser = new OfficialStructureParser;
        $fields = $parser->parse(file_get_contents(__DIR__.'/../../../../Fixtures/TrackContext/actual/seibuen-structure.html'));
        self::assertSame('400', $fields['bank_circumference_m']['value']);
        self::assertSame('m', $fields['bank_circumference_m']['unit']);
        self::assertSame(['degrees' => 29, 'minutes' => 26, 'seconds' => 54, 'arcseconds' => 106014], $fields['cant']['value']);
        self::assertSame('29°26′54″', $fields['cant']['raw']);
        self::assertArrayNotHasKey('indoor_outdoor', $fields);
    }

    #[DataProvider('decimalCases')]
    public function test_exact_half_lap_conversion_and_optional_missing_fields(string $circumference, string $seconds, string $kmh): void
    {
        $files = TrackContextFixture::files();
        TrackContextFixture::circumference($files, $circumference);
        $resolution = $this->master($files)->resolve('keirin_jp', '11', '2023-01-01');
        $calculator = new AgariSpeedCalculator;
        $result = $calculator->calculate($resolution, $seconds, 'half-lap', AgariStatus::Valid, RaceEntryResultStatus::Finished);
        self::assertSame($kmh, $result['speed_kmh']);
        self::assertSame($circumference, $resolution->layout['fields']['bank_circumference_m']['value']);
        self::assertSame($result, $calculator->calculate($resolution, $seconds, 'half-lap', AgariStatus::Valid, RaceEntryResultStatus::Finished));
        self::assertTrue($result['calculable']);
        self::assertSame('NOT_AUTHORIZED', $result['prediction_use']);
        self::assertFalse($result['historical_as_of_available']);
        self::assertNull($result['measurement_precision_seconds']);
    }

    public static function decimalCases(): array
    {
        return [['400', '12', '60.000000000000'], ['500', '15', '60.000000000000'],
            ['333', '10', '59.940000000000'], ['335', '10', '60.300000000000'],
            ['333.3', '10', '59.994000000000'], ['400', '7', '102.857142857143'],
            ['0.000000000001', '1', '0.000000000002']];
    }

    public function test_half_even_rounding_and_no_intermediate_rounding(): void
    {
        $result = (new AgariSpeedCalculator)->calculate($this->master()->resolve('keirin_jp', '11', '2023-01-01'), '12', 'half-lap', AgariStatus::Valid, RaceEntryResultStatus::Tied);
        self::assertSame('16.666666666667', $result['speed_mps']);
        self::assertSame('60.000000000000', $result['speed_kmh']);
        $files = TrackContextFixture::files();
        TrackContextFixture::circumference($files, '0.000000000001');
        $result = (new AgariSpeedCalculator)->calculate($this->master($files)->resolve('keirin_jp', '11', '2023-01-01'), '1', 'half-lap', AgariStatus::Valid, RaceEntryResultStatus::Finished);
        self::assertSame('0.000000000000', $result['speed_mps']);
    }

    public function test_renovation_boundary_conflict_and_current_value_never_falls_back(): void
    {
        $files = TrackContextFixture::files();
        $layouts = &$files['tracks.json']['keirin_jp:11']['layouts'];
        $layouts[] = $layouts[0];
        $layouts[1]['layout_version'] = 'synthetic-b';
        $layouts[1]['period']['from'] = '2024-01-01';
        $layouts[1]['period']['until'] = '2025-01-01';
        TrackContextFixture::circumference($files, '333.3', 1);
        $master = $this->master($files);
        self::assertSame('synthetic-a', $master->resolve('keirin_jp', '11', '2023-12-31')->layout['layout_version']);
        self::assertSame('synthetic-b', $master->resolve('keirin_jp', '11', '2024-01-01')->layout['layout_version']);
        self::assertSame('UNKNOWN_LAYOUT_VERSION', $master->resolve('keirin_jp', '11', '2025-01-01')->status);
        $layouts[1]['period']['from'] = '2023-12-31';
        self::assertSame('SOURCE_CONFLICT', $this->master($files)->resolve('keirin_jp', '11', '2023-12-31')->status);
        $layouts[1]['period'] = ['status' => 'UNKNOWN', 'from' => null, 'until' => null, 'source_refs' => [], 'evidence' => 'Current observation only'];
        self::assertSame('UNKNOWN_LAYOUT_VERSION', $this->master($files)->resolve('keirin_jp', '11', '2025-01-01')->status);
    }

    #[DataProvider('statusCases')]
    public function test_missing_and_abnormal_statuses_do_not_return_zero(AgariStatus $agari, RaceEntryResultStatus $result, ?string $time, string $definition, string $reason): void
    {
        $output = (new AgariSpeedCalculator)->calculate($this->master()->resolve('keirin_jp', '11', '2023-01-01'), $time, $definition, $agari, $result);
        self::assertSame($reason, $output['reason']);
        self::assertFalse($output['calculable']);
        self::assertNull($output['speed_kmh']);
        self::assertNull($output['speed_mps']);
    }

    public static function statusCases(): array
    {
        $cases = [];
        foreach (AgariStatus::cases() as $status) {
            if ($status !== AgariStatus::Valid) {
                $cases[] = [$status, RaceEntryResultStatus::Finished, null, 'half-lap', $status->value];
            }
        }
        foreach (RaceEntryResultStatus::cases() as $status) {
            if (! in_array($status, [RaceEntryResultStatus::Finished, RaceEntryResultStatus::Tied], true)) {
                $cases[] = [AgariStatus::Valid, $status, '12', 'half-lap', 'ABNORMAL_RESULT'];
            }
        }
        $cases[] = [AgariStatus::Valid, RaceEntryResultStatus::Finished, null, 'half-lap', 'MISSING_TIME'];
        $cases[] = [AgariStatus::Valid, RaceEntryResultStatus::Finished, '12', 'other', 'MEASUREMENT_DEFINITION_MISMATCH'];

        return $cases;
    }

    #[DataProvider('invalidDecimals')]
    public function test_invalid_decimal_values_are_rejected(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        StructureValues::positiveDecimal($value);
    }

    public static function invalidDecimals(): array
    {
        return [['0'], ['-12'], [''], ['NaN'], ['Infinity'], ['12sec'], ['1e2'], [12.0], [null]];
    }

    public function test_invalid_time_is_rejected_by_calculator(): void
    {
        $resolution = $this->master()->resolve('keirin_jp', '11', '2023-01-01');
        $this->expectException(InvalidArgumentException::class);
        (new AgariSpeedCalculator)->calculate($resolution, '0', 'half-lap', AgariStatus::Valid, RaceEntryResultStatus::Finished);
    }

    public function test_unknown_distance_and_definition_are_distinct_from_unknown_layout(): void
    {
        $files = TrackContextFixture::files();
        $field = &$files['tracks.json']['keirin_jp:11']['layouts'][0]['fields']['segment_distance_m'];
        $field['status'] = 'MISSING';
        $field['value'] = null;
        $field['reason'] = 'UNCONFIRMED';
        $calculator = new AgariSpeedCalculator;
        $master = $this->master($files);
        self::assertSame('UNKNOWN_MEASUREMENT_DISTANCE', $calculator->calculate($master->resolve('keirin_jp', '11', '2023-01-01'), '12', 'half-lap', AgariStatus::Valid, RaceEntryResultStatus::Finished)['reason']);
        self::assertSame('UNKNOWN_LAYOUT_VERSION', $calculator->calculate($master->resolve('keirin_jp', '11', '2025-01-01'), '12', 'half-lap', AgariStatus::Valid, RaceEntryResultStatus::Finished)['reason']);
        $files['definitions.json']['half-lap']['status'] = 'UNKNOWN';
        self::assertSame('UNKNOWN_MEASUREMENT_DEFINITION', $calculator->calculate($this->master($files)->resolve('keirin_jp', '11', '2023-01-01'), '12', 'half-lap', AgariStatus::Valid, RaceEntryResultStatus::Finished)['reason']);
    }

    #[DataProvider('badMasters')]
    public function test_invalid_master_semantics_are_rejected(string $case): void
    {
        $files = TrackContextFixture::files();
        $layout = &$files['tracks.json']['keirin_jp:11']['layouts'][0];
        switch ($case) {
            case 'reference': $layout['fields']['cant']['source_refs'] = ['absent'];
                break;
            case 'missing': unset($layout['fields']['bank_circumference_m']);
                break;
            case 'distance': $layout['fields']['segment_distance_m']['value'] = '201';
                break;
            case 'definition': $layout['measurement_definition_id'] = 'absent';
                break;
            case 'period': $layout['period']['from'] = '2024-01-01';
                break;
            case 'date': $layout['period']['from'] = '2023-02-30';
                break;
            case 'no_evidence': $layout['period']['source_refs'] = [];
                break;
            case 'negative': $layout['fields']['bank_circumference_m']['value'] = '-400';
                break;
            case 'raw_mismatch': $layout['fields']['bank_circumference_m']['raw'] = '500m';
                break;
            case 'excerpt': $files['sources.json']['synthetic']['excerpt_file'] = 'absent';
                break;
            case 'duplicate': $files['tracks.json']['keirin_jp:11']['layouts'][] = $layout;
                break;
        }
        $this->expectException(\Exception::class);
        $this->master($files);
    }

    public static function badMasters(): array
    {
        return array_map(static fn ($case) => [$case], ['reference', 'missing', 'distance', 'definition', 'period', 'date', 'no_evidence', 'negative', 'raw_mismatch', 'excerpt', 'duplicate']);
    }

    public function test_one_byte_master_corruption_fails_closed(): void
    {
        $this->master();
        file_put_contents($this->directory.'/synthetic.txt', 'Synthetic');
        $this->expectException(RuntimeException::class);
        TrackContextMaster::load($this->directory, 'v1');
    }

    public function test_no_latest_fallback(): void
    {
        $this->master();
        $this->expectException(InvalidArgumentException::class);
        TrackContextMaster::load($this->directory, 'latest');
    }

    #[DataProvider('badExcerpts')]
    public function test_invalid_structural_excerpt_is_rejected(string $html): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new OfficialStructureParser)->parse($html);
    }

    public static function badExcerpts(): array
    {
        return [['<table></table>'],
            ['<table class="hyo3"><tr><th>周長</th><td>400ft</td></tr></table>'],
            ['<table class="hyo3"><tr><th>周長</th><td>0m</td></tr></table>'],
            ['<table class="hyo3"><tr><th>周長</th><td>400m</td></tr><tr><th>周長</th><td>500m</td></tr></table>'],
            ['<table class="hyo3"><tr><th>周長</th><td>400m</td></tr><tr><th>センター傾斜角</th><td>29.2654</td></tr></table>'],
            ['<table class="hyo3"><tr><th>周長</th><td>400m</td></tr><tr><th>センター傾斜角</th><td>29°60′00″</td></tr></table>']];
    }

    public function test_conflicting_source_fields_refuse_resolution_and_speed(): void
    {
        $files = TrackContextFixture::files();
        $field = &$files['tracks.json']['keirin_jp:11']['layouts'][0]['fields']['cant'];
        $field['status'] = 'CONFLICT';
        $field['source_refs'] = ['synthetic'];
        $field['reason'] = 'Conflicting synthetic source observations';
        $resolution = $this->master($files)->resolve('keirin_jp', '11', '2023-01-01');
        self::assertSame('SOURCE_CONFLICT', $resolution->status);
        $output = (new AgariSpeedCalculator)->calculate($resolution, '12', 'half-lap', AgariStatus::Valid, RaceEntryResultStatus::Finished);
        self::assertSame('SOURCE_CONFLICT', $output['reason']);
        self::assertNull($output['speed_kmh']);
    }

    public function test_coverage_counts_target_days_including_unknown_and_conflict(): void
    {
        $targets = [['source' => 'keirin_jp', 'external_track_id' => '11', 'race_date' => '2023-01-01'],
            ['source' => 'keirin_jp', 'external_track_id' => '12', 'race_date' => '2023-01-01']];
        $master = $this->master();
        $report = (new CoverageReporter)->report($master, $targets);
        self::assertSame(['RESOLVED' => 1, 'UNKNOWN_LAYOUT_VERSION' => 1, 'SOURCE_CONFLICT' => 0], $report['status_counts']);
        self::assertSame(2, $report['track_count']);
        self::assertSame(1, $report['distance_resolved_days']);
        self::assertSame($report, (new CoverageReporter)->report($master, $targets));
        $this->expectException(InvalidArgumentException::class);
        (new CoverageReporter)->report($master, [$targets[0], $targets[0]]);
    }

    public function test_2026_target_inventory_is_rejected_without_db_access(): void
    {
        $master = $this->master();
        $this->expectException(InvalidArgumentException::class);
        (new CoverageReporter)->report($master, [['source' => 'keirin_jp', 'external_track_id' => '11', 'race_date' => '2026-01-01']]);
    }
}
