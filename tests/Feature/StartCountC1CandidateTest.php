<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Statistics\AgariRaceRelative\Artifacts;
use App\Domain\Keirin\Statistics\StartCountC1Candidate\Builder;
use App\Domain\Keirin\Statistics\StartCountC1Candidate\Bundle;
use App\Domain\Keirin\Statistics\StartCountC1Candidate\Contract;
use App\Domain\Keirin\Statistics\StartCountC1Candidate\Sources;
use App\Domain\Keirin\Statistics\StartCountC1Candidate\Timing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\MemoryLimitedTestProcess;
use Tests\Support\StartCountC1Fixture as F;
use Tests\TestCase;

class StartCountC1CandidateTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/s-c1-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        DB::swap(new class
        {
            public function __call(string $name, array $arguments): never
            {
                throw new RuntimeException('DB forbidden');
            }
        });
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_all_versions_cohort_order_zero_provenance_reproduction_and_training_block(): void
    {
        $source = F::bundle($this->root, races: 2);
        $b = new Builder($source);
        $a = $b->build($this->root.'/build');
        $c = $b->build($this->root.'/reproduce', $this->root.'/build');
        $this->assertSame($a['manifest'], $c['manifest']);
        $this->assertSame(40, $a['coverage']['total']['numeric_candidates']);
        $this->assertSame(80, $a['coverage']['total']['linked_observations']);
        $csv = file_get_contents($this->root.'/build/coverage.csv');
        foreach (Contract::YEARS as $year) {
            $this->assertStringContainsString($year.',entries,10', $csv);
        }
        $this->assertStringContainsString('TOTAL,entries,40', $csv);
        $rows = iterator_to_array(Artifacts::lines($this->root.'/build/candidates-2024.jsonl'));
        $this->assertSame([300002, 300001], [$rows[0]['race_id'], $rows[5]['race_id']]);
        $this->assertSame('000001', $rows[0]['external_player_id']);
        $this->assertSame(0, $rows[0]['candidate_displayed_start_count']);
        foreach ($rows as $row) {
            $this->assertSame(Contract::restrictions(), array_intersect_key($row, Contract::restrictions()));
            $this->assertNull($row['statistical_as_of']);
            $this->assertArrayNotHasKey('signals', $row);
            $this->assertArrayNotHasKey('rank', $row);
        }
        Http::assertNothingSent();
        foreach (['training', 'prediction', 'evaluation'] as $purpose) {
            try {
                Bundle::open($this->root.'/build', $purpose);
                $this->fail('Candidate used as '.$purpose);
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('BLOCKED_INPUT_SEMANTICS', $e->getMessage());
            }
        }
        $this->expectExceptionMessage('BLOCKED_INPUT_SEMANTICS');
        Bundle::openForTraining($this->root.'/build');
    }

    public function test_numeric_agreement_ignores_raw_type_difference_but_retains_both_sources(): void
    {
        $source = F::bundle($this->root, function (&$r, $kind) {
            if ($kind === 'snapshot' && $r['bike_number'] === 2 && $r['fetch_log_id'] % 2 === 0) {
                F::value($r, '003');
            }
        });
        $result = (new Builder($source))->build($this->root.'/build');
        $this->assertSame(20, $result['coverage']['total']['numeric_candidates']);
        $this->assertSame(4, $result['coverage']['total']['raw_type_or_representation_versions']);
        $this->assertSame(0, $result['coverage']['total']['value_conflicts']);
        $rows = array_values(array_filter(iterator_to_array(Artifacts::lines($this->root.'/build/source-observation-links.jsonl')),
            fn ($r) => $r['kind'] === 'C1_OBSERVATION'));
        $this->assertSame([3, '003'], [$rows[1]['field']['raw'], $rows[6]['field']['raw']]);
    }

    public static function heldValues(): array
    {
        return [['different', 'VALUE_CONFLICT'], [null, 'INCOMPLETE_OR_CONTRADICTORY_VERSIONS'],
            ['', 'INCOMPLETE_OR_CONTRADICTORY_VERSIONS'], ['－', 'INCOMPLETE_OR_CONTRADICTORY_VERSIONS'],
            ['missing', 'INCOMPLETE_OR_CONTRADICTORY_VERSIONS'], ['invalid', 'INCOMPLETE_OR_CONTRADICTORY_VERSIONS']];
    }

    #[DataProvider('heldValues')]
    public function test_mixed_version_faults_hold_only_affected_entry(mixed $value, string $state): void
    {
        $source = F::bundle($this->root, function (&$r, $kind) use ($value) {
            if ($kind === 'snapshot' && $r['bike_number'] === 1 && $r['fetch_log_id'] % 2 === 0) {
                F::value($r, $value === 'different' ? 4 : ($value === 'invalid' ? -1 : $value), $value === 'missing');
            }
        });
        $result = (new Builder($source))->build($this->root.'/build');
        $this->assertSame(16, $result['coverage']['total']['numeric_candidates']);
        $rows = iterator_to_array(Artifacts::lines($this->root.'/build/candidates-2024.jsonl'));
        $this->assertNull($rows[0]['candidate_displayed_start_count']);
        $this->assertSame($state, $rows[0]['state']);
        $this->assertSame(3, $rows[1]['candidate_displayed_start_count']);
    }

    public function test_successful_version_missing_entry_holds_but_failed_fetch_does_not(): void
    {
        $source = F::bundle($this->root, function (&$r, $kind) {
            if ($kind === 'snapshot' && $r['bike_number'] === 1 && $r['fetch_log_id'] % 3 === 2) {
                return false;
            }
            if ($kind === 'fetch' && $r['fetch_log_id'] % 3 === 0) {
                $r['status'] = 'FETCH_NOT_SUCCESSFUL';
            }
        }, versions: 3);
        $result = (new Builder($source))->build($this->root.'/build');
        $this->assertSame(4, $result['coverage']['total']['fetch_failures']);
        $this->assertSame(16, $result['coverage']['total']['numeric_candidates']);
        $this->assertSame(20, $result['coverage']['total']['entries_with_failed_fetch_history']);
        $audit = iterator_to_array(Artifacts::lines($this->root.'/build/mapping-audit-2024.jsonl'));
        $this->assertContains('SUCCESSFUL_VERSION_MISSING_ENTRY', $audit[0]['reasons']);
        $this->assertSame(1, $audit[1]['failed_fetch_versions_for_race']);
    }

    public function test_unknown_class_does_not_override_verified_identity_and_no_observation_remains(): void
    {
        $source = F::bundle($this->root, function (&$r, $kind) {
            if ($kind === 'mapping') {
                $r['candidate'] = false;
                $r['race_class'] = 'UNKNOWN';
                $r['checks']['class_known'] = false;
                $r['reasons'] = ['UNKNOWN_RACE_CLASS'];
            }
        });
        $result = (new Builder($source))->build($this->root.'/build');
        $this->assertSame(20, $result['coverage']['total']['unknown_race_class_identity_connected']);
        $this->assertSame(20, $result['coverage']['total']['numeric_candidates']);
        $empty = $this->root.'/empty';
        mkdir($empty);
        $source = F::bundle($empty, versions: 0);
        $result = (new Builder($source))->build($empty.'/build');
        $this->assertSame(20, $result['coverage']['total']['null_candidates']);
        $this->assertSame(20, $result['coverage']['total']['reasons']['NO_MATCHING_SNAPSHOT']);
    }

    public static function identityFaults(): array
    {
        return [['entry'], ['external'], ['date'], ['mapping_internal'], ['mapping_meeting'], ['mapping_bike'], ['claimed_other_race']];
    }

    #[DataProvider('identityFaults')]
    public function test_identity_faults_are_not_silently_outside_or_repaired(string $fault): void
    {
        $source = F::bundle($this->root, function (&$r, $kind) use ($fault) {
            if ($kind === 'snapshot' && $r['bike_number'] === 1) {
                if ($fault === 'entry') {
                    $r['entry_id'] += 100;
                } elseif ($fault === 'external') {
                    $r['observed_external_id'] = '999999';
                } elseif ($fault === 'date') {
                    $r['identity_issues'] = ['RACE_IDENTITY_MISMATCH'];
                    $r['displayed_start_count'] = null;
                } elseif ($fault === 'claimed_other_race') {
                    $r['bike_number'] = 9;
                }
            }
            if ($kind === 'mapping' && $r['target']['bike'] === 1) {
                if ($fault === 'mapping_internal') {
                    $r['player_id_status'] = 'PLAYER_ID_MISMATCH';
                } elseif ($fault === 'mapping_meeting') {
                    $r['context']['meeting']['starts_on'] = '2024-01-01';
                } elseif ($fault === 'mapping_bike') {
                    $r['context']['bike'] = 8;
                }
            }
        });
        $result = (new Builder($source))->build($this->root.'/build');
        $this->assertSame(4, $result['coverage']['total']['null_candidates']);
        $this->assertSame(0, $result['coverage']['total']['outside_c1_observations']);
        $rows = iterator_to_array(Artifacts::lines($this->root.'/build/candidates-2024.jsonl'));
        $this->assertNull($rows[0]['candidate_displayed_start_count']);
        $this->assertSame(3, $rows[1]['candidate_displayed_start_count']);
    }

    public function test_outside_cohort_observations_are_accounted_without_enlarging_c1(): void
    {
        $source = F::bundle($this->root, function (&$r, $kind) {
            if ($kind === 'snapshot' && $r['bike_number'] === 5) {
                $r['bike_number'] = 9;
                $r['entry_id'] = 99999999;
            }
        });
        $result = (new Builder($source))->build($this->root.'/build');
        $this->assertSame(20, $result['coverage']['total']['entries']);
        $this->assertSame(8, $result['coverage']['total']['outside_c1_observations']);
        $this->assertSame(4, $result['coverage']['total']['outside_c1_unique_entries']);
        $this->assertSame(4, $result['coverage']['total']['null_candidates']);
    }

    public static function timeCases(): array
    {
        return [['2024-07-31 23:59:59+09', 'BEFORE_TARGET_DATE'], ['2024-08-01T00:00:00+09:00', 'SAME_TARGET_DATE_NO_START_TIME'],
            ['2024-07-31T15:00:00Z', 'SAME_TARGET_DATE_NO_START_TIME'], ['2024-08-02 01:00:00+09', 'AFTER_TARGET_DATE'],
            ['2024-08-01 01:00:00', 'UNKNOWN_TIME_OR_TIMEZONE'], [null, 'UNKNOWN_TIME_OR_TIMEZONE'],
            ['2024-02-30 01:00:00+09', 'INVALID_FETCH_TIME'], ['2024-08-01 25:00:00+09', 'INVALID_FETCH_TIME'],
            ['2024-08-01 00:00:00+15', 'INVALID_FETCH_TIME']];
    }

    #[DataProvider('timeCases')]
    public function test_explicit_timezone_no_datetime_repair(mixed $value, string $expected): void
    {
        $this->assertSame($expected, Timing::relation($value, '2024-08-01'));
    }

    public static function malformedRows(): array
    {
        return [['c1', 'rank'], ['snapshot', 'result'], ['mapping', 'winner'], ['snapshot', 'duplicate'], ['mapping', '2026']];
    }

    #[DataProvider('malformedRows')]
    public function test_unknown_fields_years_or_duplicate_sources_prevent_publication(string $kind, string $fault): void
    {
        $source = F::bundle($this->root, function (&$r, $event) use ($kind, $fault) {
            if ($event !== $kind) {
                return;
            }
            if ($fault === '2026') {
                $r['year'] = 2026;
            } elseif ($fault === 'duplicate') {
                $r['row_index'] = 0;
                $r['source_pointer'] = 'PJ0315.sensyuTypeInfo[0].stTori';
            } elseif ($kind === 'c1') {
                $r['entries'][0][$fault] = 1;
            } else {
                $r[$fault] = 1;
            }
        });
        try {
            (new Builder($source))->build($this->root.'/build');
            $this->fail('Invalid source accepted');
        } catch (\Throwable $e) {
            $this->assertFileDoesNotExist($this->root.'/build/COMPLETE.json');
        }
    }

    public function test_source_pin_body_seal_overwrite_and_no_auto_acceptance(): void
    {
        $source = F::bundle($this->root);
        $b = new Builder($source);
        $b->build($this->root.'/build');
        try {
            $b->build($this->root.'/build');
            $this->fail('Overwrite accepted');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('new output', $e->getMessage());
        }
        file_put_contents($this->root.'/snapshots/snapshots-2024.jsonl', '{}'."\n", FILE_APPEND);
        try {
            $b->build($this->root.'/drift');
            $this->fail('Changed source accepted');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('hash/size mismatch', $e->getMessage());
            $this->assertDirectoryDoesNotExist($this->root.'/drift');
        }
        $this->assertSame('REVIEW_CANDIDATE_ONLY', Bundle::open($this->root.'/build')['restrictions']['artifact_role']);
        $this->expectExceptionMessage('Fixed source pin mismatch');
        (new Sources(['snapshots' => ['path' => $this->root.'/snapshots',
            'seal' => ['sha256' => str_repeat('f', 64)]]]))->open();
    }

    public function test_verified_meeting_contradiction_blocks_race_but_unverified_member_does_not(): void
    {
        $source = F::bundle($this->root, function (&$r, $kind) {
            if ($kind === 'mapping' && $r['target']['bike'] === 1) {
                $r['target']['meeting_id']++;
                $r['context']['meeting']['meeting_id']++;
            }
        });
        $result = (new Builder($source))->build($this->root.'/build');
        $this->assertSame(20, $result['coverage']['total']['null_candidates']);
        $this->assertSame(20, $result['coverage']['total']['reasons']['CONFLICTING_VERIFIED_MEETING_CONTEXT']);
        $other = $this->root.'/unverified';
        mkdir($other);
        $source = F::bundle($other, function (&$r, $kind) {
            if ($kind === 'mapping' && $r['target']['bike'] === 1) {
                $r['checks']['meeting_matched'] = false;
                $r['checks']['identity_matched'] = false;
                $r['target']['meeting_id']++;
                $r['context']['meeting']['meeting_id']++;
            }
        });
        $result = (new Builder($source))->build($other.'/build');
        $this->assertSame(16, $result['coverage']['total']['numeric_candidates']);
        $this->assertArrayNotHasKey('CONFLICTING_VERIFIED_MEETING_CONTEXT', $result['coverage']['total']['reasons']);
    }

    public function test_unverified_duplicate_external_does_not_destroy_valid_member(): void
    {
        $source = F::bundle($this->root, function (&$r, $kind) {
            if ($kind === 'mapping' && $r['target']['bike'] === 1) {
                $r['context']['external_player_id'] = '000002';
                $r['checks']['identity_matched'] = false;
            }
        });
        $result = (new Builder($source))->build($this->root.'/build');
        $this->assertSame(16, $result['coverage']['total']['numeric_candidates']);
        $this->assertArrayNotHasKey('DUPLICATE_VERIFIED_MAPPING_EXTERNAL_ID', $result['coverage']['total']['reasons']);
    }

    public function test_unknown_source_contract_and_incomplete_bundle_are_rejected(): void
    {
        F::bundle($this->root);
        $path = $this->root.'/snapshots/manifest.json';
        $m = Files::json($path);
        $m['version'] = 'UNREVIEWED';
        file_put_contents($path, Files::canonical($m)."\n");
        file_put_contents($this->root.'/snapshots/COMPLETE.json', Files::canonical(Files::identity($path))."\n");
        try {
            (new Builder(F::sources($this->root)))->build($this->root.'/unknown');
            $this->fail('Unknown version accepted');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Unknown S snapshot contract', $e->getMessage());
        }
        unlink($this->root.'/snapshots/COMPLETE.json');
        $this->expectException(RuntimeException::class);
        F::sources($this->root)->open();
    }

    public function test_missing_or_duplicate_mapping_and_extra_unbound_snapshot_are_rejected(): void
    {
        foreach (['missing', 'duplicate', 'unbound'] as $fault) {
            $root = $this->root.'/'.$fault;
            mkdir($root);
            F::bundle($root);
            $path = $root.'/mapping/mapping-audit.jsonl';
            $rows = iterator_to_array(Artifacts::lines($path));
            if ($fault === 'missing') {
                array_pop($rows);
            } elseif ($fault === 'duplicate') {
                $rows[] = $rows[0];
            }
            file_put_contents($path, implode('', array_map(fn ($r) => Files::canonical($r)."\n", $rows)));
            $source = F::reseal($root, 'mapping', 'mapping-audit.jsonl');
            if ($fault === 'unbound') {
                $path = $root.'/snapshots/snapshots-2024.jsonl';
                $rows = iterator_to_array(Artifacts::lines($path));
                $rows[0]['fetch_log_id'] = 999999;
                file_put_contents($path, implode('', array_map(fn ($r) => Files::canonical($r)."\n", $rows)));
                $source = F::reseal($root, 'snapshots', 'snapshots-2024.jsonl');
            }
            try {
                (new Builder($source))->build($root.'/bad');
                $this->fail('Broken source accepted');
            } catch (\Throwable $e) {
                $this->assertFileDoesNotExist($root.'/bad/COMPLETE.json');
            }
        }
    }

    public function test_verified_duplicate_external_holds_both_members_not_other_entries(): void
    {
        $source = F::bundle($this->root, function (&$r, $kind) {
            if ($kind === 'mapping' && $r['target']['bike'] === 1) {
                $r['context']['external_player_id'] = '000002';
            }
            if ($kind === 'snapshot' && $r['bike_number'] === 1) {
                foreach (['ledger_external_id', 'observed_external_id', 'pc0201_external_id'] as $key) {
                    $r[$key] = '000002';
                }
            }
        });
        $result = (new Builder($source))->build($this->root.'/build');
        $this->assertSame(12, $result['coverage']['total']['numeric_candidates']);
        $this->assertSame(8, $result['coverage']['total']['reasons']['DUPLICATE_VERIFIED_MAPPING_EXTERNAL_ID']);
    }

    public function test_raw_object_and_list_types_are_preserved_in_observation_links(): void
    {
        $source = F::bundle($this->root, function (&$r, $kind) {
            if ($kind === 'snapshot' && $r['bike_number'] === 1) {
                F::value($r, $r['fetch_log_id'] % 2 === 0 ? [] : (object) []);
            }
        });
        $result = (new Builder($source))->build($this->root.'/build');
        $this->assertSame(4, $result['coverage']['total']['null_candidates']);
        $h = fopen($this->root.'/build/source-observation-links.jsonl', 'rb');
        $types = [];
        while (($line = fgets($h)) !== false) {
            $row = json_decode($line, flags: JSON_THROW_ON_ERROR);
            if ($row->kind === 'C1_OBSERVATION' && $row->original_identity->bike === 1) {
                $types[] = get_debug_type($row->field->raw);
            }
        }
        fclose($h);
        $this->assertSame(['stdClass', 'array', 'stdClass', 'array', 'stdClass', 'array', 'stdClass', 'array'], $types);
    }

    public function test_raw_observed_race_and_bike_mismatch_cannot_claim_a_valid_saved_identity(): void
    {
        $source = F::bundle($this->root, function (&$r, $kind) {
            if ($kind === 'snapshot' && $r['bike_number'] === 1) {
                $r['observed_race']['race_date'] = '20240101';
                $r['observed_bike'] = '2';
            }
        });
        $result = (new Builder($source))->build($this->root.'/build');
        $this->assertSame(16, $result['coverage']['total']['numeric_candidates']);
        $this->assertSame(4, $result['coverage']['total']['reasons']['OBSERVATION_RACE_OR_BIKE_MISMATCH']);
    }

    public function test_unknown_unresolved_fields_cannot_be_silently_ignored(): void
    {
        F::bundle($this->root);
        $path = $this->root.'/snapshots/unresolved-2024.jsonl';
        file_put_contents($path, Files::canonical(['race' => ['race_id' => 300001, 'race_date' => '2024-08-01'],
            'reason' => 'NO_PJ0315_CANDIDATE', 'actual_rank' => 1]).'\\n');
        $source = F::reseal($this->root, 'snapshots', 'unresolved-2024.jsonl');
        $this->expectException(RuntimeException::class);
        (new Builder($source))->build($this->root.'/build');
    }

    public function test_plan_without_source_db_http_raw_or_training(): void
    {
        $this->artisan('keirin:stat36:c1-candidate')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_streaming_in_independent_128m_process(): void
    {
        $execution = MemoryLimitedTestProcess::launch([PHP_BINARY, '-d', 'memory_limit=128M',
            base_path('tests/Support/start-count-c1-memory.php'), $this->root], $this->root, timeout: 180);
        $this->assertSame(0, $execution['exit_code'], file_get_contents($this->root.'/stderr.log'));
        $m = Files::json($this->root.'/measurement.json');
        $this->assertSame('128M', $m['limit']);
        $this->assertGreaterThan(100 * 1024 * 1024, $m['source_bytes']);
        $this->assertSame(10000, $m['candidates']);
        $this->assertSame(20000, $m['source_rows']);
        $this->assertLessThan(128 * 1024 * 1024, $m['peak_memory_bytes']);
    }
}
