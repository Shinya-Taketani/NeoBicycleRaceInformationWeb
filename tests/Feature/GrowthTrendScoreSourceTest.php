<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Keirin\Backtest\Experiments\GrowthPointAnalysis\Signals;
use App\Domain\Keirin\Backtest\Experiments\GrowthTrendScoreSource\Contract;
use App\Domain\Keirin\Backtest\Experiments\GrowthTrendScoreSource\Query;
use App\Domain\Keirin\Backtest\Experiments\GrowthTrendScoreSource\Service;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionPipeline\ReadOnlySession;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\GrowthTrendFixture;
use Tests\TestCase;

final class GrowthTrendScoreSourceTest extends TestCase
{
    use GrowthTrendFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture();
    }

    protected function tearDown(): void
    {
        $this->cleanupFixture();
        parent::tearDown();
    }

    public function test_capture_uses_only_approved_tables_and_columns_and_verify_needs_no_db(): void
    {
        $queries = [];
        DB::listen(function ($event) use (&$queries): void {
            $queries[] = strtolower($event->sql);
        });
        $service = app(Service::class);
        $result = $service->capture($this->root.'/score', 'synthetic', $this->root.'/outer');
        $this->assertSame('SCORE_SOURCE_LOCKED', $result['status']);
        $this->assertSame(14, array_sum(array_column($result['targets'], 'entries')));
        $this->assertSame(182, array_sum(array_column($result['coverage'], 'observations')));
        foreach ($queries as $sql) {
            foreach (['race_results', 'result_status', 'rank', 'insert ', 'update ', 'delete '] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $sql);
            }
        }
        config(['database.default' => 'growth_disabled']);
        $this->assertSame('VERIFIED', $service->verify($result['bundle'])['status']);
        $this->assertLessThan(128 * 1024 * 1024, memory_get_peak_usage(true));
    }

    #[DataProvider('forbiddenQueries')]
    public function test_query_contract_rejects_unapproved_structure(string $kind): void
    {
        $reader = new Query;
        $q = $reader->build([1], [20241], 0);
        match ($kind) {
            'rank' => $q->addSelect('e.rank'), 'result_status' => $q->addSelect('r.result_status'),
            'table' => $q->join('race_results', 'race_results.race_id', '=', 'r.id'),
            '2026' => $q->wheres[0]['values'] = ['2022-01-01', '2026-12-31'],
            'union' => $q->union(DB::table('races')->select('id')),
            'bypass' => $q->orWhere('r.race_date', '>', '2025-12-31'),
        };
        $this->expectException(RuntimeException::class);
        $reader->approved($q);
    }

    public static function forbiddenQueries(): array
    {
        return array_map(fn ($k) => [$k], ['rank', 'result_status', 'table', '2026', 'union', 'bypass']);
    }

    public function test_read_only_session_refuses_writes(): void
    {
        $this->expectException(QueryException::class);
        app(ReadOnlySession::class)->run(fn () => DB::table('races')->delete());
    }

    public function test_targets_cannot_silently_disappear(): void
    {
        DB::table('race_entries')->where('id', 20241)->delete();
        $this->expectException(RuntimeException::class);
        app(Service::class)->capture($this->root.'/score', 'missing', $this->root.'/outer');
    }

    public function test_capture_is_deterministic_and_locked_cannot_be_overwritten(): void
    {
        $service = app(Service::class);
        $a = $service->capture($this->root.'/score', 'a', $this->root.'/outer');
        $b = $service->capture($this->root.'/score', 'b', $this->root.'/outer');
        $this->assertSame(Files::identity($a['bundle'].'/score-observations.jsonl'), Files::identity($b['bundle'].'/score-observations.jsonl'));
        $this->expectException(RuntimeException::class);
        $service->capture($this->root.'/score', 'a', $this->root.'/outer');
    }

    public function test_tampering_is_rejected(): void
    {
        $result = app(Service::class)->capture($this->root.'/score', 'tamper', $this->root.'/outer');
        file_put_contents($result['bundle'].'/coverage.json', '{}');
        $this->expectException(RuntimeException::class);
        app(Service::class)->verify($result['bundle']);
    }

    public function test_future_year_query_cannot_expand_and_score_is_exact(): void
    {
        $this->dbRace(3000, '2026-01-01', 3000, 100.0);
        $audit = [];
        $rows = iterator_to_array((new Query)->rows([1], [], $audit));
        $this->assertNotContains(30001, array_column($rows, 'entry_id'));
        $this->assertSame(10523, Signals::score('105.23'));
        $this->assertSame(Signals::score('105.2'), Signals::score('105.20'));
        $this->assertNull(Signals::score(null));
        $this->expectException(RuntimeException::class);
        Contract::year(2026);
    }

    public function test_history_pagination_has_no_duplicates_or_missing_rows(): void
    {
        for ($i = 1; $i <= 150; $i++) {
            $this->dbRace(500000 + $i, '2023-05-01', 500000 + $i, 90.0);
        }
        $audit = [];
        $ids = [];
        foreach ((new Query)->rows(range(1, 7), [], $audit) as $row) {
            $ids[] = $row['entry_id'];
        }
        $this->assertCount(1232, $ids);
        $this->assertCount(1232, array_unique($ids));
        $this->assertSame(2, $audit['select_queries']);
        $this->assertLessThan(128 * 1024 * 1024, memory_get_peak_usage(true));
    }

    #[DataProvider('invalidTargets')]
    public function test_target_identity_and_source_values_are_validated(string $kind): void
    {
        match ($kind) {
            'bike' => DB::table('race_entries')->where('id', 20241)->update(['bike_number' => 9]),
            'score' => DB::table('race_entries')->where('id', 20241)->update(['race_score' => 'unknown']),
            'player' => DB::table('race_entries')->where('id', 20241)->update(['player_id' => -1]),
            'meeting_date' => DB::table('race_meetings')->where('id', 2024)->update(['starts_on' => '2024-07-01']),
            'day_date' => DB::table('race_days')->where('id', 2024)->update(['race_date' => '2024-06-16']),
        };
        $this->expectException(RuntimeException::class);
        app(Service::class)->capture($this->root.'/score', 'invalid', $this->root.'/outer');
    }

    public static function invalidTargets(): array
    {
        return array_map(fn ($v) => [$v], ['bike', 'score', 'player', 'meeting_date', 'day_date']);
    }

    public function test_production_universe_is_fixed_and_plan_has_no_database_access(): void
    {
        config(['database.default' => 'growth_disabled']);
        $this->assertSame([2024 => 25212, 2025 => 24866], Contract::COUNTS);
        $this->assertSame([2024 => 179089, 2025 => 177120], Contract::ENTRIES);
        $this->artisan('keirin:backtest:growth-trend-score-source', ['--plan' => true])->assertSuccessful();
        $this->artisan('keirin:backtest:growth-trend-analysis', ['--plan' => true])->assertSuccessful();
    }

    public function test_start_end_drift_is_rejected_without_publication(): void
    {
        $this->app->instance(Query::class, new class extends Query
        {
            private int $historyReads = 0;

            public function rows(array $players, array $ids, array &$audit, bool $targets = false): \Generator
            {
                if (! $targets) {
                    $this->historyReads++;
                }
                foreach (parent::rows($players, $ids, $audit, $targets) as $row) {
                    if (! $targets && $this->historyReads === 2) {
                        $row['race_score'] = '99.99';
                    }
                    yield $row;
                }
            }
        });
        try {
            app(Service::class)->capture($this->root.'/score', 'drift', $this->root.'/outer');
            $this->fail('START/END drift was accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('score START/END', $e->getMessage());
            $this->assertDirectoryDoesNotExist($this->root.'/score/evaluations/drift');
        }
    }
}
