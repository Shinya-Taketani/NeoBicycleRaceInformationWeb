<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\HistoryReader;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\SourceVerifier;
use App\Domain\Keirin\Scraping\Support\RaceCategoryPolicy;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use RuntimeException;
use Tests\TestCase;

class TacticalHistoryReaderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        Schema::create('races', function (Blueprint $t): void {
            $t->integer('id')->primary();
            $t->integer('race_day_id');
            foreach (['race_date', 'scheduled_start_at', 'race_type', 'result_status'] as $key) {
                $t->string($key)->nullable();
            }
        });
        Schema::create('race_days', function (Blueprint $t): void {
            $t->integer('id')->primary();
            $t->integer('race_meeting_id');
        });
        Schema::create('race_entries', function (Blueprint $t): void {
            $t->integer('id')->primary();
            foreach (['race_id', 'player_id', 'bike_number'] as $key) {
                $t->integer($key);
            }
            $t->string('external_player_id');
        });
        Schema::create('race_results', function (Blueprint $t): void {
            $t->integer('id')->primary();
            foreach (['race_id', 'bike_number', 'player_id', 'race_entry_id', 'rank', 'race_result_import_id'] as $key) {
                $t->integer($key)->nullable();
            }
            foreach (['result_status', 'winning_technique', 'raw_result_text', 'fetched_at'] as $key) {
                $t->text($key)->nullable();
            }
        });
        Schema::create('race_result_imports', function (Blueprint $t): void {
            $t->integer('id')->primary();
            $t->integer('race_id');
            $t->string('source_hash');
            $t->string('import_status');
        });
        DB::table('race_days')->insert([['id' => 1, 'race_meeting_id' => 1], ['id' => 2, 'race_meeting_id' => 100]]);
        foreach ([1, 2, 3] as $id) {
            DB::table('races')->insert(['id' => $id, 'race_day_id' => $id === 2 ? 2 : 1,
                'race_date' => $id === 3 ? '2024-06-10' : '2024-05-01', 'scheduled_start_at' => $id === 3 ? '2024-06-10T00:00:00+09:00' : '2024-05-01T11:00:00+09:00',
                'race_type' => "A\u{7d1a}", 'result_status' => 'CONFIRMED']);
            DB::table('race_entries')->insert(['id' => $id, 'race_id' => $id, 'player_id' => 9, 'bike_number' => 1, 'external_player_id' => '999999']);
            DB::table('race_results')->insert(['id' => $id, 'race_id' => $id, 'bike_number' => 1, 'rank' => 1, 'race_result_import_id' => $id,
                'result_status' => 'FINISHED', 'winning_technique' => "\u{9003}\u{3052}", 'raw_result_text' => json_encode(['syaban' => '1', 'sensyuRegistNo' => '999999', 'kimarite' => "\u{9003}\u{3052}"]), 'fetched_at' => '2025-01-01']);
            DB::table('race_result_imports')->insert(['id' => $id, 'race_id' => $id, 'source_hash' => str_repeat('a', 64), 'import_status' => 'SUCCEEDED']);
        }
    }

    public function test_local_identity_index_preserves_window_rows_without_reading_future_or_same_meeting_results(): void
    {
        $reader = new HistoryReader(new RaceCategoryPolicy);
        $expected = iterator_to_array($reader->rows(9, '2024-06-10', 100));
        $this->assertSame([1], array_column($expected, 'race_id'));
        $this->assertNull($expected[0]['result_player_id']);
        $this->assertTrue($expected[0]['formal_provenance_verified']);
        $index = new PDO('sqlite::memory:');
        $index->exec('CREATE TABLE history_entries (id INTEGER,player_id INTEGER,race_date TEXT)');
        $index->exec("INSERT INTO history_entries VALUES (1,9,'2024-05-01'),(2,9,'2024-05-01'),(3,9,'2024-06-10')");
        $reader->useEntryIndex($index);
        DB::table('race_results')->whereIn('id', [2, 3])->update(['raw_result_text' => '{invalid']);
        $this->assertSame($expected, iterator_to_array($reader->rows(9, '2024-06-10', 100)));
    }

    public function test_raw_identity_conflicts_fail_closed(): void
    {
        DB::table('race_results')->where('id', 1)->update(['raw_result_text' => json_encode(['syaban' => '2'])]);
        $this->expectException(RuntimeException::class);
        iterator_to_array((new HistoryReader(new RaceCategoryPolicy))->rows(9, '2024-06-10', 100));
    }

    public function test_changed_historical_result_is_rejected_by_fresh_source_verification(): void
    {
        $directory = sys_get_temp_dir().'/history-source-test-'.bin2hex(random_bytes(8));
        mkdir($directory);
        try {
            $reader = new HistoryReader(new RaceCategoryPolicy);
            $old = iterator_to_array($reader->rows(9, '2024-06-10', 100));
            $cache = new PDO('sqlite:'.$directory.'/history-cache.sqlite');
            $cache->exec('CREATE TABLE windows (cache_key TEXT PRIMARY KEY, audit TEXT)');
            $cache->prepare('INSERT INTO windows VALUES (?,?)')->execute(['9:100:2024-06-10', json_encode(['source_rows' => $old])]);
            $cache->exec('CREATE TABLE history_entries (id INTEGER,player_id INTEGER,race_date TEXT)');
            $cache->exec("INSERT INTO history_entries VALUES (1,9,'2024-05-01'),(2,9,'2024-05-01'),(3,9,'2024-06-10')");
            $cache = null;
            $hash = hash_init('sha256');
            foreach ([[1, 9, '2024-05-01'], [2, 9, '2024-05-01'], [3, 9, '2024-06-10']] as $row) {
                hash_update($hash, json_encode($row)."\n");
            }
            JsonlArtifact::json($directory.'/history-entry-index.json', ['rows' => 3, 'sha256' => hash_final($hash)]);
            JsonlArtifact::json($directory.'/manifest.json', ['history_cache_sha256' => hash_file('sha256', $directory.'/history-cache.sqlite')]);
            DB::table('race_results')->where('id', 1)->update(['winning_technique' => "\u{6372}\u{308a}",
                'raw_result_text' => json_encode(['syaban' => '1', 'sensyuRegistNo' => '999999', 'kimarite' => "\u{6372}\u{308a}"])]);
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('History source drifted');
            (new SourceVerifier($reader))->verify($directory);
        } finally {
            foreach (glob($directory.'/*') as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }
}
