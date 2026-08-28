<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Services\Bt03e05DevelopmentEvaluationService;
use App\Domain\Keirin\Backtest\Support\Bt03e05RaceSpool;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

class Bt03e05CohortTest extends TestCase
{
    public function test_source_and_context_join_by_stream_identity_without_race_id_monotonicity(): void
    {
        $source = $this->spool('SOURCE', [[20, [1, 2, 3, 4, 5]], [10, [1, 2, 3, 4, 5]]]);
        $context = $this->spool('CONTEXT', [[20, [5, 4, 3, 2, 1]], [10, [1, 2, 3, 4, 5]]]);
        try {
            $seen = [];
            $this->evaluateYear($source, $context, static function (array $sourceRace, array $contextRace) use (&$seen): void {
                $seen[] = [$sourceRace['race_id'], $contextRace['race_id']];
            });

            $this->assertSame([[20, 20], [10, 10]], $seen);
        } finally {
            $source->cleanup();
            $context->cleanup();
        }
    }

    public function test_missing_extra_or_different_bike_set_fails_closed(): void
    {
        $source = $this->spool('SOURCE', [[20, [1, 2, 3, 4, 5]]]);
        $context = $this->spool('CONTEXT', [[20, [1, 2, 3, 4, 6]]]);
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('cohorts differed');
            $this->evaluateYear($source, $context, static function (array $_source, array $_context): void {});
        } finally {
            $source->cleanup();
            $context->cleanup();
        }
    }

    /** @param list<array{int,list<int>}> $races */
    private function spool(string $role, array $races): Bt03e05RaceSpool
    {
        $spool = new Bt03e05RaceSpool($role, sys_get_temp_dir().'/bt03e05-cohort-'.strtolower($role).'-'.bin2hex(random_bytes(8)).'.jsonl');
        foreach ($races as [$raceId, $bikes]) {
            $spool->append([
                'year' => 2024,
                'race_id' => $raceId,
                'entries' => array_map(static fn (int $bike): array => ['bike' => $bike], $bikes),
            ]);
        }
        $spool->seal();

        return $spool;
    }

    private function evaluateYear(Bt03e05RaceSpool $source, Bt03e05RaceSpool $context, callable $consumer): void
    {
        $reflection = new ReflectionClass(Bt03e05DevelopmentEvaluationService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('evaluateYear');
        $method->invoke($service, $source, $context, $consumer);
    }
}
