<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Statistics;

use App\Domain\Keirin\Statistics\SavedWeather\Files;
use App\Domain\Keirin\Statistics\SavedWeather\Snapshot;
use App\Domain\Keirin\Statistics\Services\SavedResultWeatherProjectionService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SavedWeatherBindingTest extends TestCase
{
    public function test_fixed_old_import_is_not_replaced_by_current_correction(): void
    {
        $out = Snapshot::binding(['id' => 1, 'rank' => 1, 'race_result_import_id' => 10], ['id' => 1, 'rank' => 2, 'race_result_import_id' => 20]);
        $this->assertSame(10, $out['import_id']);
        $this->assertSame('FIXED_OLD_POINTER', $out['state']);
    }

    #[DataProvider('bindings')]
    public function test_b_c_binding_does_not_claim_historical_pointer(array $old, array $current, string $state, ?int $pointer): void
    {
        $out = Snapshot::binding($old, $current);
        $this->assertSame($state, $out['state']);
        $this->assertSame($pointer, $out['import_id']);
        $this->assertSame('NOT_PROVEN', $out['historical_import_pointer_at_snapshot']);
        $this->assertFalse($out['historical_as_of_available']);
    }

    public static function bindings(): array
    {
        return [[['id' => 1, 'rank' => 1], ['id' => 1, 'rank' => 1, 'race_result_import_id' => 2], 'CURRENT_SOURCE_ROW_MATCHED', 2],
            [['id' => 1, 'rank' => 1, 'race_result_import_id' => null], ['id' => 1, 'rank' => 1, 'race_result_import_id' => 2], 'CURRENT_SOURCE_ROW_MATCHED', 2],
            [['id' => 1, 'rank' => 1], ['id' => 1, 'rank' => 2, 'race_result_import_id' => 2], 'SOURCE_ROW_DRIFT', null],
            [['id' => 1, 'updated_at' => '2024-01-01T00:00:00+09:00'], ['id' => 1, 'updated_at' => '2024-01-01T00:00:01+09:00', 'race_result_import_id' => 2], 'SOURCE_ROW_DRIFT', null],
            [['id' => 1, 'rank' => null], ['id' => 1, 'rank' => '', 'race_result_import_id' => 2], 'SOURCE_ROW_DRIFT', null]];
    }

    #[DataProvider('values')]
    public function test_json_preserves_weather_v1_value_types_and_missing(mixed $value, bool $present): void
    {
        $service = (new \ReflectionClass(SavedResultWeatherProjectionService::class))->newInstanceWithoutConstructor();
        $row = $service->fields($present ? ['tenki' => $value, 'husoku' => $value] : []);
        $saved = json_decode(Files::json($row), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($row, $saved);
        $this->assertSame($value, $saved['husoku']['raw']);
        $this->assertSame($present, $saved['husoku']['present']);
        $this->assertNull($saved['husoku']['unit']);
    }

    public static function values(): array
    {
        return [[null, false], [null, true], ['', true], [0, true], [0.0, true], ['0', true], ['2.5', true], ['bad', true], [[], true]];
    }
}
