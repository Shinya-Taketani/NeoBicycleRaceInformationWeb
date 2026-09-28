<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Statistics\AgariC1Input\Builder;
use App\Domain\Keirin\Statistics\AgariC1Input\Contract;
use App\Domain\Keirin\Statistics\AgariC1Input\Index;
use App\Domain\Keirin\Statistics\AgariC1Input\SourceProjector;
use App\Domain\Keirin\Statistics\AgariC1Input\Sources;
use App\Domain\Keirin\Statistics\AgariC1Input\Validator;
use App\Domain\Keirin\Statistics\AgariRaceRelative\Artifacts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\AgariC1InputFixture as F;
use Tests\Support\MemoryLimitedTestProcess;
use Tests\TestCase;

final class AgariC1InputTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/agari-c1-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        DB::shouldReceive('connection')->never();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
        parent::tearDown();
    }

    public function test_command_build_reproduce_preserves_c1_and_leading_zero_identity_without_db_http(): void
    {
        $source = F::bundle($this->root);
        $this->app->instance(Sources::class, $source);
        $this->artisan('keirin:stat35:c1-input plan')->assertSuccessful();
        $args = ['mode' => 'build', '--c1-dir' => $this->root.'/c1', '--history-dir' => $this->root.'/history',
            '--context-dir' => $this->root.'/context', '--output-dir' => $this->root.'/out'];
        $this->artisan('keirin:stat35:c1-input', $args)->assertSuccessful();
        $this->artisan('keirin:stat35:c1-input', array_replace($args, ['mode' => 'reproduce', '--original-dir' => $this->root.'/out', '--output-dir' => $this->root.'/repro']))->assertSuccessful();
        $this->assertSame(Builder::published($this->root.'/out'), Builder::published($this->root.'/repro'));
        foreach (Contract::YEARS as $year) {
            $r = iterator_to_array(Artifacts::lines($this->root.'/out/c1-'.$year.'.jsonl'))[0];
            $this->assertSame(SourceProjector::project(F::race($year, $year), $year, Contract::C1_VERSION), $r);
        }
        $audit = iterator_to_array(Artifacts::lines($this->root.'/out/audit-2024.jsonl'));
        $this->assertSame('000001', $audit[0]['context']['evidence']['external_player_id']);
        $this->assertNull($audit[0]['context']['evidence']['observed_at']);
        $this->assertSame(0.333333333333, $audit[0]['float']);
        $this->assertSame(['NO_OBSERVED_HISTORY'], $audit[1]['reasons']);
        $this->assertSame(4, Files::json($this->root.'/out/summary.json')['totals']['numeric']);
        $this->assertTrue(Files::json($this->root.'/repro/reproduction.json')['identical']);
    }

    public function test_training_outcome_changes_do_not_change_projected_inputs_sidecar_or_semantic_hash(): void
    {
        $a = $this->root.'/a';
        $b = $this->root.'/b';
        mkdir($a);
        mkdir($b);
        $r = F::race(2022);
        $sourceA = F::bundle($a, [$r]);
        foreach ($r['entries'] as &$entry) {
            $entry['labels'] = [0, 0, 0];
            $entry['rank'] = null;
            $entry['status'] = 'DISQUALIFIED';
        }
        unset($entry);
        $sourceB = F::bundle($b, [$r]);
        (new Builder($sourceA))->build($a.'/c1', $a.'/history', $a.'/out', $a.'/context');
        (new Builder($sourceB))->build($b.'/c1', $b.'/history', $b.'/out', $b.'/context');
        foreach (['c1-2022.jsonl', 'stat35-2022.jsonl', 'invariance.json'] as $file) {
            $this->assertSame(Files::identity($a.'/out/'.$file), Files::identity($b.'/out/'.$file));
        }
        $this->assertNotSame(Files::identity($a.'/c1/inputs-2022.jsonl'), Files::identity($b.'/c1/inputs-2022.jsonl'));
    }

    #[DataProvider('badInputs')]
    public function test_strict_projection_and_validation_reject_invalid_inputs(string $kind): void
    {
        $r = F::race();
        match ($kind) {
            'outcome' => $r['entries'][0]['rank'] = 1,
            'unknown' => $r['unexpected'] = 1,
            'missing' => $r['entries'][0] = array_diff_key($r['entries'][0], ['history' => true]),
            'duplicate_entry' => $r['entries'][1]['id'] = $r['entries'][0]['id'],
            'duplicate_bike' => $r['entries'][1]['bike'] = 1,
            'year' => $r['year'] = 2026,
            'null_vs_zero' => $r['entries'][0]['history_status'] = 'NO_HISTORY',
            'nonfinite' => $r['entries'][0]['anchor'] = INF,
            'anchor' => $r['entries'][0]['anchor_status'] = 'AVAILABLE',
            'negative' => $r['entries'][0]['history'][0] = -1,
        };
        $this->expectException(RuntimeException::class);
        Validator::race($r, 2024);
    }

    public static function badInputs(): array
    {
        return array_map(fn ($v) => [$v], ['outcome', 'unknown', 'missing', 'duplicate_entry', 'duplicate_bike', 'year', 'null_vs_zero', 'nonfinite', 'anchor', 'negative']);
    }

    public function test_unknown_training_field_version_and_prediction_outcome_are_rejected(): void
    {
        foreach (['extra', 'version', 'prediction'] as $case) {
            $r = F::race(2022);
            if ($case === 'extra') {
                $r['entries'][0]['payout'] = 1;
            }
            try {
                SourceProjector::project($r, $case === 'prediction' ? 2024 : 2022, $case === 'version' ? 'unknown' : Contract::C1_VERSION);
                $this->fail('Unexpected projection acceptance.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_null_meeting_consumes_slot_zero_is_valid_and_partial_window_uses_only_selected_values(): void
    {
        $rows = [];
        for ($i = 1; $i <= 7; $i++) {
            $rows[] = F::meeting(100 + $i, $i === 7 ? null : ($i === 1 ? '1' : '0'), sprintf('2022-%02d-01', $i), sprintf('2022-%02d-03', $i));
        }
        $source = F::bundle($this->root, [F::race(2022)], $rows);
        $this->build($source);
        $r = iterator_to_array(Artifacts::lines($this->root.'/out/audit-2022.jsonl'))[0];
        $this->assertSame(0.0, $r['float']);
        $this->assertSame(6, $r['window']['observed_meetings']);
        $this->assertSame(5, $r['window']['valid_meetings']);
        $this->assertTrue($r['window']['flags']['missing_meeting_values']);
        $this->assertNotContains(101, array_column($r['window']['meetings'], 'meeting_id'));
    }

    public function test_target_same_day_future_other_identity_and_class_cannot_change_values(): void
    {
        $a = $this->root.'/a';
        $b = $this->root.'/b';
        mkdir($a);
        mkdir($b);
        $races = [F::race(2022)];
        $past = F::meeting();
        $future = F::meeting(102, '1', '2022-09-01', '2022-09-03');
        $same = F::meeting(1, '1', '2022-06-01', '2022-06-03');
        $sameDay = F::meeting(103, '1', '2022-07-30', '2022-08-01');
        $other = F::meeting(104, '1', external: '999999');
        $otherClass = F::meeting(105, '1');
        $otherClass['race_class'] = 'A1_A2';
        $sa = F::bundle($a, $races, [$past]);
        $sb = F::bundle($b, $races, [$past, $future, $same, $sameDay, $other, $otherClass]);
        (new Builder($sa))->build($a.'/c1', $a.'/history', $a.'/out', $a.'/context');
        (new Builder($sb))->build($b.'/c1', $b.'/history', $b.'/out', $b.'/context');
        $this->assertSame(Files::json($a.'/out/invariance.json'), Files::json($b.'/out/invariance.json'));
        $this->assertSame(Files::identity($a.'/out/stat35-2022.jsonl'), Files::identity($b.'/out/stat35-2022.jsonl'));
        $this->assertNotSame(Files::identity($a.'/history/player-meetings.jsonl'), Files::identity($b.'/history/player-meetings.jsonl'));
    }

    public function test_overlap_outside_sixth_slot_blocks_window(): void
    {
        $rows = [];
        for ($i = 1; $i <= 7; $i++) {
            $rows[] = F::meeting(100 + $i, '1', sprintf('2022-%02d-01', $i), sprintf('2022-%02d-03', $i));
        }
        $rows[1]['meeting']['starts_on'] = '2022-01-02';
        $source = F::bundle($this->root, [F::race(2022)], $rows);
        $this->build($source);
        $r = iterator_to_array(Artifacts::lines($this->root.'/out/audit-2022.jsonl'))[0];
        $this->assertNull($r['float']);
        $this->assertSame(['AMBIGUOUS_MEETING_ORDER'], $r['reasons']);
    }

    #[DataProvider('badContexts')]
    public function test_individual_context_failures_remain_in_cohort_with_reasons(string $kind, string $reason): void
    {
        $race = F::race();
        $contexts = array_map(fn ($e) => F::context($race, $e), $race['entries']);
        match ($kind) {
            'external' => $contexts[0]['external_player_id'] = null,
            'class' => $contexts[0]['race_type'] = 'unknown',
            'bike' => $contexts[0]['bike'] = 9,
            'duplicate' => $contexts[] = $contexts[0],
            'same_person' => $contexts[1]['external_player_id'] = $contexts[0]['external_player_id'],
            'meeting' => $contexts[0]['meeting']['starts_on'] = null,
        };
        $source = F::bundle($this->root, [$race], contexts: $contexts);
        $this->build($source);
        $rows = iterator_to_array(Artifacts::lines($this->root.'/out/audit-2024.jsonl'));
        $this->assertCount(5, $rows);
        $this->assertContains($reason, $rows[0]['reasons']);
        $this->assertNull($rows[0]['float']);
    }

    public static function badContexts(): array
    {
        return [['external', 'UNRESOLVED_EXTERNAL_ID'], ['class', 'UNKNOWN_RACE_CLASS'], ['bike', 'CONTEXT_IDENTITY_CONFLICT'],
            ['duplicate', 'DUPLICATE_CONTEXT'], ['same_person', 'CONTEXT_IDENTITY_CONFLICT'], ['meeting', 'INVALID_MEETING_CONTEXT']];
    }

    public function test_missing_evidence_and_empty_input_are_diagnostics_not_success(): void
    {
        $source = F::bundle($this->root);
        $summary = (new Builder($source))->build($this->root.'/c1', $this->root.'/history', $this->root.'/out');
        $this->assertSame('DIAGNOSTIC_ALL_NULL', $summary['status']);
        $this->assertSame(20, $summary['totals']['unmatched']);
        $this->assertFileDoesNotExist($this->root.'/out/COMPLETE.json');
        $this->assertFileExists($this->root.'/out/DIAGNOSTIC.json');
        mkdir($this->root.'/empty');
        $s = F::bundle($this->root.'/empty', []);
        $summary = (new Builder($s))->build($this->root.'/empty/c1', $this->root.'/empty/history', $this->root.'/empty/out');
        $this->assertSame('EMPTY_INPUT', $summary['status']);
        $this->assertSame(0, $summary['totals']['entries']);
    }

    public function test_duplicate_races_and_cross_race_entry_reuse_reject_publication(): void
    {
        $r = F::race();
        $source = F::bundle($this->root, [$r, $r]);
        try {
            $this->build($source);
            $this->fail('Duplicate race accepted.');
        } catch (\PDOException) {
            $this->assertFileDoesNotExist($this->root.'/out/COMPLETE.json');
            $this->assertFileExists($this->root.'/out/FAILED.json');
        }
    }

    public function test_reversed_race_ids_keep_order_and_cross_race_entry_reuse_is_rejected(): void
    {
        $a = F::race(2024, 90);
        $b = F::race(2024, 10);
        $source = F::bundle($this->root, [$a, $b]);
        $this->build($source);
        $this->assertSame([90, 10], array_column(iterator_to_array(Artifacts::lines($this->root.'/out/c1-2024.jsonl')), 'race_id'));
        mkdir($this->root.'/bad');
        $b['entries'][0]['id'] = $a['entries'][0]['id'];
        $source = F::bundle($this->root.'/bad', [$a, $b]);
        $this->expectException(\PDOException::class);
        (new Builder($source))->build($this->root.'/bad/c1', $this->root.'/bad/history', $this->root.'/bad/out');
    }

    public function test_conflicting_evidence_is_distinct_from_identical_duplicate(): void
    {
        $r = F::race();
        $ctx = F::context($r, $r['entries'][0]);
        $conflict = array_replace($ctx, ['external_player_id' => '999999']);
        $source = F::bundle($this->root, [$r], contexts: [$ctx, $conflict]);
        $this->build($source);
        $row = iterator_to_array(Artifacts::lines($this->root.'/out/audit-2024.jsonl'))[0];
        $this->assertContains('DUPLICATE_CONTEXT', $row['reasons']);
        $this->assertContains('CONTEXT_IDENTITY_CONFLICT', $row['reasons']);
        $this->assertNull($row['float']);
    }

    public function test_observed_null_history_and_partial_single_value_are_distinct(): void
    {
        $source = F::bundle($this->root, [F::race(2022)], [F::meeting(value: null)]);
        $this->build($source);
        $rows = iterator_to_array(Artifacts::lines($this->root.'/out/audit-2022.jsonl'));
        $this->assertSame(['NO_VALID_HISTORY'], $rows[0]['reasons']);
        $this->assertSame(1, $rows[0]['window']['observed_meetings']);
        $this->assertSame(['NO_OBSERVED_HISTORY'], $rows[1]['reasons']);
        $this->assertSame(0, $rows[1]['window']['observed_meetings']);
    }

    public function test_half_even_conversion_and_source_field_order_do_not_change_non_result_values(): void
    {
        $this->assertSame(0.000000000002, Index::number(['numerator' => '3', 'denominator' => '2000000000000', 'decimal' => '0.000000000002']));
        $this->assertSame(0.0, Index::number(['numerator' => '1', 'denominator' => '2000000000000', 'decimal' => '0.000000000000']));
        $r = F::race(2022);
        $r = array_reverse($r, true);
        foreach ($r['entries'] as &$entry) {
            $entry = array_reverse($entry, true);
        }
        unset($entry);
        $p = SourceProjector::project($r, 2022, Contract::C1_VERSION);
        foreach ($r['entries'] as &$entry) {
            unset($entry['rank'], $entry['status'], $entry['labels']);
        }
        unset($entry);
        $this->assertSame($r, $p);
    }

    public function test_corruption_and_source_overlap_are_not_missing_data(): void
    {
        $source = F::bundle($this->root);
        try {
            (new Builder($source))->build($this->root.'/c1', $this->root.'/history', $this->root.'/c1/forbidden');
            $this->fail('Source child accepted.');
        } catch (RuntimeException) {
            $this->assertDirectoryDoesNotExist($this->root.'/c1/forbidden');
        }
        $path = $this->root.'/c1/inputs-2024.jsonl';
        $bytes = file_get_contents($path);
        file_put_contents($path, str_replace('80.0', '81.0', $bytes));
        $this->expectException(RuntimeException::class);
        $this->build($source);
    }

    public function test_reproduction_rejects_tampered_published_body_and_existing_directory(): void
    {
        $source = F::bundle($this->root);
        $this->build($source);
        try {
            $this->build($source);
            $this->fail('Overwrite accepted.');
        } catch (RuntimeException) {
            $this->assertFileExists($this->root.'/out/COMPLETE.json');
        }
        file_put_contents($this->root.'/out/stat35-2024.jsonl', "{}\n");
        $this->expectException(RuntimeException::class);
        (new Builder($source))->build($this->root.'/c1', $this->root.'/history', $this->root.'/repro', $this->root.'/context', $this->root.'/out');
    }

    #[DataProvider('badNumbers')]
    public function test_exact_numeric_validation(array $value): void
    {
        $this->expectException(RuntimeException::class);
        Index::number($value);
    }

    public static function badNumbers(): array
    {
        return [[['numerator' => '1', 'denominator' => '0', 'decimal' => '1.000000000000']],
            [['numerator' => '2', 'denominator' => '1', 'decimal' => '2.000000000000']],
            [['numerator' => '1', 'denominator' => '3', 'decimal' => '0.333333333334']],
            [['numerator' => '-1', 'denominator' => '2', 'decimal' => '-0.500000000000']]];
    }

    public function test_streamed_input_generation_in_independent_128m_process(): void
    {
        if (MemoryLimitedTestProcess::delegate(self::class.'::'.__FUNCTION__)) {
            return;
        }
        $source = F::bundle($this->root, []);
        unlink($this->root.'/c1/inputs-2022.jsonl');
        $rows = function () {
            for ($i = 1; $i <= 11000; $i++) {
                $r = F::race(2022, $i);
                foreach ($r['entries'] as &$e) {
                    $e['signals'][0] = str_repeat('x', 2000);
                }
                unset($e);
                yield Files::canonical($r)."\n";
            }
        };
        $seal = Artifacts::write($this->root.'/c1', 'inputs-2022.jsonl', $rows());
        $m = Files::json($this->root.'/c1/manifest.json');
        $m['manifests'][2022]['inputs'] = ['rows' => 11000, ...$seal];
        file_put_contents($this->root.'/c1/manifest.json', Files::canonical($m)."\n");
        $source = new Sources(['c1' => Files::identity($this->root.'/c1/manifest.json'), 'history' => Files::identity($this->root.'/history/manifest.json')]);
        $this->assertGreaterThan(100 * 1024 * 1024, $seal['bytes']);
        $summary = (new Builder($source))->build($this->root.'/c1', $this->root.'/history', $this->root.'/out');
        $this->assertSame(11000, $summary['totals']['races']);
        $this->assertSame(55000, $summary['totals']['entries']);
        $this->assertSame('DIAGNOSTIC_ALL_NULL', $summary['status']);
        $this->assertLessThan(128 * 1024 * 1024, memory_get_peak_usage(true));
        MemoryLimitedTestProcess::record(self::class.'::'.__FUNCTION__, memory_get_peak_usage(true));
    }

    private function build(Sources $source): array
    {
        return (new Builder($source))->build($this->root.'/c1', $this->root.'/history', $this->root.'/out', $this->root.'/context');
    }
}
