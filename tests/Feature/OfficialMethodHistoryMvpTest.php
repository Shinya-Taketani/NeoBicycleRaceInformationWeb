<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Keirin\Scraping\DTO\StoredRawResponseDto;
use App\Domain\Keirin\Scraping\Enums\RaceResultStatus;
use App\Domain\Keirin\Scraping\Parsers\EmbeddedJsonExtractor;
use App\Domain\Keirin\Scraping\Parsers\RaceLiveResultParser;
use App\Domain\Keirin\Scraping\Services\RaceResultImportService;
use App\Domain\Keirin\Scraping\Support\CharacterEncodingConverter;
use App\Domain\Keirin\Statistics\Calculators\Stat19OfficialMethodHistoryCalculator;
use App\Domain\Keirin\Statistics\Calculators\Stat30OfficialMethodMixCalculator;
use App\Domain\Keirin\Statistics\OfficialMethodHistory\Reader;
use App\Domain\Keirin\Statistics\OfficialMethodHistory\WindowBuilder;
use App\Domain\Keirin\Statistics\Repositories\OfficialMethodHistoryRepository;
use App\Domain\Keirin\Statistics\Services\OfficialMethodHistoryBuildService;
use App\Models\BatchRun;
use App\Models\BatchRunItem;
use App\Models\Player;
use App\Models\Race;
use App\Models\RaceDay;
use App\Models\RaceEntry;
use App\Models\RaceMeeting;
use App\Models\RaceResult;
use App\Models\RaceResultImport;
use App\Models\Racetrack;
use App\Models\ScrapingFetchLog;
use App\Models\StatisticFeatureResult;
use DateTimeImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\CompositionResultTemporaryDirectory;
use Tests\TestCase;
use Throwable;

final class OfficialMethodHistoryMvpTest extends TestCase
{
    use RefreshDatabase;

    private ?CompositionResultTemporaryDirectory $temporary = null;

    public function createApplication(): Application
    {
        $this->temporary = CompositionResultTemporaryDirectory::create();
        $root = $this->temporary->path();
        try {
            foreach (['bootstrap', 'bootstrap/cache', 'runtime', 'raw'] as $dir) {
                if (! mkdir($root.'/'.$dir, 0700)) {
                    throw new RuntimeException('Cannot create isolated official method directory.');
                }
            }
            $this->writeNew($root.'/.env', "# Isolated official method history test. No application secrets.\n");
            $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
            $this->traitsUsedByTest = class_uses_recursive(self::class);
            $app->useEnvironmentPath($root);
            $app->useBootstrapPath($root.'/bootstrap');
            $app->useStoragePath($root.'/runtime');
            $app->make(Kernel::class)->bootstrap();
            $app['config']->set(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
                'database.connections.sqlite.url' => null, 'logging.default' => 'null', 'keirin.raw_disk' => 'official_test',
                'filesystems.disks.official_test' => ['driver' => 'local', 'root' => $root.'/raw', 'throw' => true]]);
            $app->bind(Factory::class, fn () => throw new RuntimeException('HTTP forbidden in official method tests.'));

            return $app;
        } catch (Throwable $error) {
            $this->temporary->retire($this->temporary->path(), 'TEST_FAILURE');
            $this->temporary = null;
            throw $error;
        }
    }

    protected function tearDown(): void
    {
        $reason = $this->status()->isSuccess() ? 'TEST_COMPLETE' : 'TEST_FAILURE';
        try {
            parent::tearDown();
        } finally {
            $this->temporary?->retire($this->temporary->path(), $reason);
        }
    }

    public function test_actual_four_year_raw_verifies_null_db_ids_and_twelve_top2_methods(): void
    {
        $counts = ['ESCAPE' => 0, 'MAKURI' => 0, 'SASHI' => 0, 'MARK' => 0];
        $years = $states = $examples = [];
        $entries = $missing = 0;
        foreach ($this->cases() as $case) {
            [$race] = $this->seedSaved($case);
            $years[$race->race_date->format('Y')] = true;
            foreach (RaceEntry::query()->where('race_id', $race->id)->orderBy('bike_number')->get() as $entry) {
                $out = app(Reader::class)->read($race, $entry);
                $this->assertTrue($out['evidence_verified']);
                $this->assertNull($out['identity_evidence']['stored_entry_id']);
                $this->assertNull($out['identity_evidence']['stored_player_id']);
                $this->assertSame($entry->id, $out['identity_evidence']['matched_entry_id']);
                $this->assertSame($case['original']['sha256'], $out['raw_sha256']);
                $this->assertSame($case['original']['bytes'], $out['raw_bytes']);
                $this->assertSame('STARTED', $out['participation']);
                $this->assertSame('2026', substr($out['fetched_at'], 0, 4));
                $this->assertSame('DEVELOPMENT_RETROSPECTIVE', $out['data_role']);
                if ($out['method'] !== null) {
                    $counts[$out['method']]++;
                    $this->assertContains($out['rank'], [1, 2]);
                    if ($out['method'] === 'MARK') {
                        $this->assertSame(2, $out['rank']);
                    }
                } else {
                    $this->assertFalse($out['method_missing']);
                    $missing++;
                }
                $states[$out['result_status']] = ($states[$out['result_status']] ?? 0) + 1;
                $examples[] = ['race_id' => $race->id, 'entry_id' => $entry->id, 'bike' => $entry->bike_number,
                    'method' => $out['method'], 'result_status' => $out['result_status'], 'raw_sha256' => $out['raw_sha256']];
                $entries++;
            }
        }
        $this->assertCount(4, $years);
        $this->assertSame(39, $entries);
        $this->assertSame(['ESCAPE' => 2, 'MAKURI' => 4, 'SASHI' => 3, 'MARK' => 3], $counts);
        $this->assertSame(27, $missing);
        $this->assertSame(2, $states['CRASHED']);
        $this->assertSame(1, $states['DISQUALIFIED']);
        $out = getenv('OFFICIAL_METHOD_REPORT_DIRECTORY');
        if (is_string($out) && $out !== '' && realpath($out) === $out && is_dir($out)) {
            $this->writeNew($out.'/real-result-acceptance.json', json_encode(['races' => 6, 'entries' => $entries,
                'counts' => $counts, 'non_top2_or_abnormal_without_method' => $missing, 'states' => $states,
                'examples' => $examples], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        }
    }

    public function test_real_history_is_limited_to_prior_meetings_and_insufficient_twenty_is_null(): void
    {
        $this->seedAll();
        $profile = $this->playerProfile(12542, '014236', '2025-01-01');
        $this->assertSame(2, $profile->audit['verified_started']);
        $this->assertSame([37828, 37819], $profile->windows['LAST_3']['race_ids']);
        $this->assertSame(['ESCAPE' => 0, 'MAKURI' => 0, 'SASHI' => 0, 'MARK' => 0], $profile->windows['LAST_3']['observed_method_counts']);
        $this->assertNull($profile->windows['LAST_3']['method_counts']);
        $this->assertContains('INSUFFICIENT_SAMPLE', $profile->windows['LAST_20']['reasons']);
        $this->assertNull(app(Stat30OfficialMethodMixCalculator::class)->calculate($profile)->features['RETROSPECTIVE']['l1_distance']);
        $this->assertFalse($profile->audit['historical_as_of_available']);
        $this->assertSame(2, $profile->audit['fetched_at_or_after_input']);
        $same = $this->playerProfile(37828, '014236', '2024-01-01');
        $this->assertSame(0, $same->audit['history_rows']);
        $out = getenv('OFFICIAL_METHOD_REPORT_DIRECTORY');
        if (is_string($out) && $out !== '' && realpath($out) === $out && is_dir($out)) {
            $this->writeNew($out.'/real-history-acceptance.json', json_encode([
                'stat19' => app(Stat19OfficialMethodHistoryCalculator::class)->calculate($profile)->features,
                'stat30' => app(Stat30OfficialMethodMixCalculator::class)->calculate($profile)->features,
                'audit' => $profile->audit, 'same_meeting_excluded_rows' => $same->audit['history_rows']], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        }
    }

    public function test_plan_and_execute_dry_run_have_zero_writes(): void
    {
        $this->seedAll();
        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|create|alter|drop|replace)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        foreach ([[], ['--execute' => true, '--dry-run' => true]] as $mode) {
            $this->artisan('keirin:statistics:build-official-method-history', $mode + [
                '--race-id' => '12542', '--input-as-of' => '2025-01-01T00:00:00+09:00'])->assertExitCode(0);
        }
        $this->assertSame([], $writes);
        $this->assertSame(0, StatisticFeatureResult::query()->count());
        $this->assertSame(0, DB::table('statistic_feature_runs')->count());
    }

    public function test_abnormal_historical_meeting_does_not_become_a_complete_window(): void
    {
        $this->seedAll();
        RaceMeeting::query()->whereKey(1399)->update(['starts_on' => '2024-01-02']); // Invalid metadata control only.
        $profile = $this->playerProfile(12542, '014236', '2025-01-01');
        $this->assertSame(2, $profile->audit['verified_started']);
        $this->assertContains('MEETING_CONTEXT_UNVERIFIED', $profile->windows['LAST_3']['reasons']);
        $this->assertNull($profile->windows['LAST_3']['method_counts']);
    }

    public function test_execute_saves_distinct_versions_using_existing_statistic_tables_only(): void
    {
        $this->seedAll();
        $before = $this->businessState();
        $this->artisan('keirin:statistics:build-official-method-history', ['--race-id' => '12542',
            '--input-as-of' => '2025-01-01T00:00:00+09:00', '--execute' => true])->assertExitCode(0);
        $this->assertSame($before, $this->businessState());
        $this->assertSame(2, DB::table('statistic_feature_runs')->count());
        $this->assertSame(2, DB::table('statistic_feature_run_items')->count());
        $this->assertSame(10, StatisticFeatureResult::query()->count());
        foreach (StatisticFeatureResult::all() as $result) {
            $this->assertContains($result->stat_code, ['STAT-19', 'STAT-30']);
            $this->assertSame($result->stat_code === 'STAT-19' ? Stat19OfficialMethodHistoryCalculator::CALCULATION_VERSION
                : Stat30OfficialMethodMixCalculator::CALCULATION_VERSION, $result->calculation_version);
            $this->assertNull($result->raw_points);
            $this->assertNull($result->confidence);
            $this->assertNull($result->effective_points);
            $this->assertFalse($result->evidence['historical_as_of_available']);
            $this->assertNull($result->evidence['official_publication_at']);
            $this->assertSame('NOT_AUTHORIZED', $result->features['prediction_use']);
        }
        $this->assertSame(0, DB::table('statistic_feature_results')->whereNotIn('stat_code', ['STAT-19', 'STAT-30'])->count());
    }

    public function test_abnormal_id_registration_result_and_method_mutations_are_rejected(): void
    {
        [$race, , $import] = $this->seedSaved($this->cases()[0]);
        $entry = RaceEntry::query()->where('race_id', $race->id)->where('bike_number', 1)->sole();
        $result = RaceResult::query()->where('race_id', $race->id)->where('bike_number', 1)->sole();
        $original = $result->getAttributes();
        $other = RaceEntry::query()->where('race_id', $race->id)->where('id', '!=', $entry->id)->firstOrFail();
        foreach (['race_entry_id' => $other->id, 'player_id' => $other->player_id,
            'rank' => 2, 'result_status' => 'TIED', 'winning_technique' => '逃げ'] as $field => $bad) {
            $result->timestamps = false;
            $result->forceFill([$field => $bad])->save();
            $this->reject(fn () => app(Reader::class)->read($race, $entry));
            $result->forceFill($original)->save();
        }
        $changed = clone $entry;
        $changed->external_player_id = '999999';
        $this->reject(fn () => app(Reader::class)->read($race, $changed));
        RaceEntry::query()->where('race_id', $race->id)->where('id', '!=', $entry->id)->firstOrFail()
            ->update(['external_player_id' => $entry->external_player_id]);
        $this->reject(fn () => app(Reader::class)->read($race, $entry));
        $this->assertSame(0, StatisticFeatureResult::query()->count());
    }

    public function test_source_hash_size_import_and_acquisition_chronology_mismatches_fail_closed(): void
    {
        [$race, $log, $import] = $this->seedSaved($this->cases()[0]);
        [$otherRace] = $this->seedSaved($this->cases()[1]);
        $entry = RaceEntry::query()->where('race_id', $race->id)->firstOrFail();
        $original = $import->getAttributes();
        foreach (['source_hash' => str_repeat('0', 64), 'raw_response_size' => 1, 'race_id' => $otherRace->id,
            'raw_file_path' => 'missing.html', 'source_url' => 'https://invalid.test/',
            'imported_at' => '2022-01-01 00:00:00', 'scraping_fetch_log_id' => null] as $field => $bad) {
            $import->timestamps = false;
            $import->forceFill([$field => $bad])->save();
            if ($bad === null) {
                $this->assertFalse(app(Reader::class)->read($race, $entry)['evidence_verified']);
            } else {
                $this->reject(fn () => app(Reader::class)->read($race, $entry));
            }
            $import->forceFill($original)->save();
        }
        $log->timestamps = false;
        $log->request_parameters = ['disp' => 'PJ0315'];
        $log->save();
        $this->reject(fn () => app(Reader::class)->read($race, $entry));
    }

    public function test_missing_raw_and_one_byte_drift_are_detected_without_modifying_original(): void
    {
        [$race, $log, $import] = $this->seedSaved($this->cases()[0]);
        $entry = RaceEntry::query()->where('race_id', $race->id)->firstOrFail();
        $path = $log->raw_file_path;
        foreach ([$log, $import] as $model) {
            $model->timestamps = false;
            $model->raw_file_path = 'not-present.html';
            $model->save();
        }
        $this->reject(fn () => app(Reader::class)->read($race, $entry));
        foreach ([$log, $import] as $model) {
            $model->raw_file_path = $path;
            $model->save();
        }
        $stream = fopen($this->temporary->path().'/raw/'.$path, 'r+b');
        fwrite($stream, '!');
        fclose($stream);
        $this->reject(fn () => app(Reader::class)->read($race, $entry));
    }

    public function test_end_raw_drift_blocks_both_statistics_before_publication(): void
    {
        $this->seedAll();
        $real = app(Reader::class);
        $calls = 0;
        $this->mock(Reader::class)->shouldReceive('read')->andReturnUsing(function ($race, $entry) use ($real, &$calls) {
            $out = $real->read($race, $entry);
            if (++$calls === 2) {
                $log = ScrapingFetchLog::query()->where('id', $out['fetch_log_id'])->sole();
                $stream = fopen($this->temporary->path().'/raw/'.$log->raw_file_path, 'r+b');
                fwrite($stream, '!');
                fclose($stream);
            }

            return $out;
        });
        $this->reject(fn () => app(OfficialMethodHistoryBuildService::class)->build(12542, new DateTimeImmutable('2025-01-01T00:00:00+09:00'), false));
        $this->assertSame(0, DB::table('statistic_feature_runs')->count());
        $this->assertSame(0, StatisticFeatureResult::query()->count());
    }

    public function test_artificial_forbidden_target_year_is_rejected_before_history_access(): void
    {
        [$race] = $this->seedSaved($this->cases()[0]);
        $race->update(['race_date' => '2026-01-01']); // Invalid fixture mutation, not a real 2026 race.
        $this->expectException(ModelNotFoundException::class);
        iterator_to_array(app(OfficialMethodHistoryRepository::class)->inputs($race->id, new DateTimeImmutable('2026-01-01T00:00:00+09:00')));
    }

    public function test_pr119_provisional_under_review_and_unknown_raw_never_confirm_db_results(): void
    {
        $case = $this->cases()[0];
        [$race, $log, $import] = $this->seedSaved($case);
        $entry = RaceEntry::query()->where('race_id', $race->id)->where('bike_number', 1)->sole();
        foreach (['PROVISIONAL', 'UNDER_REVIEW', 'UNKNOWN'] as $status) {
            $page = $this->statusControl($case, $log, $import, $status);
            $this->assertSame($status === 'UNKNOWN' ? null : $status, $page->detectedStatus?->value);
            if ($status === 'PROVISIONAL') {
                $this->assertTrue($page->resultPage->resultParsingComplete);
                $this->assertNotEmpty($page->resultPage->results);
            }
            $out = app(Reader::class)->read($race, $entry);
            $this->assertFalse($out['evidence_verified']);
            $this->assertSame('UNKNOWN', $out['participation']);
            $this->assertNull($out['method']);
            $this->assertContains('RESULT_FINALITY_UNVERIFIED', $out['issues']);
        }
    }

    public function test_pr119_import_finality_metadata_must_match_the_saved_race(): void
    {
        [$race, , $import] = $this->seedSaved($this->cases()[0]);
        $entry = RaceEntry::query()->where('race_id', $race->id)->firstOrFail();
        $original = $import->getAttributes();
        foreach ([['requested_result_status' => 'PROVISIONAL'], ['parsed_page_status' => 'UNDER_REVIEW'],
            ['requested_result_status' => 'CORRECTED']] as $change) {
            $import->timestamps = false;
            $import->forceFill($change)->save();
            $out = app(Reader::class)->read($race, $entry);
            $this->assertFalse($out['evidence_verified']);
            $this->assertContains('RESULT_FINALITY_UNVERIFIED', $out['issues']);
            $import->forceFill($original)->save();
        }
    }

    public function test_pr119_legitimate_corrected_import_accepts_confirmed_or_corrected_raw(): void
    {
        $case = $this->cases()[0];
        [$race, $log, $import] = $this->seedSaved($case);
        $entry = RaceEntry::query()->where('race_id', $race->id)->where('bike_number', 1)->sole();
        // Artificial correction boundary: the existing importer allows RESULTS_AVAILABLE + explicit CORRECTED.
        $race->update(['result_status' => 'CORRECTED']);
        $import->timestamps = false;
        $import->requested_result_status = 'CORRECTED';
        $import->save();
        foreach (['CONFIRMED', 'CORRECTED'] as $status) {
            $page = $this->statusControl($case, $log, $import, $status);
            $this->assertSame($status, $page->detectedStatus->value);
            $out = app(Reader::class)->read($race->fresh(), $entry);
            $this->assertTrue($out['evidence_verified']);
            $this->assertSame('SASHI', $out['method']);
            $this->assertSame($status, $out['raw_detected_status']);
            $this->assertSame('CORRECTED', $out['requested_result_status']);
        }
    }

    public function test_pr119_cancelled_raw_with_zero_result_rows_and_null_schedule_is_verified(): void
    {
        $case = $this->cases()[0];
        [$race, $log, $import] = $this->seedSaved($case);
        $cancel = $this->cancelControl($case, $race, $log, $import);
        $race->update(['scheduled_start_at' => null]);
        $this->assertSame(0, RaceResult::query()->where('race_id', $race->id)->count());
        $this->assertSame('SUCCEEDED', $cancel->import_status);
        $this->assertSame('CANCELLED', $cancel->requested_result_status);
        $this->assertSame('CANCELLED', $cancel->parsed_page_status);
        $entry = RaceEntry::query()->where('race_id', $race->id)->firstOrFail();
        $out = app(Reader::class)->read($race->fresh(), $entry);
        $this->assertTrue($out['evidence_verified']);
        $this->assertSame('CANCELLED', $out['participation']);
        $this->assertSame($cancel->id, $out['import_id']);
        $this->assertSame($log->sha256, $out['raw_sha256']);
        $this->assertNotNull($out['fetched_at']);
        $this->assertNotNull($out['saved_at']);
    }

    public function test_pr119_db_only_cancelled_and_contradictory_cancel_raw_remain_unknown(): void
    {
        $case = $this->cases()[0];
        [$race, $log, $import] = $this->seedSaved($case);
        $entry = RaceEntry::query()->where('race_id', $race->id)->firstOrFail();
        $race->update(['result_status' => 'CANCELLED', 'scheduled_start_at' => null]);
        $out = app(Reader::class)->read($race->fresh(), $entry);
        $this->assertSame('UNKNOWN', $out['participation']);
        $this->assertFalse($out['evidence_verified']);
        $this->assertContains('CANCELLED_IMPORT_UNVERIFIED', $out['issues']);
        $cancel = $this->cancelControl($case, $race, $log, $import);
        $this->statusControl($case, $log, $cancel, 'CONFIRMED');
        $out = app(Reader::class)->read($race->fresh(), $entry);
        $this->assertSame('UNKNOWN', $out['participation']);
        $this->assertFalse($out['evidence_verified']);
        $this->assertContains('CANCELLED_RAW_STATE_UNVERIFIED', $out['issues']);
    }

    public function test_pr119_cancelled_import_missing_log_raw_or_timestamp_is_not_skipped(): void
    {
        $case = $this->cases()[0];
        [$race, $log, $import] = $this->seedSaved($case);
        $cancel = $this->cancelControl($case, $race, $log, $import);
        $entry = RaceEntry::query()->where('race_id', $race->id)->firstOrFail();
        $original = $cancel->getAttributes();
        foreach ([['scraping_fetch_log_id' => null], ['imported_at' => null], ['import_status' => 'FAILED']] as $bad) {
            $cancel->timestamps = false;
            $cancel->forceFill($bad)->save();
            $out = app(Reader::class)->read($race->fresh(), $entry);
            $this->assertSame('UNKNOWN', $out['participation']);
            $this->assertFalse($out['evidence_verified']);
            $cancel->forceFill($original)->save();
        }
        $cancel->raw_file_path = 'missing-cancelled-control.html';
        $cancel->save();
        $log->timestamps = false;
        $log->raw_file_path = $cancel->raw_file_path;
        $log->save();
        $out = app(Reader::class)->read($race->fresh(), $entry);
        $this->assertSame('UNKNOWN', $out['participation']);
        $this->assertContains('CANCELLED_RAW_MISSING', $out['issues']);
    }

    public function test_pr119_unknown_cancel_occupies_history_slot_but_verified_cancel_is_excluded(): void
    {
        $this->seedAll();
        $case = collect($this->cases())->first(fn ($c) => $c['race']['id'] === 37828);
        $race = Race::query()->findOrFail(37828);
        $import = RaceResultImport::query()->findOrFail($case['import']['id']);
        $log = ScrapingFetchLog::query()->findOrFail($case['log']['id']);
        $race->update(['result_status' => 'CANCELLED', 'scheduled_start_at' => null]);
        $profile = $this->playerProfile(12542, '014236', '2025-01-01');
        $this->assertSame(0, $profile->audit['cancelled']);
        $this->assertSame(2, $profile->audit['candidate_starts']);
        $this->assertContains('CANCELLED_IMPORT_UNVERIFIED', $profile->windows['LAST_3']['reasons']);
        $this->assertNull($profile->windows['LAST_3']['method_counts']);
        $this->cancelControl($case, $race, $log, $import);
        $profile = $this->playerProfile(12542, '014236', '2025-01-01');
        $this->assertSame(1, $profile->audit['cancelled']);
        $this->assertSame(1, $profile->audit['candidate_starts']);
        $this->assertNotContains('SCHEDULED_TIME_UNKNOWN', $profile->windows['LAST_3']['reasons']);
    }

    public function test_pr119_cancelled_source_identity_timing_and_raw_integrity_are_checked(): void
    {
        $case = $this->cases()[0];
        [$race, $log, $import] = $this->seedSaved($case);
        $cancel = $this->cancelControl($case, $race, $log, $import);
        $entry = RaceEntry::query()->where('race_id', $race->id)->firstOrFail();
        $original = $log->getAttributes();
        $original['request_parameters'] = $log->request_parameters;
        foreach ([['sha256' => str_repeat('0', 64)], ['response_size' => 1], ['request_parameters' => ['disp' => 'PJ0315']],
            ['fetched_at' => $cancel->imported_at->addSecond()]] as $bad) {
            $log->timestamps = false;
            $log->forceFill($bad)->save();
            $out = app(Reader::class)->read($race->fresh(), $entry);
            $this->assertSame('UNKNOWN', $out['participation']);
            $this->assertFalse($out['evidence_verified']);
            $log->forceFill($original)->save();
        }
        $this->statusControl($this->cases()[1], $log, $cancel, 'CANCELLED'); // Different real race inside an artificial cancellation control.
        $out = app(Reader::class)->read($race->fresh(), $entry);
        $this->assertSame('UNKNOWN', $out['participation']);
        $this->assertContains('CANCELLED_RAW_IDENTITY_UNVERIFIED', $out['issues']);
        $this->statusControl($case, $log, $cancel, 'CANCELLED');
        $stream = fopen($this->temporary->path().'/raw/'.$log->raw_file_path, 'r+b');
        fwrite($stream, '!');
        fclose($stream);
        $this->reject(fn () => app(Reader::class)->read($race->fresh(), $entry));
    }

    public function test_pr119_cancelled_import_does_not_fall_back_past_later_successful_contradiction(): void
    {
        $case = $this->cases()[0];
        [$race, $log, $import] = $this->seedSaved($case);
        $cancel = $this->cancelControl($case, $race, $log, $import);
        $entry = RaceEntry::query()->where('race_id', $race->id)->firstOrFail();
        $this->assertSame('CANCELLED', app(Reader::class)->read($race->fresh(), $entry)['participation']);
        $later = $cancel->replicate();
        $later->forceFill(['imported_at' => $cancel->imported_at->addSecond(), 'requested_result_status' => 'CONFIRMED',
            'parsed_page_status' => 'RESULTS_AVAILABLE'])->save();
        $out = app(Reader::class)->read($race->fresh(), $entry);
        $this->assertSame('UNKNOWN', $out['participation']);
        $this->assertContains('CANCELLED_IMPORT_UNVERIFIED', $out['issues']);
    }

    public function test_pr119_same_instant_as_of_is_canonical_in_features_audit_run_and_storage(): void
    {
        $this->seedAll();
        $service = app(OfficialMethodHistoryBuildService::class);
        $jst = $service->build(12542, new DateTimeImmutable('2025-01-01T00:00:00+09:00'), true);
        $utc = $service->build(12542, new DateTimeImmutable('2024-12-31T15:00:00Z'), true);
        $this->assertSame($jst, $utc);
        $this->artisan('keirin:statistics:build-official-method-history', ['--race-id' => '12542',
            '--input-as-of' => '2024-12-31T15:00:00Z', '--execute' => true])->assertExitCode(0);
        foreach (DB::table('statistic_feature_runs')->get() as $run) {
            $this->assertSame('MEETING_START_JST_RETROSPECTIVE_ONLY', $run->input_as_of_policy);
            $this->assertSame('2025-01-01T00:00:00+09:00', json_decode($run->parameters, true)['input_as_of']);
        }
        foreach (StatisticFeatureResult::all() as $row) {
            $this->assertSame('2025-01-01T00:00:00+09:00', $row->evidence['input_as_of']);
            $this->assertSame('2025-01-01 00:00:00+09:00', $row->getRawOriginal('input_as_of'));
            $this->assertEquals(new DateTimeImmutable('2024-12-31T15:00:00Z'), new DateTimeImmutable($row->getRawOriginal('input_as_of')));
        }
    }

    public function test_pr119_before_after_and_invalid_as_of_never_publish_either_statistic(): void
    {
        $this->seedAll();
        foreach (['2024-12-31T23:59:59+09:00', '2025-01-01T00:00:01+09:00', '2025-01-04T00:00:00+09:00'] as $stamp) {
            $this->reject(fn () => iterator_to_array(app(OfficialMethodHistoryRepository::class)->inputs(12542, new DateTimeImmutable($stamp))));
            $this->reject(fn () => app(OfficialMethodHistoryBuildService::class)->build(12542, new DateTimeImmutable($stamp), false));
        }
        foreach (['2024-12-31T23:59:59+09:00', '2025-01-01T00:00:01+09:00', '2025-01-04T00:00:00+09:00',
            '2025-02-30T00:00:00+09:00', '2025-01-01T00:00:00+00:99', 'not-a-date'] as $stamp) {
            foreach ([[], ['--execute' => true], ['--execute' => true, '--dry-run' => true]] as $mode) {
                $this->artisan('keirin:statistics:build-official-method-history', $mode + ['--race-id' => '12542',
                    '--input-as-of' => $stamp])->assertExitCode(1);
            }
        }
        $this->assertSame(0, DB::table('statistic_feature_runs')->count());
        $this->assertSame(0, DB::table('statistic_feature_run_items')->count());
        $this->assertSame(0, StatisticFeatureResult::query()->count());
    }

    public function test_pr119_plan_checks_target_cutoff_without_reading_raw(): void
    {
        $this->seedAll();
        $this->mock(Reader::class)->shouldNotReceive('read');
        $this->artisan('keirin:statistics:build-official-method-history', ['--race-id' => '12542',
            '--input-as-of' => '2024-12-31T15:00:00Z'])->assertExitCode(0);
        $this->assertSame(0, DB::table('statistic_feature_runs')->count());
    }

    /** Artificial page-state mutation only; never real cancellation/correction acceptance. */
    private function statusControl(array $case, ScrapingFetchLog $log, RaceResultImport $import, string $status)
    {
        [$html] = app(CharacterEncodingConverter::class)->convertToUtf8(file_get_contents($case['fixture_root'].'/'.$case['fixture_file']), $case['log']['content_type']);
        $extractor = app(EmbeddedJsonExtractor::class);
        $pc = $extractor->extract($html, 'PC0201');
        $pj = $extractor->extract($html, 'PJ0326');
        $pc['C0201data']['flgRaceCancel'] = $status === 'CANCELLED';
        $pc['C0201data']['flgSectionCancel'] = false;
        $pj['status'] = ['PROVISIONAL' => '暫定', 'UNDER_REVIEW' => '審議中', 'CONFIRMED' => '確定', 'CORRECTED' => '訂正', 'CANCELLED' => '中止', 'UNKNOWN' => '不明'][$status];
        if ($status === 'UNKNOWN') {
            $pc['C0201data']['C0201race'] = [];
        }
        $body = '<!-- ARTIFICIAL PR119 PAGE-STATE BOUNDARY --><script>jsonData["PC0201"] = '.json_encode($pc, JSON_THROW_ON_ERROR)
            .';jsonData["PJ0326"] = '.json_encode($pj, JSON_THROW_ON_ERROR).';</script>';
        $path = 'pr119-status-'.bin2hex(random_bytes(8)).'.html';
        $this->writeNew($this->temporary->path().'/raw/'.$path, $body);
        $log->timestamps = false;
        $log->forceFill(['raw_file_path' => $path, 'sha256' => hash('sha256', $body), 'response_size' => strlen($body),
            'content_type' => 'text/html; charset=UTF-8'])->save();
        $import->timestamps = false;
        $import->forceFill(['raw_file_path' => $path, 'source_hash' => $log->sha256, 'raw_response_size' => strlen($body),
            'converted_hash' => $log->sha256])->save();

        return app(RaceLiveResultParser::class)->parse($body);
    }

    private function cancelControl(array $case, Race $race, ScrapingFetchLog $log, RaceResultImport $import): RaceResultImport
    {
        $page = $this->statusControl($case, $log, $import, 'CANCELLED');
        $race->update(['result_status' => 'PROVISIONAL']); // Artificial state transition setup for the existing importer.
        $run = BatchRun::query()->create(['type' => 'PR119_ARTIFICIAL_CANCELLATION', 'source' => 'keirin_jp',
            'status' => 'RUNNING', 'lock_key' => bin2hex(random_bytes(8)), 'started_at' => now()]);
        $item = BatchRunItem::query()->create(['batch_run_id' => $run->id, 'item_type' => 'RACE_RESULT',
            'item_key' => 'PR119_CONTROL', 'status' => 'RUNNING', 'attempt_count' => 1, 'started_at' => now()]);
        $body = file_get_contents($this->temporary->path().'/raw/'.$log->raw_file_path);
        $out = app(RaceResultImportService::class)->importStoredResponse($race, $run, $item,
            new StoredRawResponseDto($log->raw_file_path, $log->sha256, $log->response_size, 'UTF-8', true, $body, $log->id),
            $page->resultPage, $log->request_url, RaceResultStatus::Cancelled);

        return $out['import'];
    }

    private function playerProfile(int $raceId, string $registration, string $start)
    {
        foreach (app(OfficialMethodHistoryRepository::class)->inputs($raceId, new DateTimeImmutable($start.'T00:00:00+09:00')) as $input) {
            if ($input->entry['external_player_id'] === $registration) {
                return app(WindowBuilder::class)->build($input);
            }
        }
        throw new RuntimeException('Actual fixture player missing.');
    }

    private function seedAll(): void
    {
        foreach ($this->cases() as $case) {
            $this->seedSaved($case);
        }
    }

    private function cases(): array
    {
        $path = getenv('STAT38_RESULT_FIXTURE') ?: '/home/shinya/neo-keirin-artifacts/stat38-distance-laps-mvp-01-20261021-01/pr117-review-20261022-075425-15b42fa7/result-fixture.json';
        if (! is_file($path) || hash_file('sha256', $path) !== '706e5747f6383711055d1ded3c16575016cfc22148823cc75379117410ee3f11') {
            throw new RuntimeException('Sealed real PJ0326 fixture missing or changed.');
        }
        $cases = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR)['cases'];
        foreach ($cases as &$case) {
            $case['fixture_root'] = dirname($path);
        }

        return $cases;
    }

    private function seedSaved(array $case): array
    {
        $path = getenv('OFFICIAL_METHOD_MEETING_FIXTURE') ?: '/home/shinya/neo-keirin-artifacts/stat19-30-official-method-history-mvp-01/prepare-20261022-133230-672ed72a/meeting-fixture.json';
        if (! is_file($path) || hash_file('sha256', $path) !== '3dfa0b2f3d8d510a13ec2e74c2b73f27a9c7a1eca48b7d6f885434e87e1a3fc6') {
            throw new RuntimeException('Sealed real meeting metadata missing or changed.');
        }
        $context = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR)['races'][$case['race']['id']];
        $track = Racetrack::query()->firstOrCreate(['source' => 'keirin_jp', 'external_track_id' => $case['track_code']], ['name' => $case['track_code']]);
        // Adapter-only external keys are not historical identity evidence; actual internal IDs/dates are sealed above.
        if (! RaceMeeting::query()->find($context['meeting_id'])) {
            $this->savedRow(RaceMeeting::class, ['id' => $context['meeting_id'], 'source' => 'keirin_jp',
                'external_meeting_id' => 'isolated-snapshot:'.$context['meeting_id'], 'racetrack_id' => $track->id,
                'starts_on' => $context['starts_on'], 'ends_on' => $context['ends_on'],
                'duration_days' => (int) new DateTimeImmutable($context['starts_on'])->diff(new DateTimeImmutable($context['ends_on']))->days + 1]);
        }
        $this->savedRow(RaceDay::class, ['id' => $context['day_id'], 'race_meeting_id' => $context['meeting_id'],
            'external_race_day_id' => 'isolated-snapshot:'.$context['day_id'], 'race_date' => $context['race_date'],
            'day_number' => (int) new DateTimeImmutable($context['starts_on'])->diff(new DateTimeImmutable($context['race_date']))->days + 1]);
        $attrs = $case['race'];
        $this->assertSame($attrs['race_date'], $context['race_date']);
        $this->assertSame($attrs['race_day_id'], $context['day_id']);
        $attrs['racetrack_id'] = $track->id;
        unset($attrs['encrypted_parameter']);
        $race = $this->savedRow(Race::class, $attrs);
        foreach ($case['entries'] as $attrs) {
            $match = collect($context['entries'])->firstWhere('id', $attrs['id']);
            $this->assertSame($match['external_player_id'], $attrs['external_player_id']);
            $this->assertSame($match['bike_number'], $attrs['bike_number']);
            if ($attrs['player_id'] !== null && ! Player::query()->find($attrs['player_id'])) {
                $this->savedRow(Player::class, ['id' => $attrs['player_id'], 'source' => 'keirin_jp',
                    'external_player_id' => $attrs['external_player_id'], 'name' => $attrs['player_name']]);
            }
            $this->savedRow(RaceEntry::class, $attrs);
        }
        $attrs = $case['log'];
        $attrs['batch_run_id'] = null;
        $attrs['request_parameters'] = json_decode($attrs['request_parameters'], true, flags: JSON_THROW_ON_ERROR);
        $attrs['raw_file_path'] = $case['fixture_file'];
        $log = $this->savedRow(ScrapingFetchLog::class, $attrs);
        $raw = $case['fixture_root'].'/'.$case['fixture_file'];
        $this->assertSame($case['original']['sha256'], hash_file('sha256', $raw));
        $this->assertSame($case['original']['bytes'], filesize($raw));
        $this->writeNew($this->temporary->path().'/raw/'.$case['fixture_file'], file_get_contents($raw));
        $attrs = $case['import'];
        $attrs['batch_run_id'] = $attrs['batch_run_item_id'] = null;
        $attrs['raw_file_path'] = $case['fixture_file'];
        $import = $this->savedRow(RaceResultImport::class, $attrs);
        foreach ($case['results'] as $attrs) {
            $this->savedRow(RaceResult::class, $attrs);
        }

        return [$race, $log, $import];
    }

    private function savedRow(string $class, array $attrs): Model
    {
        $row = $class::query()->find($attrs['id']) ?? new $class;
        $row->timestamps = false;
        $row->forceFill($attrs)->save();

        return $row->fresh();
    }

    private function writeNew(string $path, string $bytes): void
    {
        $stream = fopen($path, 'xb');
        if ($stream === false || fwrite($stream, $bytes) !== strlen($bytes) || ! fflush($stream)) {
            throw new RuntimeException('Cannot create exclusive official method fixture.');
        }
        fclose($stream);
    }

    private function reject(callable $call): void
    {
        $caught = null;
        try {
            $call();
        } catch (RuntimeException $error) {
            $caught = $error;
        }
        $this->assertInstanceOf(RuntimeException::class, $caught, 'Invalid isolated boundary accepted.');
    }

    private function businessState(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(),
            ['races', 'race_entries', 'race_results', 'race_result_imports', 'scraping_fetch_logs', 'race_tactic_observations', 'race_distance_observations']);
    }
}
