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

    public function test_default_and_container_sources_use_only_the_explicitly_reviewed_candidate_pin(): void
    {
        $expected = [['bytes' => 248, 'sha256' => '7800bc94ed7a1d89e6bf1aee5a3bbd22d3d979dea01d511d4133214a08981268']];
        $this->assertSame($expected, Sources::REVIEWED_CONTEXT_PINS);
        $property = new \ReflectionProperty(Sources::class, 'reviewedContextPins');
        $this->assertSame($expected, $property->getValue(new Sources));
        $this->assertSame($expected, $property->getValue($this->app->make(Sources::class)));
        $builderSource = (new \ReflectionProperty(Builder::class, 'sources'))->getValue($this->app->make(Builder::class));
        $this->assertSame($expected, $property->getValue($builderSource));
        $this->assertSame([], $property->getValue(new Sources(reviewedContextPins: [])));
    }

    public function test_explicit_empty_allowlist_rejects_self_sealed_context_before_publication(): void
    {
        F::bundle($this->root);
        $source = new Sources(['c1' => Files::identity($this->root.'/c1/manifest.json'),
            'history' => Files::identity($this->root.'/history/manifest.json')], []);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unverified target context evidence');
        try {
            $this->build($source);
        } finally {
            $this->assertDirectoryDoesNotExist($this->root.'/out');
        }
    }

    #[DataProvider('trustedContextCorruptions')]
    public function test_trusted_synthetic_pin_still_requires_body_and_completion_integrity(string $file): void
    {
        $source = F::bundle($this->root);
        $path = $this->root.'/context/'.$file;
        $bytes = file_get_contents($path);
        $changed = $file === 'entry-context.jsonl'
            ? str_replace('000001', '999999', $bytes)
            : str_replace('"bytes":', '"bytes":9', $bytes);
        $this->assertNotSame($bytes, $changed);
        file_put_contents($path, $changed);
        $this->expectException(RuntimeException::class);
        try {
            $this->build($source);
        } finally {
            $this->assertDirectoryDoesNotExist($this->root.'/out');
        }
    }

    public static function trustedContextCorruptions(): array
    {
        return [['entry-context.jsonl'], ['COMPLETE.json']];
    }

    public function test_partial_context_reproduces_without_conflating_connection_numeric_zero_and_null(): void
    {
        $race = F::race();
        $contexts = array_map(fn ($entry) => F::context($race, $entry), array_slice($race['entries'], 0, 4));
        $source = F::bundle($this->root, [$race], [F::meeting(),
            F::meeting(102, null, external: '000002'), F::meeting(103, '0', external: '000003')], $contexts);
        $summary = $this->build($source);
        $this->assertSame('INPUTS_PREPARED', $summary['status']);
        $this->assertSame(5, $summary['totals']['entries']);
        $this->assertSame(4, $summary['totals']['connected']);
        $this->assertSame(1, $summary['totals']['unmatched']);
        $this->assertSame(2, $summary['totals']['numeric']);
        $this->assertSame(3, $summary['totals']['null']);
        $rows = iterator_to_array(Artifacts::lines($this->root.'/out/audit-2024.jsonl'));
        $this->assertSame([11, 12, 13, 14, 15], array_column($rows, 'entry_id'));
        $this->assertSame([0.333333333333, null, 0.0, null, null], array_column($rows, 'float'));
        $this->assertSame([[], ['NO_VALID_HISTORY'], [], ['NO_OBSERVED_HISTORY'], ['MISSING_IDENTITY_CONTEXT_EVIDENCE']], array_column($rows, 'reasons'));
        $this->assertSame(['MISSING_IDENTITY_CONTEXT_EVIDENCE' => 1, 'NO_OBSERVED_HISTORY' => 1, 'NO_VALID_HISTORY' => 1],
            $summary['years'][2024]['null_reasons']);
        $this->assertSame(SourceProjector::project($race, 2024, Contract::C1_VERSION),
            iterator_to_array(Artifacts::lines($this->root.'/out/c1-2024.jsonl'))[0]);
        $reproduced = (new Builder($source))->build($this->root.'/c1', $this->root.'/history', $this->root.'/repro',
            $this->root.'/context', $this->root.'/out');
        $this->assertSame($summary, $reproduced);
        $published = Builder::published($this->root.'/out');
        $this->assertCount(14, $published['files']);
        $this->assertSame($published, Builder::published($this->root.'/repro'));
        $this->assertSame(Files::identity($this->root.'/out/manifest.json'), Files::identity($this->root.'/repro/manifest.json'));
        $this->assertTrue(Files::json($this->root.'/repro/reproduction.json')['identical']);
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
            'meeting_type' => $contexts[0]['meeting']['starts_on'] = [],
        };
        $source = F::bundle($this->root, [$race], $this->allPlayerMeetings(), $contexts);
        $this->build($source);
        $rows = iterator_to_array(Artifacts::lines($this->root.'/out/audit-2024.jsonl'));
        $this->assertCount(5, $rows);
        $this->assertContains($reason, $rows[0]['reasons']);
        $this->assertNull($rows[0]['float']);
        foreach (array_slice($rows, $kind === 'same_person' ? 2 : 1) as $healthy) {
            $this->assertSame([], $healthy['reasons']);
            $this->assertSame(0.333333333333, $healthy['float']);
        }
    }

    #[DataProvider('targetMismatches')]
    public function test_context_must_match_fixed_c1_target(string $field): void
    {
        $race = F::race();
        $contexts = array_map(fn ($e) => F::context($race, $e), $race['entries']);
        foreach ($contexts as &$context) {
            match ($field) {
                'race_id' => $context['race_id'] = 99,
                'entry_id' => $context['entry_id'] += 100,
                'bike' => $context['bike'] = 9,
                'race_date' => $context['race_date'] = '2024-08-02',
                'meeting_id' => $context['meeting']['meeting_id'] = 99,
                'meeting_start' => $context['meeting']['starts_on'] = '2024-07-31',
                'meeting_end' => $context['meeting']['ends_on'] = '2024-08-04',
            };
        }
        unset($context);
        $summary = $this->build(F::bundle($this->root, [$race], $this->allPlayerMeetings(), $contexts));
        $this->assertSame(0, $summary['totals']['connected']);
        $this->assertSame(0, $summary['totals']['numeric']);
        foreach (Artifacts::lines($this->root.'/out/audit-2024.jsonl') as $row) {
            $this->assertNull($row['float']);
            $this->assertNotEmpty($row['reasons']);
            $this->assertSame([], $row['window']['meetings']);
        }
    }

    public static function targetMismatches(): array
    {
        return array_map(fn ($v) => [$v], ['race_id', 'entry_id', 'bike', 'race_date', 'meeting_id', 'meeting_start', 'meeting_end']);
    }

    public function test_self_sealed_context_without_reviewed_evidence_is_rejected_before_publication(): void
    {
        F::bundle($this->root);
        $source = new Sources(['c1' => Files::identity($this->root.'/c1/manifest.json'),
            'history' => Files::identity($this->root.'/history/manifest.json')]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unverified target context evidence');
        try {
            $this->build($source);
        } finally {
            $this->assertDirectoryDoesNotExist($this->root.'/out');
        }
    }

    public function test_meeting_key_order_does_not_change_values_or_decisions(): void
    {
        $race = F::race();
        $contexts = array_map(fn ($e) => F::context($race, $e), $race['entries']);
        $source = F::bundle($this->root, [$race], $this->allPlayerMeetings(), $contexts);
        $summary = $this->build($source);
        mkdir($this->root.'/reordered');
        $contexts[0]['meeting'] = array_reverse($contexts[0]['meeting'], true);
        $other = $this->root.'/reordered';
        $s = F::bundle($other, [$race], $this->allPlayerMeetings(), $contexts);
        $this->assertSame($summary, (new Builder($s))->build($other.'/c1', $other.'/history', $other.'/out', $other.'/context'));
        $this->assertSame(Files::identity($this->root.'/out/stat35-2024.jsonl'), Files::identity($other.'/out/stat35-2024.jsonl'));
        $this->assertSame(Files::json($this->root.'/out/invariance.json'), Files::json($other.'/out/invariance.json'));
        $a = iterator_to_array(Artifacts::lines($this->root.'/out/audit-2024.jsonl'));
        $b = iterator_to_array(Artifacts::lines($other.'/out/audit-2024.jsonl'));
        $this->assertSame(array_column($a, 'reasons'), array_column($b, 'reasons'));
    }

    public function test_wrong_affiliation_cannot_create_another_entries_identity_conflict(): void
    {
        $race = F::race();
        $contexts = array_map(fn ($e) => F::context($race, $e), $race['entries']);
        $contexts[0]['race_id'] = 999;
        $contexts[0]['external_player_id'] = $contexts[1]['external_player_id'];
        $this->build(F::bundle($this->root, [$race], $this->allPlayerMeetings(), $contexts));
        $rows = iterator_to_array(Artifacts::lines($this->root.'/out/audit-2024.jsonl'));
        $this->assertContains('CONTEXT_IDENTITY_CONFLICT', $rows[0]['reasons']);
        foreach (array_slice($rows, 1) as $healthy) {
            $this->assertSame([], $healthy['reasons']);
            $this->assertSame(0.333333333333, $healthy['float']);
        }
    }

    #[DataProvider('conflictKinds')]
    public function test_conflict_counts_match_yearly_and_total_audit_union(string $kind): void
    {
        $races = [F::race(2024, 1), F::race(2025, 2)];
        $contexts = [];
        foreach ($races as $race) {
            $rows = array_map(fn ($e) => F::context($race, $e), $race['entries']);
            if ($kind !== 'identity') {
                $rows[0]['race_type'] = 'A級予選';
            }
            if ($kind !== 'race') {
                $rows[1]['external_player_id'] = $rows[0]['external_player_id'];
            }
            array_push($contexts, ...$rows);
        }
        $summary = $this->build(F::bundle($this->root, $races, $this->allPlayerMeetings(), $contexts));
        $expected = $kind === 'identity' ? 2 : 5;
        foreach ([2024, 2025] as $year) {
            $rows = iterator_to_array(Artifacts::lines($this->root.'/out/audit-'.$year.'.jsonl'));
            $conflicts = array_filter($rows, fn ($r) => array_intersect($r['reasons'], ['CONTEXT_IDENTITY_CONFLICT', 'CONFLICTING_RACE_CONTEXT']) !== []);
            $this->assertCount($expected, $conflicts);
            $this->assertSame(count($conflicts), $summary['years'][$year]['conflicting_context']);
            foreach ($conflicts as $row) {
                $this->assertNull($row['float']);
            }
            $this->assertSame($kind === 'identity' ? 0 : 5, $summary['years'][$year]['null_reasons']['CONFLICTING_RACE_CONTEXT'] ?? 0);
            $this->assertSame($kind === 'race' ? 0 : 2, $summary['years'][$year]['null_reasons']['CONTEXT_IDENTITY_CONFLICT'] ?? 0);
        }
        $this->assertSame(2 * $expected, $summary['totals']['conflicting_context']);
    }

    public static function conflictKinds(): array
    {
        return [['identity'], ['race'], ['both']];
    }

    #[DataProvider('meetingFields')]
    public function test_single_target_mismatch_does_not_poison_valid_peers(string $field): void
    {
        $race = F::race();
        $contexts = array_map(fn ($e) => F::context($race, $e), $race['entries']);
        if ($field === 'race_date') {
            $contexts[0][$field] = '2024-08-02';
        } else {
            $contexts[0]['meeting'][$field] = match ($field) {
                'meeting_id' => 999,
                'starts_on' => '2024-07-31',
                'ends_on' => '2024-08-04',
            };
        }
        $contexts[0]['external_player_id'] = $contexts[1]['external_player_id'];
        $contexts[0]['race_type'] = 'A級予選';
        $summary = $this->build(F::bundle($this->root, [$race], $this->allPlayerMeetings(), $contexts));
        $this->assertSame(4, $summary['totals']['numeric']);
        $rows = iterator_to_array(Artifacts::lines($this->root.'/out/audit-2024.jsonl'));
        $this->assertContains('CONTEXT_TARGET_MISMATCH', $rows[0]['reasons']);
        $this->assertNull($rows[0]['float']);
        $this->assertSame([], $rows[0]['window']['meetings']);
        foreach (array_slice($rows, 1) as $healthy) {
            $this->assertSame([], $healthy['reasons']);
            $this->assertSame(0.333333333333, $healthy['float']);
        }
    }

    public static function meetingFields(): array
    {
        return [['race_date'], ['meeting_id'], ['starts_on'], ['ends_on']];
    }

    #[DataProvider('meetingFields')]
    public function test_true_meeting_conflict_between_individually_verified_targets_blocks_race(string $field): void
    {
        $race = F::race();
        $contexts = array_map(fn ($e) => F::context($race, $e), $race['entries']);
        $target = F::target($race, $race['entries'][0]);
        if ($field === 'race_date') {
            $contexts[0][$field] = $target[$field] = '2024-08-02';
        } else {
            $targetField = ['meeting_id' => 'meeting_id', 'starts_on' => 'meeting_start', 'ends_on' => 'meeting_end'][$field];
            $contexts[0]['meeting'][$field] = $target[$targetField] = match ($field) {
                'meeting_id' => 999,
                'starts_on' => '2024-07-31',
                'ends_on' => '2024-08-04',
            };
        }
        $source = F::bundle($this->root, [$race], $this->allPlayerMeetings(), $contexts, [11 => $target]);
        $summary = $this->build($source);
        $this->assertSame(5, $summary['totals']['conflicting_context']);
        foreach (Artifacts::lines($this->root.'/out/audit-2024.jsonl') as $row) {
            $this->assertTrue($row['context']['validity']['meeting']);
            $this->assertSame(['CONFLICTING_RACE_CONTEXT'], $row['reasons']);
            $this->assertNull($row['float']);
        }
    }

    public function test_unresolved_person_does_not_hide_verified_class_conflict(): void
    {
        $race = F::race();
        $contexts = array_map(fn ($e) => F::context($race, $e), $race['entries']);
        $contexts[0]['external_player_id'] = null;
        $contexts[0]['race_type'] = 'A級予選';
        $summary = $this->build(F::bundle($this->root, [$race], $this->allPlayerMeetings(), $contexts));
        $this->assertSame(5, $summary['totals']['conflicting_context']);
        $this->assertSame(0, $summary['totals']['numeric']);
        $rows = iterator_to_array(Artifacts::lines($this->root.'/out/audit-2024.jsonl'));
        $this->assertFalse($rows[0]['context']['validity']['identity']);
        $this->assertTrue($rows[0]['context']['validity']['class']);
        $this->assertContains('CONFLICTING_RACE_CONTEXT', $rows[1]['reasons']);
    }

    public function test_reordered_identical_duplicate_is_not_an_identity_conflict(): void
    {
        $race = F::race();
        $contexts = array_map(fn ($e) => F::context($race, $e), $race['entries']);
        $copy = array_reverse($contexts[0], true);
        $copy['meeting'] = array_reverse($copy['meeting'], true);
        $contexts[] = $copy;
        $summary = $this->build(F::bundle($this->root, [$race], $this->allPlayerMeetings(), $contexts));
        $this->assertSame(1, $summary['totals']['duplicate_context']);
        $this->assertSame(0, $summary['totals']['conflicting_context']);
        $this->assertSame(4, $summary['totals']['numeric']);
        $rows = iterator_to_array(Artifacts::lines($this->root.'/out/audit-2024.jsonl'));
        $this->assertSame(['DUPLICATE_CONTEXT'], $rows[0]['reasons']);
    }

    #[DataProvider('evidenceFields')]
    public function test_resealing_identity_and_class_changes_cannot_bypass_reviewed_pin(string $field, string $value): void
    {
        $source = F::bundle($this->root);
        $file = $this->root.'/context/entry-context.jsonl';
        $rows = iterator_to_array(Artifacts::lines($file));
        $rows[0][$field] = $value;
        file_put_contents($file, implode('', array_map(fn ($r) => Files::canonical($r)."\n", $rows)));
        $manifest = Files::json($this->root.'/context/manifest.json');
        $manifest['files']['entry-context.jsonl'] = Files::identity($file);
        file_put_contents($this->root.'/context/manifest.json', Files::canonical($manifest)."\n");
        file_put_contents($this->root.'/context/COMPLETE.json', Files::canonical(Files::identity($this->root.'/context/manifest.json'))."\n");
        $this->expectExceptionMessage('Unverified target context evidence');
        try {
            $this->build($source);
        } finally {
            $this->assertDirectoryDoesNotExist($this->root.'/out');
        }
    }

    public static function evidenceFields(): array
    {
        return [['external_player_id', '999999'], ['race_type', 'A級予選'], ['source_record_id', 'self-declared-record']];
    }

    public function test_fixed_target_file_tamper_is_rejected_at_open_and_end(): void
    {
        $source = F::bundle($this->root);
        $opened = $source->open($this->root.'/c1', $this->root.'/history', $this->root.'/context');
        $path = $this->root.'/c1/history-2024.jsonl';
        file_put_contents($path, str_replace('2024-08-01', '2024-08-02', file_get_contents($path)));
        foreach (['open', 'end'] as $phase) {
            try {
                $phase === 'open' ? $this->build($source) : Sources::verify($opened);
                $this->fail('Fixed target drift accepted at '.$phase);
            } catch (RuntimeException) {
                $this->assertDirectoryDoesNotExist($this->root.'/out');
            }
        }
    }

    #[DataProvider('badTargetSources')]
    public function test_fixed_target_source_must_cover_c1_exactly(string $case): void
    {
        F::bundle($this->root, [F::race()]);
        $file = $this->root.'/c1/history-2024.jsonl';
        $rows = iterator_to_array(Artifacts::lines($file));
        match ($case) {
            'race' => $rows[0]['target']['race_id'] = 999,
            'entry' => $rows[0]['target']['entry_id'] = 999,
            'bike' => $rows[0]['target']['bike'] = 9,
            'year' => $rows[0]['target']['race_date'] = '2023-08-01',
            'missing_field' => $rows[0]['target'] = array_diff_key($rows[0]['target'], ['meeting_end' => true]),
            'extra' => $rows[] = array_replace($rows[0], ['target' => array_replace($rows[0]['target'], ['entry_id' => 999])]),
            'duplicate' => $rows[] = $rows[0],
            'missing' => array_pop($rows),
            'count' => null,
        };
        file_put_contents($file, implode('', array_map(fn ($r) => Files::canonical($r)."\n", $rows)));
        $manifest = Files::json($this->root.'/c1/manifest.json');
        $manifest['manifests'][2024]['history'] = ['rows' => count($rows) + (int) ($case === 'count'), ...Files::identity($file)];
        file_put_contents($this->root.'/c1/manifest.json', Files::canonical($manifest)."\n");
        $this->expectException(RuntimeException::class);
        try {
            $this->build(F::sources($this->root));
        } finally {
            $this->assertFileDoesNotExist($this->root.'/out/COMPLETE.json');
            $this->assertFileDoesNotExist($this->root.'/out/DIAGNOSTIC.json');
            $this->assertFileExists($this->root.'/out/FAILED.json');
        }
    }

    public static function badTargetSources(): array
    {
        return array_map(fn ($v) => [$v], ['race', 'entry', 'bike', 'year', 'missing_field', 'extra', 'duplicate', 'missing', 'count']);
    }

    public function test_non_target_history_values_are_not_used_for_connection_or_extra_input(): void
    {
        $source = F::bundle($this->root);
        $this->build($source);
        foreach (Contract::YEARS as $year) {
            $file = $this->root.'/c1/history-'.$year.'.jsonl';
            $rows = iterator_to_array(Artifacts::lines($file));
            foreach ($rows as &$row) {
                $row['aggregate'] = ['values' => [null, null, null, null], 'status' => 'UNTRUSTED', 'rank' => 99];
                $row['cache_key'] = 'not-an-identity-source';
                $row['source_sha256'] = 'ignored';
                $row['target']['player_id'] = 999999;
                $row['target']['input_as_of'] = null;
            }
            unset($row);
            file_put_contents($file, implode('', array_map(fn ($r) => Files::canonical($r)."\n", $rows)));
        }
        $manifest = Files::json($this->root.'/c1/manifest.json');
        foreach (Contract::YEARS as $year) {
            $manifest['manifests'][$year]['history'] = ['rows' => 5, ...Files::identity($this->root.'/c1/history-'.$year.'.jsonl')];
        }
        file_put_contents($this->root.'/c1/manifest.json', Files::canonical($manifest)."\n");
        (new Builder(F::sources($this->root)))->build($this->root.'/c1', $this->root.'/history', $this->root.'/changed', $this->root.'/context');
        foreach (Contract::YEARS as $year) {
            $this->assertSame(Files::identity($this->root.'/out/stat35-'.$year.'.jsonl'), Files::identity($this->root.'/changed/stat35-'.$year.'.jsonl'));
            $a = iterator_to_array(Artifacts::lines($this->root.'/out/audit-'.$year.'.jsonl'));
            $b = iterator_to_array(Artifacts::lines($this->root.'/changed/audit-'.$year.'.jsonl'));
            $this->assertSame(array_column($a, 'context'), array_column($b, 'context'));
            $this->assertSame(['race_id', 'entry_id', 'bike', 'race_date', 'meeting_id', 'meeting_start', 'meeting_end'], array_keys($b[0]['context']['target']));
        }
        $this->assertSame(Files::json($this->root.'/out/invariance.json'), Files::json($this->root.'/changed/invariance.json'));
    }

    public static function badContexts(): array
    {
        return [['external', 'UNRESOLVED_EXTERNAL_ID'], ['class', 'UNKNOWN_RACE_CLASS'], ['bike', 'CONTEXT_IDENTITY_CONFLICT'],
            ['duplicate', 'DUPLICATE_CONTEXT'], ['same_person', 'CONTEXT_IDENTITY_CONFLICT'], ['meeting', 'INVALID_MEETING_CONTEXT'],
            ['meeting_type', 'INVALID_MEETING_CONTEXT']];
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
        unlink($this->root.'/c1/history-2022.jsonl');
        $targets = function () {
            for ($i = 1; $i <= 11000; $i++) {
                $race = F::race(2022, $i);
                foreach ($race['entries'] as $entry) {
                    yield Files::canonical(['target' => F::target($race, $entry)])."\n";
                }
            }
        };
        $m['manifests'][2022]['history'] = ['rows' => 55000, ...Artifacts::write($this->root.'/c1', 'history-2022.jsonl', $targets())];
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

    private function allPlayerMeetings(): array
    {
        return array_map(fn ($i) => F::meeting(external: sprintf('%06d', $i)), range(1, 5));
    }
}
