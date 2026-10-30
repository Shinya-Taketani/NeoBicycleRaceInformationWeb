<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Statistics;

use App\Domain\Keirin\Statistics\Services\SavedResultWeatherProjectionService;
use PHPUnit\Framework\TestCase;

final class SavedResultWeatherProjectionTest extends TestCase
{
    public function test_projection_distinguishes_missing_null_empty_zero_invalid_and_unknown_unit(): void
    {
        // Synthetic boundary values only; real PJ0326 acceptance is recorded separately.
        $reflection = new \ReflectionClass(SavedResultWeatherProjectionService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        foreach ([[[], 'MISSING'], [['husoku' => null], 'NULL'], [['husoku' => ''], 'EMPTY'],
            [['husoku' => '0'], 'NUMERIC_ZERO_UNIT_UNVERIFIED'], [['husoku' => '1.3'], 'NUMERIC_UNIT_UNVERIFIED'],
            [['husoku' => '-1'], 'NON_NUMERIC_OR_INVALID'], [['husoku' => []], 'INVALID_TYPE']] as [$input, $expected]) {
            $out = $service->fields($input)['husoku'];
            $this->assertSame($expected, $out['state']);
            $this->assertNull($out['unit']);
            $this->assertSame($input['husoku'] ?? null, $out['raw']);
            $this->assertSame(array_key_exists('husoku', $input), $out['present']);
        }
    }
}
