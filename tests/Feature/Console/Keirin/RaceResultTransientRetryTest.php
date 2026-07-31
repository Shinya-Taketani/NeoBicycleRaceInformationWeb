<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Keirin;

use App\Models\BatchRun;
use App\Models\BatchRunItem;
use App\Models\Race;
use App\Models\RaceEntry;
use App\Models\RacePayout;
use App\Models\RaceResult;
use App\Models\RaceResultImport;
use App\Models\Racetrack;
use App\Models\ScrapingFetchLog;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RaceResultTransientRetryTest extends TestCase
{
    use RefreshDatabase;

    public function test_dns_failure_recovers_in_the_same_run_with_audited_attempts(): void
    {
        $this->configureRetries(passes: 1);
        $race = $this->raceWithEntries(1, 'dns-detail-recovery');
        $detailAttempts = 0;
        Http::fake(function (Request $request) use (&$detailAttempts) {
            $form = $this->requestForm($request);
            if (($form['disp'] ?? null) === 'PJ0315' && ++$detailAttempts === 1) {
                throw $this->dnsException();
            }

            return Http::response(
                ($form['disp'] ?? null) === 'PJ0315'
                    ? $this->detailFixture(1)
                    : $this->resultFixture(1),
                200,
                ['Content-Type' => 'text/html; charset=UTF-8'],
            );
        });

        $this->artisan('keirin:races:sync-results', [
            '--date' => '2026-06-16',
            '--race-id' => (string) $race->id,
            '--sleep-ms' => '0',
            '--transient-retry-passes' => '1',
            '--transient-retry-sleep-ms' => '0',
        ])->expectsOutputToContain('success=1 skipped=0 failed=0 results=7 payouts=8')
            ->assertExitCode(0);

        $run = BatchRun::query()->sole();
        $item = BatchRunItem::query()->sole();
        $this->assertSame('SUCCEEDED', $run->status);
        $this->assertSame(1, $run->success_count);
        $this->assertSame(0, $run->failure_count);
        $this->assertNull($run->error_message);
        $this->assertSame('SUCCEEDED', $item->status);
        $this->assertSame(2, $item->attempt_count);
        $this->assertSame(1, $item->metadata['retry_pass']);
        $this->assertSame('PJ0315', $item->metadata['transient_failures'][0]['phase']);
        $this->assertSame('DNS_FAILURE', $item->metadata['transient_failures'][0]['fetch_error_type']);
        $this->assertSame(0, $item->metadata['transient_failures'][0]['http_retry_count']);
        $this->assertSame(7, RaceResult::query()->where('race_id', $race->id)->count());
        $this->assertSame(8, RacePayout::query()->where('race_id', $race->id)->count());
        $this->assertSame(1, RaceResultImport::query()->where('race_id', $race->id)->count());
        $this->assertSame(3, ScrapingFetchLog::query()->where('batch_run_id', $run->id)->count());
        $this->assertDatabaseHas('scraping_fetch_logs', [
            'batch_run_id' => $run->id,
            'error_type' => 'DNS_FAILURE',
            'http_status' => null,
            'response_size' => 0,
            'raw_file_path' => null,
        ]);
    }

    public function test_http_level_retry_recovery_does_not_start_a_service_deferred_attempt(): void
    {
        $this->configureRetries(passes: 1);
        config(['keirin.retry_times' => 2]);
        $race = $this->raceWithEntries(1, 'http-level-recovery');
        $detailAttempts = 0;
        Http::fake(function (Request $request) use (&$detailAttempts) {
            $form = $this->requestForm($request);
            if (($form['disp'] ?? null) === 'PJ0315' && ++$detailAttempts === 1) {
                throw $this->dnsException();
            }

            return Http::response(
                ($form['disp'] ?? null) === 'PJ0315'
                    ? $this->detailFixture(1)
                    : $this->resultFixture(1),
                200,
                ['Content-Type' => 'text/html; charset=UTF-8'],
            );
        });

        $this->artisan('keirin:races:sync-results', [
            '--date' => '2026-06-16',
            '--race-id' => (string) $race->id,
            '--sleep-ms' => '0',
            '--transient-retry-passes' => '1',
            '--transient-retry-sleep-ms' => '0',
        ])->assertExitCode(0);

        $item = BatchRunItem::query()->sole();
        $this->assertSame(2, $detailAttempts);
        $this->assertSame(1, $item->attempt_count);
        $this->assertArrayNotHasKey('transient_failures', $item->metadata);
        $this->assertSame(2, ScrapingFetchLog::query()->count());
        $this->assertSame(0, ScrapingFetchLog::query()->whereNotNull('error_type')->count());
    }

    public function test_deferred_attempts_run_only_after_later_initial_candidates_finish(): void
    {
        $this->configureRetries(passes: 1);
        $raceA = $this->raceWithEntries(1, 'deferred-a');
        $raceB = $this->raceWithEntries(2, 'deferred-b');
        $requests = [];
        $failedOnce = false;
        Http::fake(function (Request $request) use (&$requests, &$failedOnce) {
            $form = $this->requestForm($request);
            $requests[] = ($form['encp'] ?? '').':'.($form['disp'] ?? '');
            if (($form['encp'] ?? null) === 'deferred-a'
                && ($form['disp'] ?? null) === 'PJ0315'
                && ! $failedOnce) {
                $failedOnce = true;

                throw $this->dnsException();
            }
            $raceNumber = ($form['encp'] ?? null) === 'deferred-a' ? 1 : 2;

            return Http::response(
                ($form['disp'] ?? null) === 'PJ0315'
                    ? $this->detailFixture($raceNumber)
                    : $this->resultFixture($raceNumber),
                200,
                ['Content-Type' => 'text/html; charset=UTF-8'],
            );
        });

        $this->artisan('keirin:races:sync-results', [
            '--date' => '2026-06-16',
            '--sleep-ms' => '0',
            '--transient-retry-passes' => '1',
            '--transient-retry-sleep-ms' => '0',
        ])->assertExitCode(0);

        $this->assertSame([
            'deferred-a:PJ0315',
            'deferred-b:PJ0315',
            'deferred-b:PJ0326',
            'deferred-a:PJ0315',
            'deferred-a:PJ0326',
        ], $requests);
        $this->assertSame(2, BatchRun::query()->sole()->success_count);
        $this->assertSame(0, BatchRun::query()->sole()->failure_count);
        $this->assertSame(
            2,
            BatchRunItem::query()->where('item_key', 'race:'.$raceA->id)->value('attempt_count'),
        );
        $this->assertSame(
            1,
            BatchRunItem::query()->where('item_key', 'race:'.$raceB->id)->value('attempt_count'),
        );
    }

    public function test_pj0326_dns_failure_reprocesses_safely_without_duplicate_results_or_imports(): void
    {
        $this->configureRetries(passes: 1);
        $race = $this->raceWithEntries(1, 'dns-result-recovery');
        $detailAttempts = 0;
        $resultAttempts = 0;
        Http::fake(function (Request $request) use (&$detailAttempts, &$resultAttempts) {
            $form = $this->requestForm($request);
            if (($form['disp'] ?? null) === 'PJ0315') {
                $detailAttempts++;

                return Http::response($this->detailFixture(1), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
            }
            if (++$resultAttempts === 1) {
                throw $this->dnsException();
            }

            return Http::response($this->resultFixture(1), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        });

        $this->artisan('keirin:races:sync-results', [
            '--date' => '2026-06-16',
            '--race-id' => (string) $race->id,
            '--sleep-ms' => '0',
            '--transient-retry-passes' => '1',
            '--transient-retry-sleep-ms' => '0',
        ])->assertExitCode(0);

        $item = BatchRunItem::query()->sole();
        $this->assertSame(2, $detailAttempts);
        $this->assertSame(2, $resultAttempts);
        $this->assertSame(2, $item->attempt_count);
        $this->assertSame('PJ0326', $item->metadata['transient_failures'][0]['phase']);
        $this->assertSame(7, RaceResult::query()->where('race_id', $race->id)->count());
        $this->assertSame(8, RacePayout::query()->where('race_id', $race->id)->count());
        $this->assertSame(1, RaceResultImport::query()->where('race_id', $race->id)->count());
        $this->assertSame(4, ScrapingFetchLog::query()->count());
        $this->assertSame(0, BatchRun::query()->where('status', 'RUNNING')->count());
        $this->assertSame(0, BatchRunItem::query()->where('status', 'RUNNING')->count());
    }

    public function test_exhausted_dns_retry_passes_count_only_one_final_failure(): void
    {
        $this->configureRetries(passes: 1);
        $race = $this->raceWithEntries(1, 'dns-exhausted');
        Http::fake(fn (): never => throw $this->dnsException());

        $this->artisan('keirin:races:sync-results', [
            '--date' => '2026-06-16',
            '--race-id' => (string) $race->id,
            '--sleep-ms' => '0',
            '--transient-retry-passes' => '1',
            '--transient-retry-sleep-ms' => '0',
        ])->expectsOutputToContain('success=0 skipped=0 failed=1 results=0 payouts=0')
            ->assertExitCode(1);

        $run = BatchRun::query()->sole();
        $item = BatchRunItem::query()->sole();
        $this->assertSame('FAILED', $run->status);
        $this->assertSame(1, $run->failure_count);
        $this->assertNotNull($run->finished_at);
        $this->assertNotNull($run->error_message);
        $this->assertSame('FAILED', $item->status);
        $this->assertSame(2, $item->attempt_count);
        $this->assertCount(2, $item->metadata['transient_failures']);
        $this->assertSame([0, 1], array_column($item->metadata['transient_failures'], 'retry_pass'));
        $this->assertSame(2, ScrapingFetchLog::query()->where('error_type', 'DNS_FAILURE')->count());
        $this->assertSame(0, BatchRunItem::query()->where('status', 'RUNNING')->count());
    }

    public function test_parser_failure_is_permanent_and_is_not_deferred(): void
    {
        $this->configureRetries(passes: 2);
        $race = $this->raceWithEntries(1, 'parser-failure');
        $requests = 0;
        Http::fake(function () use (&$requests) {
            $requests++;

            return Http::response('<html><body>invalid detail</body></html>', 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
            ]);
        });

        $this->artisan('keirin:races:sync-results', [
            '--date' => '2026-06-16',
            '--race-id' => (string) $race->id,
            '--sleep-ms' => '0',
            '--transient-retry-passes' => '2',
            '--transient-retry-sleep-ms' => '0',
        ])->assertExitCode(1);

        $item = BatchRunItem::query()->sole();
        $this->assertSame(1, $requests);
        $this->assertSame(1, $item->attempt_count);
        $this->assertSame('FAILED', $item->status);
        $this->assertSame('PJ0315', $item->metadata['phase']);
        $this->assertArrayNotHasKey('transient_failures', $item->metadata);
        $this->assertSame(1, BatchRun::query()->sole()->failure_count);
    }

    public function test_failed_batch_mode_processes_only_failed_items_and_preserves_the_source_batch(): void
    {
        $this->configureRetries(passes: 0);
        $raceA = $this->raceWithEntries(1, 'retry-a');
        $raceB = $this->raceWithEntries(2, 'retry-b');
        $raceC = $this->raceWithEntries(3, 'retry-c');
        $raceD = $this->raceWithEntries(4, 'retry-d');
        $sourceRun = $this->sourceRun();
        $itemA = $this->sourceItem($sourceRun, $raceA, 'FAILED');
        $itemB = $this->sourceItem($sourceRun, $raceB, 'FAILED');
        $this->sourceItem($sourceRun, $raceC, 'SUCCEEDED');
        $this->sourceItem($sourceRun, $raceD, 'SKIPPED');
        $sourceRunBefore = $sourceRun->fresh()->toArray();
        $sourceItemsBefore = BatchRunItem::query()
            ->where('batch_run_id', $sourceRun->id)
            ->orderBy('id')
            ->get()
            ->toArray();
        Http::fake(function (Request $request) {
            $form = $this->requestForm($request);
            $raceNumber = ($form['encp'] ?? null) === 'retry-a' ? 1 : 2;

            return Http::response(
                ($form['disp'] ?? null) === 'PJ0315'
                    ? $this->detailFixture($raceNumber)
                    : $this->resultFixture($raceNumber),
                200,
                ['Content-Type' => 'text/html; charset=UTF-8'],
            );
        });

        $this->artisan('keirin:races:sync-results', [
            '--retry-failed-batch-run-id' => (string) $sourceRun->id,
            '--sleep-ms' => '0',
            '--transient-retry-passes' => '0',
            '--transient-retry-sleep-ms' => '0',
        ])->expectsOutputToContain('success=2 skipped=0 failed=0 results=14 payouts=16')
            ->assertExitCode(0);

        $retryRun = BatchRun::query()->where('type', 'race_result_retry')->sole();
        $retryItems = BatchRunItem::query()
            ->where('batch_run_id', $retryRun->id)
            ->orderBy('id')
            ->get();
        $this->assertSame($sourceRun->id, $retryRun->parameters['source_batch_run_id']);
        $this->assertSame(['race:'.$raceA->id, 'race:'.$raceB->id], $retryItems->pluck('item_key')->all());
        $this->assertSame([$itemA->id, $itemB->id], $retryItems->pluck('metadata')->map(
            fn (array $metadata): int => $metadata['source_batch_run_item_id'],
        )->all());
        $this->assertSame(14, RaceResult::query()->whereIn('race_id', [$raceA->id, $raceB->id])->count());
        $this->assertSame(16, RacePayout::query()->whereIn('race_id', [$raceA->id, $raceB->id])->count());
        $this->assertSame(2, RaceResultImport::query()->count());
        $this->assertSame(0, RaceResult::query()->whereIn('race_id', [$raceC->id, $raceD->id])->count());
        $this->assertSame($sourceRunBefore, $sourceRun->fresh()->toArray());
        $this->assertSame(
            $sourceItemsBefore,
            BatchRunItem::query()->where('batch_run_id', $sourceRun->id)->orderBy('id')->get()->toArray(),
        );
    }

    public function test_failed_batch_limit_uses_original_failed_item_order(): void
    {
        $this->configureRetries(passes: 0);
        $races = [
            $this->raceWithEntries(1, 'limit-a', raceType: 'Ｌ級ガールズ予選'),
            $this->raceWithEntries(2, 'limit-b', raceType: 'Ｌ級ガールズ予選'),
            $this->raceWithEntries(3, 'limit-c', raceType: 'Ｌ級ガールズ予選'),
        ];
        $sourceRun = $this->sourceRun();
        foreach ($races as $race) {
            $this->sourceItem($sourceRun, $race, 'FAILED');
        }
        Http::fake();

        $this->artisan('keirin:races:sync-results', [
            '--retry-failed-batch-run-id' => (string) $sourceRun->id,
            '--limit' => '2',
            '--transient-retry-passes' => '0',
            '--transient-retry-sleep-ms' => '0',
        ])->assertExitCode(0);

        $retryRun = BatchRun::query()->where('type', 'race_result_retry')->sole();
        $this->assertSame(
            ['race:'.$races[0]->id, 'race:'.$races[1]->id],
            BatchRunItem::query()->where('batch_run_id', $retryRun->id)->orderBy('id')->pluck('item_key')->all(),
        );
        $this->assertSame(2, $retryRun->skipped_count);
        Http::assertNothingSent();
    }

    public function test_failed_batch_mode_rejects_invalid_source_batches_items_and_options(): void
    {
        $this->configureRetries(passes: 0);

        $this->assertSame(1, Artisan::call('keirin:races:sync-results', [
            '--retry-failed-batch-run-id' => '999999',
        ]));

        $wrongSource = $this->sourceRun(source: 'other_source');
        $this->assertRetryBatchFails($wrongSource);

        $wrongType = $this->sourceRun(type: 'player_sync');
        $this->assertRetryBatchFails($wrongType);

        $running = $this->sourceRun(status: 'RUNNING', finished: false);
        $this->assertRetryBatchFails($running);

        $unfinished = $this->sourceRun(status: 'FAILED', finished: false);
        $this->assertRetryBatchFails($unfinished);

        $invalidKey = $this->sourceRun();
        $this->sourceItem($invalidKey, null, 'FAILED', 'race:not-an-id');
        $this->assertRetryBatchFails($invalidKey);

        $missingRace = $this->sourceRun();
        $this->sourceItem($missingRace, null, 'FAILED', 'race:999999');
        $this->assertRetryBatchFails($missingRace);

        $otherRace = $this->raceWithEntries(1, 'other-source', source: 'other_source');
        $otherRaceRun = $this->sourceRun();
        $this->sourceItem($otherRaceRun, $otherRace, 'FAILED');
        $this->assertRetryBatchFails($otherRaceRun);

        $valid = $this->sourceRun();
        foreach ([
            ['--date' => '2026-06-16'],
            ['--from' => '2026-01-01'],
            ['--to' => '2026-12-31'],
            ['--race-id' => '1'],
            ['--track-code' => '56'],
            ['--race-number' => '1'],
            ['--force' => true],
        ] as $conflict) {
            $this->assertSame(1, Artisan::call('keirin:races:sync-results', [
                '--retry-failed-batch-run-id' => (string) $valid->id,
                ...$conflict,
            ]));
        }
        $this->assertSame(1, Artisan::call('keirin:races:sync-results', [
            '--date' => '2026-06-16',
            '--transient-retry-passes' => '-1',
        ]));
        $this->assertSame(1, Artisan::call('keirin:races:sync-results', [
            '--date' => '2026-06-16',
            '--transient-retry-sleep-ms' => '1.5',
        ]));

        $this->assertSame(0, BatchRun::query()->where('type', 'race_result_retry')->count());
    }

    private function configureRetries(int $passes): void
    {
        Storage::fake('local');
        config([
            'keirin.sleep_ms' => 0,
            'keirin.retry_times' => 0,
            'keirin.retry_base_sleep_ms' => 0,
            'keirin.result_transient_retry_passes' => $passes,
            'keirin.result_transient_retry_sleep_ms' => 0,
        ]);
    }

    private function raceWithEntries(
        int $raceNumber,
        string $encryptedParameter,
        string $source = 'keirin_jp',
        string $raceType = 'S級予選',
    ): Race {
        $track = Racetrack::query()->firstOrCreate(
            ['source' => $source, 'external_track_id' => '56'],
            ['name' => "合成競輪場{$source}"],
        );
        $race = Race::query()->create([
            'source' => $source,
            'external_race_id' => sprintf('56:20260616:%02d', $raceNumber),
            'racetrack_id' => $track->id,
            'race_date' => '2026-06-16',
            'race_number' => $raceNumber,
            'race_type' => $raceType,
            'entrant_count' => 7,
            'encrypted_parameter' => $encryptedParameter,
            'result_available' => true,
        ]);
        foreach (range(1, 7) as $bikeNumber) {
            RaceEntry::query()->create([
                'race_id' => $race->id,
                'bike_number' => $bikeNumber,
                'external_player_id' => str_pad((string) $bikeNumber, 6, '0', STR_PAD_LEFT),
                'fetched_at' => now(),
            ]);
        }

        return $race;
    }

    private function sourceRun(
        string $source = 'keirin_jp',
        string $type = 'race_result_sync',
        string $status = 'PARTIALLY_FAILED',
        bool $finished = true,
    ): BatchRun {
        return BatchRun::query()->create([
            'type' => $type,
            'source' => $source,
            'status' => $status,
            'parameters' => [],
            'started_at' => now()->subMinute(),
            'finished_at' => $finished ? now() : null,
            'failure_count' => 1,
        ]);
    }

    private function sourceItem(
        BatchRun $run,
        ?Race $race,
        string $status,
        ?string $itemKey = null,
    ): BatchRunItem {
        return BatchRunItem::query()->create([
            'batch_run_id' => $run->id,
            'item_type' => 'RACE_RESULT',
            'item_key' => $itemKey ?? 'race:'.$race?->id,
            'status' => $status,
            'attempt_count' => 1,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'error_type' => $status === 'FAILED' ? 'SyntheticFailure' : null,
            'error_message' => $status === 'FAILED' ? 'Synthetic failure.' : null,
            'metadata' => ['original' => true],
        ]);
    }

    private function assertRetryBatchFails(BatchRun $run): void
    {
        $this->assertSame(1, Artisan::call('keirin:races:sync-results', [
            '--retry-failed-batch-run-id' => (string) $run->id,
        ]), Artisan::output());
    }

    /** @return array<string,string> */
    private function requestForm(Request $request): array
    {
        parse_str($request->body(), $form);

        return $form;
    }

    private function dnsException(): ConnectionException
    {
        return new ConnectionException(
            'cURL error 6: Could not resolve host: keirin.jp',
            0,
            new ConnectException(
                'DNS failure',
                new PsrRequest('POST', 'https://keirin.jp/pc/racelive'),
                null,
                ['errno' => 6],
            ),
        );
    }

    private function detailFixture(int $raceNumber): string
    {
        return str_replace(
            '"selRaceNo":1',
            '"selRaceNo":'.$raceNumber,
            $this->fixture('race-sync-pj0315.html'),
        );
    }

    private function resultFixture(int $raceNumber): string
    {
        return str_replace(
            ['"selRaceNo":1', '"raceNo":1'],
            ['"selRaceNo":'.$raceNumber, '"raceNo":'.$raceNumber],
            $this->fixture('race-sync-pj0326.html'),
        );
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path("tests/Fixtures/Keirin/synthetic/{$name}"));
    }
}
