<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Keirin\Audit\Stat35DataReadiness\RawReader;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Scraping\Exceptions\ParserException;
use App\Domain\Keirin\Statistics\AgariRaceRelative\Artifacts;
use App\Domain\Keirin\Statistics\StartObservation\Builder;
use App\Domain\Keirin\Statistics\StartObservation\Contract;
use App\Domain\Keirin\Statistics\StartObservation\DisplaySignature;
use App\Domain\Keirin\Statistics\StartObservation\Ledger;
use App\Domain\Keirin\Statistics\StartObservation\Parser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\StartObservationFixture as Fixture;
use Tests\TestCase;

class StartObservationPageStateTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/stat36-page-state-'.bin2hex(random_bytes(8));
        mkdir($this->root);
        DB::shouldReceive('connection')->never();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_true_flag_with_empty_results_is_not_unpublished(): void
    {
        $page = $this->parse(['tyakujyunDispFlg' => true, 'tyakujyunItemSubData' => []]);
        $this->assertSame('RESULT_DISPLAY', $page['page_status']);
        $this->assertSame([], $page['rows']);
    }

    public function test_unknown_flag_with_rows_is_not_display_and_keeps_observations(): void
    {
        $page = $this->parse(['tyakujyunDispFlg' => 'unknown', 'tyakujyunItemSubData' => Fixture::data()['rows']]);
        $this->assertSame('RESULT_DISPLAY_UNKNOWN', $page['page_status']);
        $this->assertCount(5, $page['rows']);
        $this->assertSame([1, 2, 3, 4, 5], array_column($page['rows'], 'bike_number'));
    }

    private static function flags(): array
    {
        return [
            'true' => [true, true, 'bool', 'RESULT_DISPLAY'],
            'one' => [true, 1, 'int', 'RESULT_DISPLAY'],
            'string one' => [true, '1', 'string', 'RESULT_DISPLAY'],
            'false' => [true, false, 'bool', 'RESULT_UNPUBLISHED'],
            'zero' => [true, 0, 'int', 'RESULT_UNPUBLISHED'],
            'string zero' => [true, '0', 'string', 'RESULT_UNPUBLISHED'],
            'missing' => [false, null, 'MISSING', 'RESULT_DISPLAY_UNKNOWN'],
            'null' => [true, null, 'null', 'RESULT_DISPLAY_UNKNOWN'],
            'blank' => [true, '', 'string', 'RESULT_DISPLAY_UNKNOWN'],
            'unknown string' => [true, 'unknown', 'string', 'RESULT_DISPLAY_UNKNOWN'],
            'string true' => [true, 'true', 'string', 'RESULT_DISPLAY_UNKNOWN'],
            'string false' => [true, 'false', 'string', 'RESULT_DISPLAY_UNKNOWN'],
            'whitespace' => [true, ' 1 ', 'string', 'RESULT_DISPLAY_UNKNOWN'],
            'unsupported integer' => [true, 2, 'int', 'RESULT_DISPLAY_UNKNOWN'],
            'negative integer' => [true, -1, 'int', 'RESULT_DISPLAY_UNKNOWN'],
            'float one' => [true, 1.0, 'float', 'RESULT_DISPLAY_UNKNOWN'],
            'float zero' => [true, 0.0, 'float', 'RESULT_DISPLAY_UNKNOWN'],
            'empty array' => [true, [], 'array', 'RESULT_DISPLAY_UNKNOWN'],
            'array' => [true, [false, 'x'], 'array', 'RESULT_DISPLAY_UNKNOWN'],
            'empty object' => [true, (object) [], 'object', 'RESULT_DISPLAY_UNKNOWN'],
            'object' => [true, (object) ['value' => false], 'object', 'RESULT_DISPLAY_UNKNOWN'],
        ];
    }

    public static function flagsAndResults(): iterable
    {
        foreach (self::flags() as $name => $flag) {
            foreach (['MISSING', 'NULL', 'EMPTY_ARRAY', 'ROWS'] as $resultState) {
                yield $name.' / '.$resultState => [...$flag, $resultState];
            }
        }
    }

    #[DataProvider('flagsAndResults')]
    public function test_display_flag_and_result_presence_are_independent(bool $present, mixed $raw, string $type, string $state, string $resultState): void
    {
        $input = self::input($present, $raw, $resultState);
        $page = $this->parse($input);
        $this->assertSame($state, $page['page_status']);
        $this->assertSame($state, $page['result_display_flag']['state']);
        $this->assertSame($present ? ($raw === null ? 'NULL' : 'PRESENT') : 'MISSING', $page['result_display_flag']['presence']);
        $this->assertSame($type, $page['result_display_flag']['type']);
        $this->assertSame(self::encode($raw), self::encode($page['result_display_flag']['raw']));
        $this->assertSame('PJ0326.tyakujyunDispFlg', $page['result_display_flag']['source_pointer']);
        $this->assertSame($resultState, $page['result_state']);
        $this->assertSame($resultState === 'ROWS' ? 'VALUE' : $resultState, $page['result_presence']);
        $this->assertSame('RESULTS_AVAILABLE', $page['ledger_page_status']);
        $this->assertSame(Contract::PAGE_STATE_VERSION, $page['page_state_version']);
        $this->assertCount($resultState === 'ROWS' ? 5 : 0, $page['rows']);
        $this->assertSame($state === 'RESULT_DISPLAY_UNKNOWN', in_array('RESULT_DISPLAY_UNKNOWN', $page['issues'], true));
        if ($resultState === 'ROWS') {
            $known = $this->parse(self::input(true, true, 'ROWS'));
            foreach ($page['rows'] as $i => $row) {
                $this->assertSame($known['rows'][$i]['fields'], $row['fields']);
                $this->assertSame(DisplaySignature::hash($known['rows'][$i]), DisplaySignature::hash($row));
                $this->assertNull($row['start_acquired']);
            }
        }
    }

    public static function cancelledResults(): iterable
    {
        foreach ([true, false, 'unknown', null] as $flag) {
            foreach (['MISSING', 'NULL', 'EMPTY_ARRAY', 'ROWS'] as $resultState) {
                yield self::encode($flag).' / '.$resultState => [$flag, $resultState];
            }
        }
    }

    #[DataProvider('cancelledResults')]
    public function test_cancelled_ledger_state_does_not_erase_flag_or_result_state(mixed $flag, string $resultState): void
    {
        $page = $this->parse(self::input(true, $flag, $resultState), 'CANCELLED');
        $this->assertSame($resultState === 'ROWS' ? 'CANCELLED_PARTIAL_OR_NONEMPTY' : 'CANCELLED_EMPTY', $page['page_status']);
        $this->assertSame('CANCELLED', $page['ledger_page_status']);
        $this->assertSame($resultState, $page['result_state']);
        $this->assertSame($flag === true ? 'RESULT_DISPLAY' : ($flag === false ? 'RESULT_UNPUBLISHED' : 'RESULT_DISPLAY_UNKNOWN'), $page['result_display_flag']['state']);
        $this->assertSame($flag, $page['result_display_flag']['raw']);
        $this->assertCount($resultState === 'ROWS' ? 5 : 0, $page['rows']);
    }

    public static function unsupportedResults(): array
    {
        return [[''], [false], [1], [(object) []], [(object) ['0' => Fixture::data()['rows'][0]]]];
    }

    #[DataProvider('unsupportedResults')]
    public function test_unsupported_result_schema_keeps_existing_hold_route_and_independent_flag(mixed $result): void
    {
        $page = $this->parse(['tyakujyunDispFlg' => 'unknown', 'tyakujyunItemSubData' => $result], 'CANCELLED');
        $this->assertSame('UNSUPPORTED_RESULT_SCHEMA', $page['page_status']);
        $this->assertSame('UNSUPPORTED_RESULT_SCHEMA', $page['result_state']);
        $this->assertSame('RESULT_DISPLAY_UNKNOWN', $page['result_display_flag']['state']);
        $this->assertSame('CANCELLED', $page['ledger_page_status']);
        $this->assertSame([], $page['rows']);
    }

    public function test_unknown_flags_and_result_states_reconcile_audit_coverage_and_rows_without_touching_signature_v2(): void
    {
        $cases = [];
        foreach (self::flags() as [$present, $raw, $type, $state]) {
            foreach (['MISSING', 'NULL', 'EMPTY_ARRAY', 'ROWS'] as $resultState) {
                $cases[] = [self::input($present, $raw, $resultState), 'RESULTS_AVAILABLE'];
            }
        }
        $cases[] = [self::input(true, 'unknown', 'EMPTY_ARRAY'), 'CANCELLED'];
        $cases[] = [self::input(true, 'unknown', 'ROWS'), 'CANCELLED'];
        $cases[] = [['tyakujyunDispFlg' => 'unknown', 'tyakujyunItemSubData' => (object) []], 'CANCELLED'];
        [$builder, $originals] = $this->bundle($cases);
        $result = $builder->build($this->root.'/source', $this->root.'/result');
        $audit = iterator_to_array(Artifacts::lines($this->root.'/result/import-audit.jsonl'));
        $typedAudit = array_map(static fn (string $line) => json_decode($line, false, flags: JSON_THROW_ON_ERROR),
            file($this->root.'/result/import-audit.jsonl', FILE_IGNORE_NEW_LINES));
        $coverage = Files::json($this->root.'/result/coverage.json')['years'][2024];
        $observations = iterator_to_array(Artifacts::lines($this->root.'/result/observations-2024.jsonl'));
        $this->assertCount(87, $audit);
        $this->assertCount(110, $observations);
        $this->assertSame(87, $coverage['imports']);
        $this->assertSame(110, $coverage['observation_rows']);
        $this->assertSame(0, $coverage['entries_with_multiple_display_versions']);
        $this->assertSame(63, $coverage['result_display_states']['RESULT_DISPLAY_UNKNOWN']);
        foreach (['page_statuses' => ['page_status'], 'result_display_states' => ['result_display_flag', 'state'],
            'result_flag_presence' => ['result_display_flag', 'presence'], 'result_flag_types' => ['result_display_flag', 'type'],
            'result_states' => ['result_state'], 'result_presence' => ['result_presence']] as $dimension => $path) {
            $counts = [];
            foreach ($audit as $row) {
                $value = $row;
                foreach ($path as $key) {
                    $value = $value[$key];
                }
                $counts[$value] = ($counts[$value] ?? 0) + 1;
            }
            ksort($counts, SORT_STRING);
            $this->assertSame($counts, $coverage[$dimension]);
            $this->assertSame(87, array_sum($coverage[$dimension]));
        }
        $this->assertSame(60, $coverage['page_statuses']['RESULT_DISPLAY_UNKNOWN']);
        foreach ($cases as $i => [$input, $ledgerStatus]) {
            $expected = $this->parse($input, $ledgerStatus);
            $this->assertSame(self::encode($expected['result_display_flag']), self::encode($typedAudit[$i]->result_display_flag));
            $this->assertSame($expected['page_status'], $audit[$i]['page_status']);
            $this->assertSame($expected['result_state'], $audit[$i]['result_state']);
            $this->assertSame($originals[$i], file_get_contents($this->root.'/source/version-'.$i.'.html'));
            $this->assertSame(hash('sha256', $originals[$i]), $audit[$i]['original_sha256']);
        }
        foreach ($observations as $row) {
            $page = $audit[$row['import_id'] - 1];
            $this->assertSame($page['result_display_flag'], $row['result_display_flag']);
            $this->assertSame($page['result_state'], $row['result_state']);
            $this->assertSame($page['page_status'], $row['page_status']);
            $this->assertNull($row['start_acquired']);
            $this->assertSame('NOT_AUTHORIZED', $row['prediction_use']);
            $this->assertSame('STAT36-DISPLAY-SIGNATURE-v2-SORTED-OBJECT-KEYS', $row['display_signature_version']);
        }
        $contract = Files::json($this->root.'/result/contract.json');
        $this->assertSame('STAT36-OBSERVATION-v3-DISPLAY-ONLY', $contract['version']);
        $this->assertSame(Contract::PAGE_STATE_VERSION, $contract['page_state_version']);
        $this->assertSame([true, 1, '1'], $contract['result_display_flag']['display_values']);
        $this->assertSame([false, 0, '0'], $contract['result_display_flag']['unpublished_values']);
        $this->assertSame('NOT_AUTHORIZED', $contract['prediction_use']);
        $again = $builder->build($this->root.'/source', $this->root.'/again', $this->root.'/result');
        $this->assertSame($result['manifest'], $again['manifest']);
        Http::assertNothingSent();
    }

    public function test_native_flag_keeps_nested_objects_arrays_and_escaped_json_types(): void
    {
        $raw = (object) ['array' => [false, 0.0, (object) []], 'text' => 'quoted " value with } ] ; \\ chars'];
        $page = $this->parse(['tyakujyunDispFlg' => $raw, 'tyakujyunItemSubData' => Fixture::data()['rows']]);
        $this->assertSame('RESULT_DISPLAY_UNKNOWN', $page['page_status']);
        $this->assertSame('object', $page['result_display_flag']['type']);
        $this->assertSame(self::encode($raw), self::encode($page['result_display_flag']['raw']));
        $this->assertCount(5, $page['rows']);
    }

    public function test_malformed_pj0326_json_remains_fatal(): void
    {
        $fixture = Fixture::data();
        $html = str_replace('jsonData["PJ0326"]={}', 'jsonData["PJ0326"]={broken}', self::html([]));
        $this->expectException(ParserException::class);
        (new Parser)->parse($html, $fixture['race'], $fixture['entries'], $fixture['import']);
    }

    private function parse(array $page, string $ledgerStatus = 'RESULTS_AVAILABLE'): array
    {
        $fixture = Fixture::data();
        $fixture['import']['parsed_page_status'] = $ledgerStatus;

        return (new Parser)->parse(self::html($page), $fixture['race'], $fixture['entries'], $fixture['import']);
    }

    private static function input(bool $present, mixed $flag, string $resultState): array
    {
        $page = $present ? ['tyakujyunDispFlg' => $flag] : [];
        if ($resultState !== 'MISSING') {
            $page['tyakujyunItemSubData'] = match ($resultState) {
                'NULL' => null, 'EMPTY_ARRAY' => [], 'ROWS' => Fixture::data()['rows'],
            };
        }

        return $page;
    }

    private static function html(array $page): string
    {
        return str_replace(self::encode(['tyakujyunDispFlg' => true, 'tyakujyunItemSubData' => []]),
            self::encode((object) $page), Fixture::html([]));
    }

    private static function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function bundle(array $cases): array
    {
        $source = $this->root.'/source';
        Fixture::bundle($source, versions: count($cases));
        $database = iterator_to_array(Artifacts::lines($source.'/database-inventory.jsonl'));
        $raws = $originals = [];
        foreach ($cases as $i => [$input, $ledgerStatus]) {
            $name = 'version-'.$i.'.html';
            $originals[$i] = self::html($input);
            file_put_contents($source.'/'.$name, $originals[$i]);
            $seal = Files::identity($source.'/'.$name);
            $import = &$database[0]['imports'][$i];
            $import['absolute_path'] = $source.'/'.$name;
            $import['fetch_path'] = $import['raw_file_path'] = $name;
            $import['fetch_hash'] = $import['source_hash'] = $import['converted_hash'] = $seal['sha256'];
            $import['fetch_bytes'] = $import['raw_response_size'] = $seal['bytes'];
            $import['parsed_page_status'] = $ledgerStatus;
            $raws[] = $import + ['raw_sha256' => $seal['sha256'], 'raw_bytes' => $seal['bytes']];
            unset($import);
        }
        foreach (['database-inventory.jsonl' => $database, 'raw-source-inventory.jsonl' => $raws] as $name => $rows) {
            unlink($source.'/'.$name);
            unlink($source.'/'.$name.'.manifest.json');
            JsonlArtifact::write($source.'/'.$name, $rows);
        }

        return [new Builder(new Ledger(Fixture::seal($source)), new RawReader, new Parser), $originals];
    }
}
