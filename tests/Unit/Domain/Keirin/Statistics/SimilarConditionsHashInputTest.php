<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Statistics;

use App\Domain\Keirin\Statistics\Support\SimilarConditionsHashInput as HashInput;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class SimilarConditionsHashInputTest extends TestCase
{
    public function test_missing_null_empty_and_missing_conditions_remain_distinct(): void
    {
        $values = [[], ['raw' => []], ['raw' => ['scheduled_start_at' => null]], ['raw' => ['scheduled_start_at' => '']],
            ['sources' => ['meeting_start' => '2024-01-01']]];
        foreach ($values as $value) {
            $this->assertSame($value, HashInput::conditions($value));
        }
        $refs = [[], ['conditions' => null], ['conditions' => []]];
        $this->assertSame($refs, HashInput::references($refs));
        $this->assertSame(HashInput::conditions(['raw' => ['scheduled_start_at' => '2024-01-01 12:00:00.000001']]),
            HashInput::conditions(['raw' => ['scheduled_start_at' => '2024-01-01T03:00:00.000001Z']]));
    }

    public function test_invalid_nonempty_timestamp_is_rejected_not_replaced(): void
    {
        $this->expectException(UnexpectedValueException::class);
        HashInput::conditions(['sources' => ['fetched_at' => '2024-02-30T12:00:00+09:00']]);
    }
}
