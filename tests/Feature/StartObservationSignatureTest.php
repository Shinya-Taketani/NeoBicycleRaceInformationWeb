<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Keirin\Audit\Stat35DataReadiness\RawReader;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
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

class StartObservationSignatureTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/stat36-signature-'.bin2hex(random_bytes(8));
        mkdir($this->root);
        DB::shouldReceive('connection')->never();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_object_key_order_has_same_signature_without_rewriting_original_evidence(): void
    {
        [$first, $second] = self::orderOnlyRows();
        [, $rows] = $this->buildPair($first, $second);
        $this->assertSame(['kojinState', 'kojinStateClass'], array_keys($rows[0]['fields']['kojinStateItemSubData']['raw'][0]));
        $this->assertSame(['kojinStateClass', 'kojinState'], array_keys($rows[5]['fields']['kojinStateItemSubData']['raw'][0]));
        $this->assertNotSame($rows[0]['original_sha256'], $rows[5]['original_sha256']);
        $this->assertNotSame($rows[0]['converted_sha256'], $rows[5]['converted_sha256']);
        $this->assertSame($rows[0]['display_signature'], $rows[5]['display_signature']);
    }

    public function test_order_only_imports_are_retained_without_multiple_display_versions(): void
    {
        [$first, $second] = self::orderOnlyRows();
        [$result, $rows] = $this->buildPair($first, $second);
        $this->assertSame([1, 2], array_values(array_unique(array_column($rows, 'import_id'))));
        $this->assertSame(2, $result['years'][2024]['imports']);
        $this->assertSame(5, $result['years'][2024]['unique_matched_entries']);
        $this->assertSame(0, $result['years'][2024]['entries_with_multiple_display_versions']);
    }

    public static function changedValues(): array
    {
        $first = self::orderOnlyRows()[0];
        $cases = [];
        foreach (['kojinState' => '落車棄権', 'kojinStateClass' => 'changed'] as $key => $value) {
            $second = $first;
            $second['kojinStateItemSubData'][0][$key] = $value;
            $cases[$key] = [$first, $second];
        }
        foreach (['BH' => 'B', 'inLineJyuni' => '1'] as $key => $value) {
            $second = $first;
            $second[$key] = $value;
            $cases[$key] = [$first, $second];
        }
        $second = $first;
        $second['kojinStateItemSubData'][0]['kojinState'] = '失格 ';
        $cases['string whitespace'] = [$first, $second];
        $first['inLineJyuni'] = '1';
        $second = $first;
        $second['inLineJyuni'] = 1;
        $cases['scalar type'] = [$first, $second];
        $first['kojinStateItemSubData'][] = ['kojinState' => '落車棄権', 'kojinStateClass' => ''];
        $second = $first;
        $second['kojinStateItemSubData'] = array_reverse($first['kojinStateItemSubData']);
        $cases['list order'] = [$first, $second];

        return $cases;
    }

    #[DataProvider('changedValues')]
    public function test_real_display_changes_remain_distinct(array $first, array $second): void
    {
        [$result, $rows] = $this->buildPair($first, $second);
        $this->assertNotSame($rows[0]['display_signature'], $rows[5]['display_signature']);
        $this->assertSame(1, $result['years'][2024]['entries_with_multiple_display_versions']);
    }

    public static function missingAndEmptyValues(): iterable
    {
        $values = ['missing' => null, 'null' => null, 'blank' => '', 'empty array' => []];
        $keys = array_keys($values);
        foreach (['BH', 'inLineJyuni', 'kojinStateItemSubData', 'kojinState', 'kojinStateClass'] as $field) {
            for ($a = 0; $a < count($keys); $a++) {
                for ($b = $a + 1; $b < count($keys); $b++) {
                    $rows = [];
                    foreach ([$keys[$a], $keys[$b]] as $state) {
                        $row = self::orderOnlyRows()[0];
                        if (in_array($field, ['kojinState', 'kojinStateClass'], true)) {
                            if ($state === 'missing') {
                                unset($row['kojinStateItemSubData'][0][$field]);
                            } else {
                                $row['kojinStateItemSubData'][0][$field] = $values[$state];
                            }
                        } elseif ($state === 'missing') {
                            unset($row[$field]);
                        } else {
                            $row[$field] = $values[$state];
                        }
                        $rows[] = $row;
                    }
                    yield $field.' '.$keys[$a].' vs '.$keys[$b] => $rows;
                }
            }
        }
    }

    #[DataProvider('missingAndEmptyValues')]
    public function test_missing_null_blank_and_empty_array_are_not_coalesced(array $first, array $second): void
    {
        [$result, $rows] = $this->buildPair($first, $second);
        $this->assertNotSame($rows[0]['fields'], $rows[5]['fields']);
        $this->assertNotSame($rows[0]['display_signature'], $rows[5]['display_signature']);
        $this->assertSame(1, $result['years'][2024]['entries_with_multiple_display_versions']);
    }

    public function test_signature_copy_preserves_scalar_types_without_mutating_fields(): void
    {
        $hashes = [];
        foreach ([0, 0.0, false, '0', null, '', [], true] as $value) {
            $row = ['external_player_id' => '000001', 'fields' => [
                'inLineJyuni' => ['presence' => 'VALUE', 'raw' => $value, 'source_pointer' => 'original'],
            ]];
            $original = $row;
            $hashes[] = DisplaySignature::hash($row);
            $this->assertSame($original, $row);
        }
        $this->assertCount(8, array_unique($hashes));
    }

    public function test_signature_copy_sorts_nested_object_keys_without_turning_objects_into_lists(): void
    {
        $row = ['external_player_id' => '000001', 'fields' => [
            'BH' => ['presence' => 'VALUE', 'raw' => ['z' => ['b' => 2, 'a' => 1], 'a' => 'x']],
        ]];
        $reordered = $row;
        $reordered['fields']['BH']['raw'] = ['a' => 'x', 'z' => ['a' => 1, 'b' => 2]];
        $this->assertSame(DisplaySignature::hash($row), DisplaySignature::hash($reordered));
        $row['fields']['BH']['raw'] = [1 => 'second', 0 => 'first'];
        $reordered['fields']['BH']['raw'] = ['first', 'second'];
        $this->assertNotSame(DisplaySignature::hash($row), DisplaySignature::hash($reordered));
    }

    public function test_signature_v2_is_identified_in_contract_manifest_and_each_observation(): void
    {
        [$first, $second] = self::orderOnlyRows();
        [, $rows] = $this->buildPair($first, $second);
        $this->assertSame('STAT36-OBSERVATION-v2-DISPLAY-ONLY', Contract::VERSION);
        $this->assertSame(Contract::VERSION, Files::json($this->root.'/result/manifest.json')['version']);
        $this->assertSame(DisplaySignature::VERSION, Files::json($this->root.'/result/contract.json')['display_signature_version']);
        foreach ($rows as $row) {
            $this->assertSame(DisplaySignature::VERSION, $row['display_signature_version']);
            $this->assertSame(Contract::VERSION, $row['parser_version']);
        }
    }

    private static function orderOnlyRows(): array
    {
        $first = Fixture::data()['rows'][0];
        $first['kojinStateItemSubData'] = [['kojinState' => '失格', 'kojinStateClass' => '']];
        $second = $first;
        $second['kojinStateItemSubData'] = [['kojinStateClass' => '', 'kojinState' => '失格']];

        return [$first, $second];
    }

    private function buildPair(array $first, array $second): array
    {
        $source = $this->root.'/source';
        Fixture::bundle($source);
        $database = iterator_to_array(Artifacts::lines($source.'/database-inventory.jsonl'));
        $raws = [];
        $originals = $parsed = [];
        $fixture = Fixture::data();
        foreach ([$first, $second] as $i => $row) {
            $fixture['rows'][0] = $row;
            $name = 'version-'.$i.'.html';
            $originals[$i] = Fixture::html($fixture['rows']);
            file_put_contents($source.'/'.$name, $originals[$i]);
            $seal = Files::identity($source.'/'.$name);
            $import = &$database[0]['imports'][$i];
            $import['absolute_path'] = $source.'/'.$name;
            $import['fetch_path'] = $import['raw_file_path'] = $name;
            $import['fetch_hash'] = $import['source_hash'] = $import['converted_hash'] = $seal['sha256'];
            $import['fetch_bytes'] = $import['raw_response_size'] = $seal['bytes'];
            $raws[] = $import + ['raw_sha256' => $seal['sha256'], 'raw_bytes' => $seal['bytes']];
            $parsed[] = (new Parser)->parse($originals[$i], $fixture['race'], $fixture['entries'], $import)['rows'][0];
            unset($import);
        }
        foreach (['database-inventory.jsonl' => $database, 'raw-source-inventory.jsonl' => $raws] as $name => $rows) {
            unlink($source.'/'.$name);
            unlink($source.'/'.$name.'.manifest.json');
            JsonlArtifact::write($source.'/'.$name, $rows);
        }
        $builder = new Builder(new Ledger(Fixture::seal($source)), new RawReader, new Parser);
        $result = $builder->build($source, $this->root.'/result');
        $rows = iterator_to_array(Artifacts::lines($this->root.'/result/observations-2024.jsonl'));
        $this->assertCount(10, $rows);
        foreach ([0, 1] as $i) {
            $this->assertSame($originals[$i], file_get_contents($raws[$i]['absolute_path']));
            $this->assertSame($parsed[$i]['fields'], $rows[$i * 5]['fields']);
            $this->assertSame($raws[$i]['source_hash'], $rows[$i * 5]['original_sha256']);
            $this->assertSame($raws[$i]['converted_hash'], $rows[$i * 5]['converted_sha256']);
        }
        foreach ($rows as $row) {
            $this->assertNull($row['start_acquired']);
            $this->assertSame('UNKNOWN_POSITION_DEFINITION', $row['interpretation_status']);
        }
        Http::assertNothingSent();

        return [$result, $rows];
    }
}
