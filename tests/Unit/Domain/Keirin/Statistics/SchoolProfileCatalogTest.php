<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Statistics;

use App\Domain\Keirin\Statistics\DTO\SchoolProfileIndexOptionsDto;
use App\Domain\Keirin\Statistics\Services\SchoolProfileCatalogService;
use App\Domain\Keirin\Statistics\Services\SchoolProfileSelectionService;
use App\Domain\Keirin\Statistics\Support\SchoolProfiles\Files;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\CompositionResultTemporaryDirectory;
use Tests\Support\SchoolProfileFixture as Fixture;

final class SchoolProfileCatalogTest extends TestCase
{
    private CompositionResultTemporaryDirectory $temporary;

    protected function setUp(): void
    {
        $this->temporary = CompositionResultTemporaryDirectory::create();
    }

    protected function tearDown(): void
    {
        try {
            $this->temporary->retire($this->temporary->path(), $this->status()->isSuccess() ? 'TEST_COMPLETE' : 'TEST_FAILURE');
        } finally {
            parent::tearDown();
        }
    }

    private function build(array $profiles): array
    {
        $root = $this->temporary->path();
        $input = Fixture::manifest($root.'/input-'.bin2hex(random_bytes(3)), $profiles);
        $catalog = new SchoolProfileCatalogService;
        $dir = $root.'/catalog-'.bin2hex(random_bytes(3));
        $catalog->build($input['path'], $input['sha256'], $dir);
        $selection = new SchoolProfileSelectionService($catalog);
        $path = $root.'/selection-'.bin2hex(random_bytes(3)).'.json';
        $plan = $selection->build($dir.'/manifest.json', Files::seal($dir.'/manifest.json')['sha256'], $path);
        $options = new SchoolProfileIndexOptionsDto($path, Files::seal($path)['sha256']);

        return [$catalog, $selection, $plan, $options, $dir];
    }

    public function test_versions_duplicates_original_order_and_types_survive_save_reload(): void
    {
        $p = Fixture::profile();
        $p['source'] = array_reverse($p['source'], true);
        $other = Fixture::profile(log: 2);
        [$catalog, $selection, $plan, $options, $dir] = $this->build([$p, $p, $other]);
        $loaded = $selection->load($options);
        $this->assertSame($p, $selection->profile($loaded, '000001')['profile']);
        $this->assertSame($p['source'], $selection->profile($loaded, '000001')['profile']['source']);
        $this->assertIsFloat($selection->profile($loaded, '000001')['profile']['STAT-03']['school_200m_seconds']);
        $counts = json_decode(file_get_contents($dir.'/summary.json'), true)['counts'];
        $this->assertSame(3, $counts['input_rows']);
        $this->assertSame(2, $counts['processed_versions']);
        $this->assertSame(1, $counts['duplicate_rows']);
        $this->assertSame(2, $plan['summary']['versions']);
        $this->assertSame(2, $plan['summary']['graduation_period_raw_not_recorded']);
        $this->assertSame(1, $plan['players']['000001']['selected']['fetch_log_id']);
        $this->assertSame($other, $catalog->record($loaded['catalog'], 2)['projection']);
    }

    #[DataProvider('selectionCases')]
    public function test_all_candidates_precede_selection_and_missing_does_not_mask_conflict(array $changes, ?int $chosen, string $reason): void
    {
        $profiles = [];
        foreach ($changes as $i => $change) {
            $p = array_replace_recursive(Fixture::profile(log: $i + 1), $change);
            if (array_key_exists('debut_date', $change) && ! isset($change['original_fields'])) {
                $p['original_fields']['debut_date_raw'] = $change['debut_date'] === null ? null : str_replace('-', '/', $change['debut_date']);
            }
            $profiles[] = $p;
        }
        [, $selection, $plan, $options] = $this->build($profiles);
        $this->assertSame($chosen, $plan['players']['000001']['selected']['fetch_log_id'] ?? null);
        $this->assertSame($reason, $plan['players']['000001']['reason']);
        $actual = $selection->profile($selection->load($options), '000001');
        $this->assertSame($chosen === null ? null : $profiles[$chosen - 1], $actual['profile']);
        $this->assertSame($chosen === null ? $reason : 'CONNECTED', $actual['reason']);
    }

    public static function selectionCases(): array
    {
        return [
            'one' => [[[]], 1, 'SINGLE_VALID_DEBUT'],
            'earlier' => [[[], ['source' => ['fetched_at' => '2026-06-30T23:59:59+09:00']]], 2, 'CONSISTENT_DEBUT_EARLIEST_FETCH_THEN_ID'],
            'UTC same instant' => [[[], ['source' => ['fetched_at' => '2026-06-30T15:00:00Z']]], 1, 'CONSISTENT_DEBUT_EARLIEST_FETCH_THEN_ID'],
            'microseconds' => [[['source' => ['fetched_at' => '2026-07-01T00:00:00.000001+09:00']], []], 2, 'CONSISTENT_DEBUT_EARLIEST_FETCH_THEN_ID'],
            'year boundary' => [[['source' => ['fetched_at' => '2024-12-31T15:00:00Z']], ['source' => ['fetched_at' => '2025-01-01T00:00:00+09:00']]], 1, 'CONSISTENT_DEBUT_EARLIEST_FETCH_THEN_ID'],
            'conflict late' => [[[], ['debut_date' => '2011-01-01']], null, 'DEBUT_DATE_CONFLICT'],
            'conflict reverse' => [[['debut_date' => '2011-01-01'], []], null, 'DEBUT_DATE_CONFLICT'],
            'missing' => [[['debut_date' => null]], null, 'MISSING_DEBUT_DATE'],
            'invalid' => [[['debut_date' => null, 'original_fields' => ['debut_date_raw' => 'unparseable']]], null, 'INVALID_DEBUT_DATE'],
            'valid plus invalid' => [[[], ['debut_date' => null, 'original_fields' => ['debut_date_raw' => 'unparseable']]], null, 'INVALID_DEBUT_DATE'],
            'invalid plus valid' => [[['debut_date' => null, 'original_fields' => ['debut_date_raw' => 'unparseable']], []], null, 'INVALID_DEBUT_DATE'],
            'valid plus missing' => [[[], ['debut_date' => null]], 1, 'SINGLE_VALID_DEBUT'],
            'school conflicts do not block debut' => [[[], ['STAT-02' => ['school_rank_raw' => 9], 'STAT-03' => ['school_200m_seconds' => 12.2], 'graduation_period' => 101]], 1, 'CONSISTENT_DEBUT_EARLIEST_FETCH_THEN_ID'],
        ];
    }

    public function test_field_conflicts_remain_separate_and_other_people_and_order_do_not_change_selection(): void
    {
        $p = Fixture::profile(log: 1);
        $q = Fixture::profile(log: 2);
        $q['STAT-02']['school_rank_raw'] = 8;
        $q['STAT-03']['school_200m_seconds'] = 12.1;
        $q['graduation_period'] = 101;
        [, , $a] = $this->build([$p, $q]);
        [, , $b] = $this->build([Fixture::profile('000002', 3), $q, $p]);
        $this->assertSame($a['players']['000001'], $b['players']['000001']);
        $this->assertSame(['school_rank' => [5, 8], 'school_200m' => [11.0, 12.1], 'graduation_period' => [100, 101]], $a['players']['000001']['field_conflicts']);
    }

    #[DataProvider('badSources')]
    public function test_identity_and_integrity_failures_are_not_successful_missing_records(string $mutation): void
    {
        $p = Fixture::profile();
        $q = $p;
        if ($mutation === 'person') {
            $q['external_player_id'] = '000002';
        } elseif ($mutation === 'raw') {
            $q['source']['sha256'] = str_repeat('b', 64);
        } elseif ($mutation === 'bytes') {
            $q['source']['bytes'] = 43;
        } elseif ($mutation === 'projection') {
            $q['graduation_period'] = 101;
        } else {
            $q['source']['fetched_at'] = $mutation === 'missing time' ? '' : '2026-02-31T00:00:00Z';
        }
        $this->expectException(\Throwable::class);
        $this->build([$p, $q]);
    }

    public static function badSources(): array
    {
        return array_map(fn ($v) => [$v], ['person', 'raw', 'bytes', 'projection', 'missing time', 'invalid time']);
    }

    public function test_input_seal_output_collision_selection_tampering_and_dry_run(): void
    {
        $root = $this->temporary->path();
        $input = Fixture::manifest($root.'/dry-input', [Fixture::profile()]);
        $catalog = new SchoolProfileCatalogService;
        $before = scandir($root);
        $out = $catalog->build($input['path'], $input['sha256'], $root.'/absent', true);
        $this->assertTrue($out['dry_run']);
        $this->assertSame($before, scandir($root));
        [$cat, $sel, $plan, $options, $dir] = $this->build([Fixture::profile()]);
        $snapshot = hash_file('sha256', $dir.'/versions.jsonl');
        try {
            $catalog->build($input['path'], $input['sha256'], $dir);
            $this->fail('Existing output was accepted.');
        } catch (RuntimeException) {
            $this->assertSame($snapshot, hash_file('sha256', $dir.'/versions.jsonl'));
        }
        $this->assertSame($plan, $sel->build($dir.'/manifest.json', Files::seal($dir.'/manifest.json')['sha256'], $root.'/not-written', true));
        $this->assertFileDoesNotExist($root.'/not-written');
        $plan['players']['000001']['selected']['raw_sha256'] = str_repeat('b', 64);
        Files::write($root.'/tampered.json', Files::json($plan));
        try {
            $sel->load(new SchoolProfileIndexOptionsDto($root.'/tampered.json', Files::seal($root.'/tampered.json')['sha256']));
            $this->fail('Resealed bad selection was accepted.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('fixed-rule mismatch', $error->getMessage());
        }
        $this->expectException(RuntimeException::class);
        $catalog->build($input['path'], str_repeat('0', 64), $root.'/bad-seal');
    }

    #[DataProvider('invalidIndexEntries')]
    public function test_index_record_binding_and_bounded_reads_reject_invalid_references(array $change): void
    {
        [$catalog, $selection, , $options] = $this->build([Fixture::profile()]);
        $loaded = $selection->load($options)['catalog'];
        $loaded['index']['versions']['1'] = array_replace($loaded['index']['versions']['1'], $change);
        $this->expectException(RuntimeException::class);
        $catalog->record($loaded, 1);
    }

    public static function invalidIndexEntries(): array
    {
        return [[['offset' => -1]], [['bytes' => 128 * 1024 * 1024]],
            [['external_player_id' => '000002']], [['projection_sha256' => str_repeat('b', 64)]],
            [['record_sha256' => str_repeat('b', 64)]]];
    }

    public function test_fatal_identity_conflict_retains_partial_evidence_and_diagnostic_not_complete(): void
    {
        $root = $this->temporary->path();
        $p = Fixture::profile();
        $q = $p;
        $q['source']['sha256'] = str_repeat('b', 64);
        $input = Fixture::manifest($root.'/conflict-input', [$p, $q]);
        $before = Files::seal(dirname($input['path']).'/projections.jsonl');
        try {
            (new SchoolProfileCatalogService)->build($input['path'], $input['sha256'], $root.'/partial');
            $this->fail('Conflicting fetch-log identity was accepted.');
        } catch (RuntimeException) {
            $failure = json_decode(file_get_contents($root.'/partial/FAILED.json'), true);
            $this->assertSame(2, $failure['counts']['input_rows']);
            $this->assertSame(1, $failure['counts']['processed_versions']);
            $this->assertSame(1, $failure['counts']['identity_conflicts']);
            $this->assertSame(0, $failure['counts']['duplicate_rows']);
            $this->assertSame(['line' => 2, 'fetch_log_id' => 1], $failure['current_record']);
            $this->assertFileExists($root.'/partial/versions.jsonl');
            $this->assertFileDoesNotExist($root.'/partial/COMPLETE.json');
            $this->assertSame($before, Files::seal(dirname($input['path']).'/projections.jsonl'));
        }
    }
}
