<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Keirin;

use App\Domain\Keirin\Scraping\DTO\StoredRawResponseDto;
use App\Domain\Keirin\Scraping\Enums\RaceResultStatus;
use App\Domain\Keirin\Scraping\Exceptions\RaceResultCompletenessException;
use App\Domain\Keirin\Scraping\Parsers\RaceLiveResultParser;
use App\Domain\Keirin\Scraping\Services\AgariBackfillService;
use App\Domain\Keirin\Scraping\Services\AgariObservationService;
use App\Domain\Keirin\Scraping\Services\BatchRunService;
use App\Domain\Keirin\Scraping\Services\RaceResultImportService;
use App\Models\BatchRun;
use App\Models\Race;
use App\Models\RaceEntry;
use App\Models\RacePayout;
use App\Models\RaceResult;
use App\Models\RaceResultAgariObservation;
use App\Models\RaceResultImport;
use App\Models\Racetrack;
use App\Models\ScrapingFetchLog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AgariStorageBackfillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['keirin.raw_disk' => 'local']);
        Http::preventStrayRequests();
    }

    public function test_plan_and_rejected_2026_do_not_open_database_or_raw(): void
    {
        DB::listen(fn () => $this->fail('Plan/invalid dates must not query DB.'));
        $this->artisan('keirin:stat35:backfill-agari', ['--plan' => true])->assertExitCode(0);
        $this->artisan('keirin:stat35:backfill-agari', ['--execute' => true, '--from' => '2026-01-01', '--to' => '2026-01-02'])->assertExitCode(1);
        $this->artisan('keirin:stat35:backfill-agari', ['--execute' => true, '--from' => '2025-01-01', '--to' => '2026-01-02'])->assertExitCode(1);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_dry_run_and_execute_preserve_versions_provenance_and_rerun_idempotency(): void
    {
        $race = $this->race();
        $a = $this->saved($race, $this->html('11.5'));
        $b = $this->saved($race, $this->html('11.3'));
        $this->current($race, $b);
        $this->race('2025-02-02');
        $original = $this->snapshot();
        $dry = $this->runBackfill(true);
        $this->assertSame(14, $dry['observations']);
        $this->assertSame(7, $dry['current_updates']);
        $this->assertSame(1, $dry['NO_IMPORT']);
        $this->assertSame($original, $this->snapshot());
        $this->assertDatabaseCount('batch_runs', 0);
        $this->assertDatabaseCount('batch_run_items', 0);
        $result = $this->runBackfill();
        $this->assertSame(0, $result['failed']);
        $this->assertSame(14, $result['observations']);
        $this->assertSame(7, $result['current_updates']);
        $this->assertSame(['11.5', '11.3'], RaceResultAgariObservation::query()->where('bike_number', 1)->orderBy('id')->pluck('agari_time_seconds')->all());
        $this->assertSame(array_fill(0, 7, '11.3'), RaceResult::query()->pluck('agari_time_seconds')->all());
        $this->assertSame(array_fill(0, 7, $b->id), RaceResult::query()->pluck('race_result_import_id')->all());
        $observation = RaceResultAgariObservation::query()->where('race_result_import_id', $a->id)->firstOrFail();
        $this->assertSame('BACKFILLED_FINAL_RESULT', $observation->metadata['origin']);
        $this->assertSame('UNKNOWN', $observation->metadata['publication_timestamp']);
        $this->assertSame('2025-08-01', $observation->fetched_at->format('Y-m-d'));
        $after = $this->snapshot();
        $again = $this->runBackfill();
        $this->assertSame(0, $again['observations']);
        $this->assertSame(0, $again['current_updates']);
        $this->assertSame($after, $this->snapshot());
        $this->assertSame(0, BatchRun::query()->where('status', 'RUNNING')->count());
        $this->assertSame($original['race_result_imports'], $after['race_result_imports']);
        $this->assertSame($original['scraping_fetch_logs'], $after['scraping_fetch_logs']);
        foreach ($after['race_results'] as $i => $row) {
            foreach (array_diff(array_keys($row), ['agari_raw_text', 'agari_time_seconds', 'agari_status']) as $key) {
                $this->assertSame($original['race_results'][$i][$key], $row[$key]);
            }
        }
    }

    public function test_current_source_is_not_replaced_by_a_newer_import_and_missing_log_timing_is_unknown(): void
    {
        $race = $this->race();
        $a = $this->saved($race, $this->html('11.5'));
        $a->update(['scraping_fetch_log_id' => null]);
        $this->saved($race, $this->html('11.3'));
        $this->current($race, $a);
        $this->assertSame(0, $this->runBackfill()['failed']);
        $this->assertSame(array_fill(0, 7, '11.5'), RaceResult::query()->pluck('agari_time_seconds')->all());
        $observation = RaceResultAgariObservation::query()->where('race_result_import_id', $a->id)->firstOrFail();
        $this->assertNull($observation->fetched_at);
        $this->assertSame('UNKNOWN', $observation->metadata['acquisition_timing']);
    }

    #[DataProvider('counts')]
    public function test_new_sync_retains_exact_values_and_corrected_observations(int $count): void
    {
        $race = $this->race(count: $count);
        $a = $this->sync($race, $this->html('11.12345678901234567890123456789', $count));
        $ids = RaceResult::query()->orderBy('id')->pluck('id')->all();
        $old = RaceResultAgariObservation::query()->orderBy('id')->get()->toArray();
        $this->assertSame(array_fill(0, $count, '11.12345678901234567890123456789'), RaceResult::query()->pluck('agari_time_seconds')->all());
        $b = $this->sync($race, $this->html('11.3', $count), RaceResultStatus::Corrected);
        $this->assertNotSame($a['import']->id, $b['import']->id);
        $this->assertSame($ids, RaceResult::query()->orderBy('id')->pluck('id')->all());
        $this->assertSame($old, RaceResultAgariObservation::query()->where('race_result_import_id', $a['import']->id)->orderBy('id')->get()->toArray());
        $this->assertSame($count * 2, RaceResultAgariObservation::query()->count());
        $this->assertSame(array_fill(0, $count, '11.3'), RaceResult::query()->pluck('agari_time_seconds')->all());
        $this->assertSame('CORRECTED', $race->refresh()->result_status);
        $this->assertSame(1, RacePayout::query()->count());
        $snapshot = $this->snapshot();
        $this->assertSame(0, $this->runBackfill()['observations']);
        $this->assertSame($snapshot, $this->snapshot());
    }

    public static function counts(): array
    {
        return [[5], [7], [8], [9]];
    }

    public function test_abnormal_blank_invalid_and_tied_values_are_persisted_as_observed(): void
    {
        $race = $this->race();
        $html = $this->html('11.5', modify: function (array &$context, array &$data): void {
            foreach (['11.50', '', '0', '-1', 'bad', '11.123', ''] as $i => $value) {
                $data['tyakujyunItemSubData'][$i]['agari'] = $value;
            }
            $data['tyakujyunItemSubData'][1]['tyaku'] = '1';
            foreach ([5, 6] as $i) {
                $data['tyakujyunItemSubData'][$i]['tyaku'] = '';
                $data['tyakujyunItemSubData'][$i]['kojinStateItemSubData'] = [['kojinState' => '失格']];
            }
        });
        $this->sync($race, $html);
        $this->assertSame(['VALID', 'MISSING', 'INVALID_FORMAT', 'INVALID_FORMAT', 'INVALID_FORMAT', 'OBSERVED_ABNORMAL_RESULT', 'MISSING'],
            RaceResult::query()->orderBy('bike_number')->pluck('agari_status')->all());
        $this->assertSame(['TIED', 'TIED'], RaceResult::query()->orderBy('bike_number')->limit(2)->pluck('result_status')->all());
        $this->assertSame('11.123', RaceResult::query()->where('bike_number', 6)->firstOrFail()->agari_time_seconds);
        $this->assertSame(0, $this->runBackfill()['failed']);
    }

    #[DataProvider('unavailable')]
    public function test_unavailable_and_cancelled_partial_never_create_observations(string $mode): void
    {
        $race = $this->race();
        $html = $this->html('', modify: function (array &$context, array &$data) use ($mode): void {
            if ($mode === 'CANCELLED') {
                $context['C0201data']['flgRaceCancel'] = true;
                $data['tyakujyunItemSubData'] = [['syaban' => '2', 'agari' => '']];
            } else {
                $context['C0201data']['C0201race'] = [['raceNo' => 1, 'flgRaceEnd' => false, 'rcvKekka' => '0']];
                $data['tyakujyunDispFlg'] = false;
                if ($mode === 'UNDER_REVIEW') {
                    $data['resultStatus'] = '審議中';
                }
            }
        });
        $parsed = app(RaceLiveResultParser::class)->parse($html);
        $this->assertSame($mode, $parsed->detectedStatus->value);
        $this->sync($race, $html, $parsed->detectedStatus);
        $this->assertSame(0, RaceResultAgariObservation::query()->count());
        $summary = $this->runBackfill();
        $this->assertSame(0, $summary['failed']);
        $this->assertSame(1, $summary['skipped']);
        $this->assertSame(0, RaceResultAgariObservation::query()->count());
        $this->assertSame(0, RaceResult::query()->count());
    }

    public static function unavailable(): array
    {
        return [['CANCELLED'], ['UNAVAILABLE'], ['UNDER_REVIEW']];
    }

    #[DataProvider('badResults')]
    public function test_partial_or_duplicate_sync_rolls_back_both_current_and_observations(string $mode): void
    {
        $race = $this->race();
        $this->sync($race, $this->html('11.5'));
        $before = $this->snapshot();
        $html = $this->html('11.3', modify: function (array &$context, array &$data) use ($mode): void {
            if ($mode === 'partial') {
                array_pop($data['tyakujyunItemSubData']);
            } else {
                $data['tyakujyunItemSubData'][6]['syaban'] = '1';
            }
        });
        try {
            $this->sync($race, $html, RaceResultStatus::Corrected);
            $this->fail('Incomplete results were accepted.');
        } catch (RaceResultCompletenessException) {
            foreach (['races', 'race_results', 'race_payouts', 'race_result_agari_observations'] as $table) {
                $this->assertSame($before[$table], $this->snapshot()[$table]);
            }
        }
        $this->assertSame('FAILED', RaceResultImport::query()->latest('id')->firstOrFail()->import_status);
    }

    public static function badResults(): array
    {
        return [['partial'], ['duplicate']];
    }

    public function test_failure_after_first_insert_rolls_back_observations_and_current(): void
    {
        $race = $this->race();
        $this->sync($race, $this->html('11.5'));
        $before = $this->snapshot();
        $real = app(AgariObservationService::class);
        $this->mock(AgariObservationService::class)->shouldReceive('record')->andReturnUsing(function ($import, $result, $entry) use ($real) {
            if ($result->bikeNumber === 2) {
                throw new RuntimeException('Synthetic failure after first write');
            }

            return $real->record($import, $result, $entry);
        });
        try {
            $this->sync($race, $this->html('11.3'), RaceResultStatus::Corrected);
            $this->fail('Expected injected failure');
        } catch (RuntimeException $error) {
            $this->assertSame('Synthetic failure after first write', $error->getMessage());
        }
        foreach (['races', 'race_results', 'race_payouts', 'race_result_agari_observations'] as $table) {
            $this->assertSame($before[$table], $this->snapshot()[$table]);
        }
    }

    #[DataProvider('corruptions')]
    public function test_bad_saved_raw_fails_visibly_without_data_changes(string $mode): void
    {
        $race = $this->race();
        $import = $this->saved($race, $this->html('11.5'));
        $this->current($race, $import);
        $path = Storage::disk('local')->path($import->raw_file_path);
        match ($mode) {
            'missing' => unlink($path),
            'hash' => file_put_contents($path, str_replace('11.5', '11.3', file_get_contents($path))),
            'size' => $import->update(['raw_response_size' => $import->raw_response_size + 1]),
            'converted' => $import->update(['converted_hash' => str_repeat('a', 64)]),
            'path' => $import->update(['raw_file_path' => '../escape.html']),
            'symlink' => $this->symlink($path),
            'identity' => $this->replaceRaw($import, $this->html('11.5', modify: function (array &$context): void {
                $context['C0201data']['selKaisai'] = '20250202';
            })),
        };
        $before = $this->snapshot();
        $result = $this->runBackfill();
        $this->assertSame(1, $result['failed']);
        $this->assertSame($before, $this->snapshot());
        $this->assertDatabaseHas('batch_run_items', ['item_type' => 'AGARI_IMPORT', 'status' => 'FAILED']);
        $this->assertSame(0, BatchRun::query()->where('status', 'RUNNING')->count());
    }

    public static function corruptions(): array
    {
        return array_map(fn ($value): array => [$value], ['missing', 'hash', 'size', 'converted', 'path', 'symlink', 'identity']);
    }

    public function test_observation_conflict_is_not_overwritten_and_current_conflict_rolls_back(): void
    {
        $race = $this->race();
        $import = $this->saved($race, $this->html('11.5'));
        $this->current($race, $import);
        $this->assertSame(0, $this->runBackfill()['failed']);
        $old = RaceResultAgariObservation::query()->get()->toArray();
        $this->replaceRaw($import, $this->html('11.3'));
        $this->assertSame(1, $this->runBackfill()['failed']);
        $this->assertSame($old, RaceResultAgariObservation::query()->get()->toArray());
        $new = $this->saved($race, $this->html('11.1'));
        RaceResult::query()->update(['race_result_import_id' => $new->id]);
        $this->assertSame(2, $this->runBackfill()['failed']);
        $this->assertSame($old, RaceResultAgariObservation::query()->get()->toArray());
        $this->assertSame(array_fill(0, 7, '11.5'), RaceResult::query()->pluck('agari_time_seconds')->all());
    }

    public function test_backfill_streams_multiple_chunks_and_continues_after_failure(): void
    {
        $race = $this->race();
        for ($i = 0; $i < 5; $i++) {
            $import = $this->saved($race, $this->html('11.5'));
            if ($i === 1) {
                Storage::disk('local')->delete($import->raw_file_path);
            }
        }
        $summary = $this->runBackfill(chunk: 2);
        $this->assertSame(4, $summary['success']);
        $this->assertSame(1, $summary['failed']);
        $this->assertSame(28, RaceResultAgariObservation::query()->count());
        $this->assertSame(4, RaceResultAgariObservation::query()->distinct()->count('race_result_import_id'));
        $this->assertSame(0, BatchRun::query()->where('status', 'RUNNING')->count());
    }

    public function test_append_only_observations_do_not_block_live_entry_deletion(): void
    {
        $race = $this->race();
        $entry = RaceEntry::query()->create(['race_id' => $race->id, 'bike_number' => 1, 'external_player_id' => '000001', 'fetched_at' => now()]);
        $import = $this->saved($race, $this->html('11.5'));
        $this->assertSame(0, $this->runBackfill()['failed']);
        $observation = RaceResultAgariObservation::query()->where('bike_number', 1)->firstOrFail();
        $this->assertSame($entry->id, $observation->race_entry_id);
        $entry->delete();
        $this->assertSame($entry->id, $observation->refresh()->race_entry_id);
        $this->assertSame(0, $this->runBackfill()['failed']);
        foreach (['update', 'delete', 'unique', 'race_fk', 'import_fk'] as $operation) {
            try {
                DB::transaction(function () use ($operation, $observation, $race, $import): void {
                    match ($operation) {
                        'update' => $observation->update(['agari_raw_text' => '11.3']),
                        'delete' => $observation->delete(),
                        'unique' => $observation->replicate()->save(),
                        'race_fk' => $race->delete(),
                        'import_fk' => $import->delete(),
                    };
                });
                $this->fail('Constraint was not enforced: '.$operation);
            } catch (QueryException) {
                $this->assertSame(7, RaceResultAgariObservation::query()->count());
            }
        }
    }

    public function test_outer_start_item_failure_finishes_batch(): void
    {
        $this->saved($this->race(), $this->html('11.5'));
        $this->partialMock(BatchRunService::class)->shouldReceive('startItem')->once()->andThrow(new RuntimeException('Synthetic startItem failure'));
        try {
            $this->runBackfill();
            $this->fail('Expected outer failure');
        } catch (RuntimeException $error) {
            $this->assertSame('Synthetic startItem failure', $error->getMessage());
        }
        $this->assertDatabaseHas('batch_runs', ['type' => 'STAT35_AGARI_BACKFILL', 'status' => 'FAILED']);
        $this->assertSame(0, BatchRun::query()->where('status', 'RUNNING')->count());
    }

    public function test_command_executes_and_reports_memory_and_no_import_without_network(): void
    {
        $this->saved($this->race(), $this->html('11.5'));
        $this->race('2025-02-02');
        $this->artisan('keirin:stat35:backfill-agari', ['--execute' => true, '--chunk' => '1'])
            ->expectsOutputToContain('"NO_IMPORT":1')->assertExitCode(0);
        $this->assertSame(7, RaceResultAgariObservation::query()->count());
        Http::assertNothingSent();
    }

    public function test_backfill_success_audit_failure_rolls_back_data_before_recording_failure(): void
    {
        $race = $this->race();
        $import = $this->saved($race, $this->html('11.5'));
        $this->current($race, $import);
        $before = $this->snapshot();
        $this->partialMock(BatchRunService::class)->shouldReceive('succeedItem')->once()->andThrow(new RuntimeException('Synthetic audit failure'));
        $summary = $this->runBackfill();
        $this->assertSame(1, $summary['failed']);
        $this->assertSame(0, $summary['success']);
        $this->assertSame(0, $summary['observations']);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, BatchRun::query()->where('status', 'RUNNING')->count());
    }

    public function test_raw_end_seal_drift_rolls_back_current_and_observations(): void
    {
        $race = $this->race();
        $import = $this->saved($race, $this->html('11.5'));
        $this->current($race, $import);
        $before = $this->snapshot();
        $real = app(AgariObservationService::class);
        $this->mock(AgariObservationService::class)->shouldReceive('record')->andReturnUsing(function ($import, $result, $entry, $backfilled, $dryRun) use ($real) {
            $observed = $real->record($import, $result, $entry, $backfilled, $dryRun);
            if ($result->bikeNumber === 7) {
                Storage::disk('local')->put($import->raw_file_path, $this->html('11.3'));
            }

            return $observed;
        });
        $this->assertSame(1, $this->runBackfill()['failed']);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_cp932_source_and_utf8_converted_hash_have_distinct_semantics(): void
    {
        $html = '<p>払戻なし</p><table><thead><tr><th>着</th><th>車番</th><th>選手名</th><th>上り</th></tr></thead><tbody id="pitbodyBs">';
        foreach (range(1, 7) as $bike) {
            $html .= "<tr><td>{$bike}</td><td>{$bike}</td><td>架空選手{$bike}</td><td>11.50</td></tr>";
        }
        $html .= '</tbody></table>';
        $bytes = mb_convert_encoding($html, 'CP932', 'UTF-8');
        $import = $this->saved($this->race(), $html);
        Storage::disk('local')->put($import->raw_file_path, $bytes);
        $import->update(['source_hash' => hash('sha256', $bytes), 'raw_response_size' => strlen($bytes), 'detected_encoding' => 'CP932']);
        ScrapingFetchLog::query()->whereKey($import->scraping_fetch_log_id)->update([
            'sha256' => hash('sha256', $bytes), 'response_size' => strlen($bytes), 'content_type' => 'text/html; charset=Shift_JIS',
        ]);
        $this->assertNotSame($import->source_hash, $import->converted_hash);
        $this->assertSame(0, $this->runBackfill()['failed']);
        $this->assertSame(array_fill(0, 7, '11.5'), RaceResultAgariObservation::query()->pluck('agari_time_seconds')->all());
        $this->assertSame($bytes, Storage::disk('local')->get($import->raw_file_path));
    }

    public function test_header_driven_manual_import_and_backfill_without_header(): void
    {
        $race = $this->race();
        foreach ([false, true] as $header) {
            $html = '<p>払戻なし</p><table>'.($header ? '<thead><tr><th>着</th><th>H/B</th><th>車番</th><th>選手名</th><th>上り</th></tr></thead>' : '').'<tbody id="pitbodyBs">';
            foreach (range(1, 7) as $bike) {
                $html .= "<tr><td>{$bike}</td><td></td><td>{$bike}</td><td>Synthetic {$bike}</td><td> 11.50 </td></tr>";
            }
            $html .= '</tbody></table>';
            $import = $this->saved($race, $html);
            $summary = $this->runBackfill();
            $this->assertSame(0, $summary['failed']);
            $this->assertSame(array_fill(0, 7, $header ? 'VALID' : 'MISSING'), RaceResultAgariObservation::query()->where('race_result_import_id', $import->id)->pluck('agari_status')->all());
            $rawPath = Storage::disk('local')->path($import->raw_file_path);
            $this->artisan('keirin:races:import-results', ['--race-id' => $race->id, '--raw-file' => $rawPath,
                '--source-url' => 'https://example.test/manual', '--result-status' => 'CONFIRMED'])->assertExitCode(0);
            $this->assertSame(array_fill(0, 7, $header ? '11.5' : null), RaceResult::query()->pluck('agari_time_seconds')->all());
        }
    }

    public function test_backfill_preserves_invalid_and_abnormal_values_without_normalizing_them_to_zero(): void
    {
        $race = $this->race();
        $this->saved($race, $this->html('11.5', modify: function (array &$context, array &$data): void {
            foreach (['0', '-1', 'unknown', '', '11.500', '11.3', ''] as $i => $agari) {
                $data['tyakujyunItemSubData'][$i]['agari'] = $agari;
                if ($i >= 5) {
                    $data['tyakujyunItemSubData'][$i]['tyaku'] = '';
                    $data['tyakujyunItemSubData'][$i]['kojinStateItemSubData'] = [['kojinState' => '失格']];
                }
            }
        }));
        $this->assertSame(0, $this->runBackfill()['failed']);
        $this->assertSame([null, null, null, null, '11.5', '11.3', null], RaceResultAgariObservation::query()->orderBy('bike_number')->pluck('agari_time_seconds')->all());
        $this->assertSame(['INVALID_FORMAT', 'INVALID_FORMAT', 'INVALID_FORMAT', 'MISSING', 'VALID', 'OBSERVED_ABNORMAL_RESULT', 'MISSING'], RaceResultAgariObservation::query()->orderBy('bike_number')->pluck('agari_status')->all());
    }

    public function test_cancelled_empty_rows_and_conflicting_numeric_rows_are_not_observations(): void
    {
        $race = $this->race();
        foreach ([[], [['syaban' => '1', 'agari' => '11.5']]] as $rows) {
            $this->saved($race, $this->html('', modify: function (array &$context, array &$data) use ($rows): void {
                $context['C0201data']['flgRaceCancel'] = true;
                $data['tyakujyunItemSubData'] = $rows;
            }));
        }
        $summary = $this->runBackfill();
        $this->assertSame(1, $summary['skipped']);
        $this->assertSame(1, $summary['failed']);
        $this->assertSame(0, RaceResultAgariObservation::query()->count());
    }

    public function test_cancelled_sync_preserves_earlier_observations_and_down_refuses_to_remove_history(): void
    {
        $race = $this->race();
        $this->sync($race, $this->html('11.5'), RaceResultStatus::Provisional);
        $old = RaceResultAgariObservation::query()->get()->toArray();
        $this->sync($race, $this->html('', modify: function (array &$context, array &$data): void {
            $context['C0201data']['flgRaceCancel'] = true;
            $data['tyakujyunItemSubData'] = [];
        }), RaceResultStatus::Cancelled);
        $this->assertSame(0, RaceResult::query()->count());
        $this->assertSame($old, RaceResultAgariObservation::query()->get()->toArray());
        $migration = require database_path('migrations/2026_09_21_000013_add_agari_storage.php');
        try {
            $migration->down();
            $this->fail('Historical observations must protect against down.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('Cannot remove agari storage', $error->getMessage());
        }
        $this->assertSame($old, RaceResultAgariObservation::query()->get()->toArray());
    }

    public function test_partial_historical_results_fail_even_with_no_current_results(): void
    {
        $race = $this->race();
        $this->saved($race, $this->html('11.5', 5));
        $this->assertSame(1, $this->runBackfill()['failed']);
        $this->assertSame(0, RaceResultAgariObservation::query()->count());
    }

    public function test_unrelated_2026_and_unsupported_category_raw_are_not_opened(): void
    {
        $race = $this->race();
        $race->update(['race_type' => 'L級ガールズ']);
        $import = $this->saved($race, $this->html('11.5'));
        Storage::disk('local')->delete($import->raw_file_path);
        $future = $this->saved($this->race('2026-01-01'), 'invalid synthetic future raw');
        Storage::disk('local')->delete($future->raw_file_path);
        $summary = $this->runBackfill();
        $this->assertSame(1, $summary['skipped']);
        $this->assertSame(0, $summary['failed']);
        $this->assertSame(0, RaceResultAgariObservation::query()->count());
    }

    private function race(string $date = '2025-02-01', int $count = 7): Race
    {
        $track = Racetrack::query()->firstOrCreate(['source' => config('keirin.source'), 'external_track_id' => '56'], ['name' => 'Synthetic']);

        return Race::query()->create(['source' => config('keirin.source'), 'external_race_id' => '56:'.$date.':1',
            'racetrack_id' => $track->id, 'race_date' => $date, 'race_number' => 1, 'race_type' => 'S級予選',
            'entrant_count' => $count, 'result_status' => 'UNAVAILABLE']);
    }

    private function html(string $agari, int $count = 7, ?callable $modify = null): string
    {
        $context = ['C0201data' => ['selKaisai' => '20250201', 'selKjyoCd' => '56', 'selRaceNo' => 1,
            'flgRaceCancel' => false, 'flgSectionCancel' => false,
            'C0201race' => [['raceNo' => 1, 'flgRaceEnd' => true, 'rcvKekka' => '1', 'rcvRefund' => '1']]]];
        $rows = [];
        foreach (range(1, $count) as $bike) {
            $rows[] = ['tyaku' => (string) $bike, 'syaban' => (string) $bike, 'sensyuName' => 'Synthetic '.$bike,
                'sensyuRegistNo' => sprintf('%06d', $bike), 'agari' => $agari, 'kojinStateItemSubData' => []];
        }
        $data = ['resultCd' => 0, 'tyakujyunDispFlg' => true, 'haraiGakuDispFlg' => true, 'tyakujyunItemSubData' => $rows,
            'haraiGakuSubData' => ['ST2HaraiGakuDispItemSubData' => [['kumiBan' => '1-2', 'haraiGaku' => '500']]]];
        if ($modify !== null) {
            $modify($context, $data);
        }

        return '<html><script>jsonData["PC0201"] = '.json_encode($context, JSON_THROW_ON_ERROR).'; jsonData["PJ0326"] = '.json_encode($data, JSON_THROW_ON_ERROR).';</script></html>';
    }

    private function saved(Race $race, string $html): RaceResultImport
    {
        $path = 'synthetic/agari-'.bin2hex(random_bytes(8)).'.html';
        Storage::disk('local')->put($path, $html);
        $log = ScrapingFetchLog::query()->create(['source' => config('keirin.source'), 'request_method' => 'POST',
            'request_url' => 'https://example.test/PJ0326', 'request_key' => $path, 'http_status' => 200,
            'fetched_at' => '2025-08-01 12:34:56+09:00', 'content_type' => 'text/html; charset=UTF-8',
            'response_size' => strlen($html), 'sha256' => hash('sha256', $html), 'raw_file_path' => $path, 'parser_version' => 'synthetic-v1']);

        return RaceResultImport::query()->create(['race_id' => $race->id, 'scraping_fetch_log_id' => $log->id,
            'source_url' => $log->request_url, 'source_hash' => $log->sha256, 'raw_file_path' => $path,
            'raw_response_size' => strlen($html), 'converted_hash' => $log->sha256, 'detected_encoding' => 'UTF-8',
            'utf8_conversion_succeeded' => true, 'parser_version' => 'synthetic-v1', 'import_status' => 'SUCCEEDED',
            'requested_result_status' => 'CONFIRMED', 'parsed_page_status' => 'RESULTS_AVAILABLE', 'result_count' => $race->entrant_count]);
    }

    private function current(Race $race, RaceResultImport $import): void
    {
        foreach (range(1, $race->entrant_count) as $bike) {
            RaceResult::query()->create(['race_id' => $race->id, 'race_result_import_id' => $import->id,
                'bike_number' => $bike, 'rank' => $bike, 'result_status' => 'FINISHED', 'raw_result_text' => 'preserved',
                'source_url' => $import->source_url, 'fetched_at' => '2025-08-02 01:02:03']);
        }
    }

    private function sync(Race $race, string $html, RaceResultStatus $status = RaceResultStatus::Confirmed): array
    {
        $source = $this->saved($race, $html);
        $batches = app(BatchRunService::class);
        $run = $batches->start('SYNTHETIC_RESULT');
        $item = $batches->startItem($run, 'RESULT', 'race:'.$race->id);
        $raw = new StoredRawResponseDto($source->raw_file_path, $source->source_hash, strlen($html), 'UTF-8', true, $html, $source->scraping_fetch_log_id);
        // Only the import created by the real service is part of this synthetic sync.
        $source->delete();

        return app(RaceResultImportService::class)->importStoredResponse($race, $run, $item, $raw,
            app(RaceLiveResultParser::class)->parse($html)->resultPage, $source->source_url, $status);
    }

    private function runBackfill(bool $dry = false, int $chunk = 2): array
    {
        return app(AgariBackfillService::class)->run('2022-01-01', '2025-12-31', $chunk, $dry);
    }

    private function snapshot(): array
    {
        $tables = ['races', 'race_entries', 'race_results', 'race_payouts', 'race_result_imports', 'race_result_agari_observations', 'scraping_fetch_logs'];

        return array_combine($tables, array_map(fn (string $table): array => DB::table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all(), $tables));
    }

    private function symlink(string $path): void
    {
        rename($path, $path.'.target');
        symlink($path.'.target', $path);
    }

    private function replaceRaw(RaceResultImport $import, string $html): void
    {
        Storage::disk('local')->put($import->raw_file_path, $html);
        $import->update(['source_hash' => hash('sha256', $html), 'converted_hash' => hash('sha256', $html), 'raw_response_size' => strlen($html)]);
        ScrapingFetchLog::query()->whereKey($import->scraping_fetch_log_id)->update(['sha256' => $import->source_hash, 'response_size' => strlen($html)]);
    }
}
