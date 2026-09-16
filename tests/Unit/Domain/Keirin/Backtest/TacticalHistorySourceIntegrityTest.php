<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\SourceIntegrity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class TacticalHistorySourceIntegrityTest extends TestCase
{
    #[DataProvider('cases')]
    public function test_both_existing_end_checks_are_required(bool $fixedPass, bool $historyPass): void
    {
        $calls = [];
        $expected = ['fingerprint_digest' => 'fixed-stat-digest'];
        try {
            $result = (new SourceIntegrity)->verify($expected, function () use (&$calls, $fixedPass, $expected): array {
                $calls[] = 'fixed';

                return $fixedPass ? $expected : ['fingerprint_digest' => 'drift'];
            }, function () use (&$calls, $historyPass): array {
                $calls[] = 'history';
                if (! $historyPass) {
                    throw new RuntimeException('History drift.');
                }

                return ['status' => 'VERIFIED_CURRENT_STATE_AGAINST_INPUT_SNAPSHOT'];
            });
            $this->assertTrue($fixedPass && $historyPass);
            $this->assertSame('VERIFIED_CURRENT_STATE_AGAINST_INPUT_SNAPSHOT', $result['status']);
        } catch (RuntimeException) {
            $this->assertFalse($fixedPass && $historyPass);
        }
        $this->assertSame($fixedPass ? ['fixed', 'history'] : ['fixed'], $calls);
    }

    public static function cases(): iterable
    {
        yield [true, true];
        yield [false, true];
        yield [true, false];
    }
}
