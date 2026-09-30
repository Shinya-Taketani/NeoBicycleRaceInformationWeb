<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Keirin\Audit\Stat35DataReadiness\RawReader;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Scraping\Exceptions\CharacterEncodingConversionException;
use App\Domain\Keirin\Scraping\Exceptions\ParserException;
use App\Domain\Keirin\Statistics\AgariRaceRelative\Artifacts;
use App\Domain\Keirin\Statistics\StartObservation\Builder;
use App\Domain\Keirin\Statistics\StartObservation\Contract;
use App\Domain\Keirin\Statistics\StartObservation\Ledger;
use App\Domain\Keirin\Statistics\StartObservation\Parser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\MemoryLimitedTestProcess;
use Tests\Support\StartObservationFixture as Fixture;
use Tests\TestCase;

class StartObservationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/stat36-'.bin2hex(random_bytes(8));
        mkdir($this->root);
        DB::shouldReceive('connection')->never();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public static function displays(): array
    {
        return [
            'literal' => [[['kojinState' => 'S']], 'EXACT_S_DISPLAY_UNINTERPRETED', true],
            'none' => [[], 'EMPTY_ARRAY_NO_S_DISPLAY', false],
            'blank' => [[['kojinState' => '']], 'BLANK_DISPLAY_MEANING_UNKNOWN', null],
            'null' => [null, 'FIELD_NULL', null],
            'string blank' => ['', 'FIELD_BLANK', null],
            'missing' => ['ABSENT', 'FIELD_MISSING', null],
            'S_CLASS' => [[['kojinState' => 'S_CLASS']], 'UNKNOWN_EXPRESSION', null],
            'S1' => [[['kojinState' => 'S1']], 'UNKNOWN_EXPRESSION', null],
            'S2' => [[['kojinState' => 'S2']], 'UNKNOWN_EXPRESSION', null],
            'prose' => [[['kojinState' => 'has S marker']], 'UNKNOWN_EXPRESSION', null],
            'full width' => [[['kojinState' => 'Ｓ']], 'UNKNOWN_EXPRESSION', null],
            'spaced' => [[['kojinState' => ' S ']], 'UNKNOWN_EXPRESSION', null],
            'unknown field' => [[['another' => 'S']], 'UNSUPPORTED_FIELD_SCHEMA', null],
            'scalar' => [42, 'UNSUPPORTED_FIELD_SCHEMA', null],
            'abnormal' => [[['kojinState' => '落車棄権']], 'NON_S_QUALITY_DISPLAY', false],
        ];
    }

    #[DataProvider('displays')]
    public function test_display_is_not_start_definition_and_missing_is_not_false(mixed $value, string $state, ?bool $display): void
    {
        $f = Fixture::data();
        if ($value === 'ABSENT') {
            unset($f['rows'][0]['kojinStateItemSubData']);
        } else {
            $f['rows'][0]['kojinStateItemSubData'] = $value;
        }
        $f['rows'][0]['sensyuName'] = 'S';
        $f['rows'][0]['BH'] = 'S';
        $f['rows'][0]['inLineJyuni'] = '1';
        $out = $this->parse($f)['rows'][0];
        $this->assertSame($state, $out['display_state']);
        $this->assertSame($display, $out['observed_s_display']);
        $this->assertNull($out['start_acquired']);
        $this->assertSame('000001', $out['external_player_id']);
        $this->assertArrayNotHasKey('sensyuName', $out);
    }

    public static function identities(): array
    {
        return [['bike'], ['duplicate'], ['external'], ['date'], ['track'], ['race_number']];
    }

    #[DataProvider('identities')]
    public function test_identity_conflicts_are_retained_not_repaired(string $case): void
    {
        $f = Fixture::data();
        $context = ['selKaisai' => '20240101', 'selKjyoCd' => '22', 'selRaceNo' => 1];
        match ($case) {
            'bike' => $f['rows'][0]['syaban'] = '0',
            'duplicate' => $f['rows'][1]['syaban'] = '1',
            'external' => $f['rows'][0]['sensyuRegistNo'] = '999999',
            'date' => $context['selKaisai'] = '20240102',
            'track' => $context['selKjyoCd'] = '11',
            'race_number' => $context['selRaceNo'] = 2,
        };
        $out = (new Parser)->parse(Fixture::html($f['rows'], context: $context), $f['race'], $f['entries'], $f['import']);
        $this->assertCount(5, $out['rows']);
        $this->assertSame('UNRESOLVED', $out['rows'][0]['identity_status']);
        $this->assertNotEmpty($out['rows'][0]['issues']);
    }

    public function test_cancelled_partial_empty_unpublished_and_accident_disqualification_are_separate(): void
    {
        $f = Fixture::data();
        $f['import']['parsed_page_status'] = 'CANCELLED';
        $f['rows'] = [];
        $this->assertSame('CANCELLED_EMPTY', $this->parse($f)['page_status']);
        $f['rows'] = array_slice(Fixture::data()['rows'], 0, 1);
        $f['rows'][0]['kojinStateItemSubData'] = [['kojinState' => '失格'], ['kojinState' => '落車棄権']];
        $out = $this->parse($f);
        $this->assertSame('CANCELLED_PARTIAL_OR_NONEMPTY', $out['page_status']);
        $this->assertContains('PARTIAL_OR_DIFFERENT_BIKE_SET', $out['issues']);
        $this->assertSame(['失格', '落車棄権'], $out['rows'][0]['individual_quality']);
        $f['import']['parsed_page_status'] = 'RESULTS_AVAILABLE';
        $this->assertCount(1, $this->parse($f)['rows']);
        $out = (new Parser)->parse(Fixture::html([], extra: ['tyakujyunDispFlg' => false]), $f['race'], $f['entries'], $f['import']);
        $this->assertSame('RESULT_UNPUBLISHED', $out['page_status']);
    }

    public function test_multiple_s_displays_are_not_forced_to_one_and_outcomes_do_not_change_display(): void
    {
        $f = Fixture::data();
        $f['rows'][1]['kojinStateItemSubData'] = [['kojinState' => 'S']];
        $before = $this->parse($f);
        $this->assertSame(2, $before['display_s_count']);
        $this->assertContains('MULTIPLE_S_DISPLAYS_UNINTERPRETED', $before['issues']);
        foreach ($f['rows'] as &$row) {
            $row['tyaku'] = 'CHANGED_TARGET_RANK';
            $row['kimarite'] = 'UNUSED_OUTCOME';
        }
        unset($row);
        $after = (new Parser)->parse(Fixture::html($f['rows'], extra: ['haraiGakuSubData' => ['arbitrary' => 900000]]), $f['race'], $f['entries'], $f['import']);
        $this->assertSame($before, $after);
    }

    public function test_unknown_header_or_field_schema_is_never_given_known_meaning(): void
    {
        $f = Fixture::data();
        $out = (new Parser)->parse(Fixture::html($f['rows'], ['Some other display']), $f['race'], $f['entries'], $f['import']);
        $this->assertSame('UNSUPPORTED_DISPLAY_SCHEMA', $out['format']);
        $this->assertNull($out['rows'][0]['observed_s_display']);
        $f['rows'][0]['new_start_field'] = 'S';
        $this->assertSame('UNSUPPORTED_DISPLAY_SCHEMA', $this->parse($f)['rows'][0]['display_state']);
    }

    public function test_invalid_json_fails_instead_of_becoming_ordinary_missing(): void
    {
        $f = Fixture::data();
        $this->expectException(ParserException::class);
        (new Parser)->parse('<script>jsonData["PC0201"]={broken};</script>', $f['race'], $f['entries'], $f['import']);
    }

    public function test_fixed_ledger_all_versions_counts_seals_and_offline_reproduction(): void
    {
        $pin = Fixture::bundle($this->root.'/source');
        $builder = $this->builder($pin);
        $first = $builder->build($this->root.'/source', $this->root.'/result');
        $again = $builder->build($this->root.'/source', $this->root.'/again', $this->root.'/result');
        $this->assertSame($first['manifest'], $again['manifest']);
        $this->assertTrue($again['independent_reproduction']);
        $y = $first['years'][2024];
        $this->assertSame([1, 2, 10, 5, 0], [$y['ledger_races'], $y['imports'], $y['observation_rows'], $y['unique_matched_entries'], $y['confirmed_start_values']]);
        $rows = iterator_to_array(Artifacts::lines($this->root.'/result/observations-2024.jsonl'));
        $this->assertSame([1, 2], array_values(array_unique(array_column($rows, 'import_id'))));
        $this->assertSame([null], array_values(array_unique(array_column($rows, 'start_acquired'), SORT_REGULAR)));
        $this->assertSame('UNKNOWN_POSITION_DEFINITION', $rows[0]['interpretation_status']);
        $this->assertSame('MISSING_INITIAL_POSITION', $rows[0]['initial_position_status']);
        Http::assertNothingSent();
    }

    public function test_raw_drift_is_fatal_and_not_published(): void
    {
        $pin = Fixture::bundle($this->root.'/source');
        $path = $this->root.'/source/raw.html';
        $raw = file_get_contents($path);
        file_put_contents($path, substr_replace($raw, 'x', 0, 1));
        try {
            $this->builder($pin)->build($this->root.'/source', $this->root.'/result');
            $this->fail('Drift accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('hash/size mismatch', $e->getMessage());
        }
        $this->assertFileDoesNotExist($this->root.'/result/COMPLETE.json');
        $this->assertFileExists($this->root.'/result/failure.json');
    }

    public function test_2026_is_rejected_before_touching_even_missing_raw(): void
    {
        $pin = Fixture::bundle($this->root.'/source');
        $rows = iterator_to_array(Artifacts::lines($this->root.'/source/database-inventory.jsonl'));
        $rows[0]['race']['race_date'] = '2026-01-01';
        unlink($this->root.'/source/database-inventory.jsonl');
        unlink($this->root.'/source/database-inventory.jsonl.manifest.json');
        JsonlArtifact::write($this->root.'/source/database-inventory.jsonl', $rows);
        $pin = Fixture::seal($this->root.'/source');
        unlink($this->root.'/source/raw.html');
        $this->expectExceptionMessage('Forbidden or invalid race date: 2026-01-01');
        $this->builder($pin)->build($this->root.'/source', $this->root.'/result');
    }

    public function test_original_bundle_cannot_be_overwritten_or_nested(): void
    {
        $pin = Fixture::bundle($this->root.'/source');
        $this->expectExceptionMessage('overlapping');
        $this->builder($pin)->build($this->root.'/source', $this->root.'/source/nested');
    }

    public function test_existing_output_is_not_overwritten(): void
    {
        $pin = Fixture::bundle($this->root.'/source');
        mkdir($this->root.'/result');
        $this->expectExceptionMessage('new output');
        $this->builder($pin)->build($this->root.'/source', $this->root.'/result');
    }

    public function test_wrong_accepted_pin_and_resealed_ledger_are_rejected(): void
    {
        $pin = Fixture::bundle($this->root.'/source');
        $m = Files::json($this->root.'/source/manifest.json');
        $m['audit_id'] = 'wrong';
        unlink($this->root.'/source/manifest.json');
        unlink($this->root.'/source/LOCKED.json');
        JsonlArtifact::json($this->root.'/source/manifest.json', $m);
        JsonlArtifact::json($this->root.'/source/LOCKED.json', Files::identity($this->root.'/source/manifest.json'));
        $this->expectExceptionMessage('hash/size mismatch');
        $this->builder($pin)->build($this->root.'/source', $this->root.'/result');
    }

    public function test_cp932_original_and_utf8_converted_hash_and_invalid_encoding(): void
    {
        Fixture::bundle($this->root.'/source');
        $source = iterator_to_array(Artifacts::lines($this->root.'/source/raw-source-inventory.jsonl'))[0];
        $utf8 = '<html><p>個人状況</p></html>';
        file_put_contents($source['absolute_path'], mb_convert_encoding($utf8, 'CP932', 'UTF-8'));
        $seal = Files::identity($source['absolute_path']);
        $source['content_type'] = 'text/html;charset=Shift_JIS';
        $source['source_hash'] = $source['fetch_hash'] = $seal['sha256'];
        $source['raw_response_size'] = $source['fetch_bytes'] = $seal['bytes'];
        $source['converted_hash'] = hash('sha256', $utf8);
        $this->assertSame($utf8, (new RawReader)->read($source));
        $source['content_type'] = 'text/html;charset=UTF-8';
        $this->expectException(CharacterEncodingConversionException::class);
        (new RawReader)->read($source);
    }

    public function test_plan_needs_no_db_http_or_sources(): void
    {
        $this->artisan('keirin:stat36:observations', ['mode' => 'plan'])->assertExitCode(0);
        $this->assertFalse(Contract::plan()['historical_as_of_available']);
        Http::assertNothingSent();
    }

    public function test_streaming_build_in_independent_real_128m_process(): void
    {
        mkdir($this->root.'/memory');
        $execution = MemoryLimitedTestProcess::launch([PHP_BINARY, '-d', 'memory_limit=128M', base_path('tests/Support/start-observation-memory.php'), $this->root.'/memory'], $this->root.'/memory', timeout: 300);
        $this->assertSame(0, $execution['exit_code'], file_get_contents($this->root.'/memory/stderr.log'));
        $m = Files::json($this->root.'/memory/measurement.json');
        $this->assertSame('128M', $m['limit']);
        $this->assertNotSame(getmypid(), $m['pid']);
        $this->assertGreaterThan(100 * 1024 * 1024, $m['source_bytes']);
        $this->assertLessThan(128 * 1024 * 1024, $m['peak']);
        $this->assertSame(12000, $m['imports']);
        $this->assertSame(60000, $m['rows']);
    }

    public function test_raw_reader_rejects_2026_before_path_or_hash_access(): void
    {
        $this->expectExceptionMessage('Forbidden or invalid race date: 2026-01-01');
        (new RawReader)->read(['race_date' => '2026-01-01']);
    }

    public function test_converted_hash_is_checked_independently(): void
    {
        Fixture::bundle($this->root.'/source');
        $source = iterator_to_array(Artifacts::lines($this->root.'/source/raw-source-inventory.jsonl'))[0];
        $source['converted_hash'] = str_repeat('0', 64);
        $this->expectExceptionMessage('CONVERTED_HASH_MISMATCH');
        (new RawReader)->read($source);
    }

    public function test_raw_inventory_must_match_import_and_does_not_allow_orphan_rows(): void
    {
        $pin = Fixture::bundle($this->root.'/source');
        $rows = iterator_to_array(Artifacts::lines($this->root.'/source/raw-source-inventory.jsonl'));
        $rows[0]['race_id']++;
        unlink($this->root.'/source/raw-source-inventory.jsonl');
        unlink($this->root.'/source/raw-source-inventory.jsonl.manifest.json');
        JsonlArtifact::write($this->root.'/source/raw-source-inventory.jsonl', $rows);
        $pin = Fixture::seal($this->root.'/source');
        $this->expectExceptionMessage('Raw inventory/import mismatch');
        $this->builder($pin)->build($this->root.'/source', $this->root.'/result');
    }

    public function test_reproduction_rejects_modified_generated_file(): void
    {
        $pin = Fixture::bundle($this->root.'/source');
        $builder = $this->builder($pin);
        $builder->build($this->root.'/source', $this->root.'/result');
        file_put_contents($this->root.'/result/observations-2024.jsonl', 'drift', FILE_APPEND);
        try {
            $builder->build($this->root.'/source', $this->root.'/reproduction', $this->root.'/result');
            $this->fail('Modified original accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('hash/size mismatch', $e->getMessage());
        }
        $this->assertDirectoryDoesNotExist($this->root.'/reproduction');
    }

    public function test_revisions_preserve_both_observations_and_report_changed_display(): void
    {
        Fixture::bundle($this->root.'/source');
        $database = iterator_to_array(Artifacts::lines($this->root.'/source/database-inventory.jsonl'));
        $raws = iterator_to_array(Artifacts::lines($this->root.'/source/raw-source-inventory.jsonl'));
        $f = Fixture::data();
        $f['rows'][0]['kojinStateItemSubData'] = [];
        file_put_contents($this->root.'/source/corrected.html', Fixture::html($f['rows']));
        $seal = Files::identity($this->root.'/source/corrected.html');
        $second = &$database[0]['imports'][1];
        $second['absolute_path'] = $this->root.'/source/corrected.html';
        $second['fetch_path'] = $second['raw_file_path'] = 'corrected.html';
        $second['fetch_hash'] = $second['source_hash'] = $second['converted_hash'] = $seal['sha256'];
        $second['fetch_bytes'] = $second['raw_response_size'] = $seal['bytes'];
        $raws[1] = $second + ['raw_sha256' => $seal['sha256'], 'raw_bytes' => $seal['bytes']];
        unset($second);
        foreach (['database-inventory.jsonl' => $database, 'raw-source-inventory.jsonl' => $raws] as $name => $rows) {
            unlink($this->root.'/source/'.$name);
            unlink($this->root.'/source/'.$name.'.manifest.json');
            JsonlArtifact::write($this->root.'/source/'.$name, $rows);
        }
        $pin = Fixture::seal($this->root.'/source');
        $result = $this->builder($pin)->build($this->root.'/source', $this->root.'/result');
        $this->assertSame(1, $result['years'][2024]['entries_with_multiple_display_versions']);
        $rows = iterator_to_array(Artifacts::lines($this->root.'/result/observations-2024.jsonl'));
        $this->assertCount(10, $rows);
        $this->assertSame($rows[0]['revision_key'], $rows[5]['revision_key']);
        $this->assertNotSame($rows[0]['display_signature'], $rows[5]['display_signature']);
        $this->assertTrue($rows[0]['observed_s_display']);
        $this->assertFalse($rows[5]['observed_s_display']);
        $this->assertNull($rows[5]['start_acquired']);
    }

    private function builder(array $pin): Builder
    {
        return new Builder(new Ledger($pin), new RawReader, new Parser);
    }

    private function parse(array $fixture): array
    {
        return (new Parser)->parse(Fixture::html($fixture['rows']), $fixture['race'], $fixture['entries'], $fixture['import']);
    }
}
