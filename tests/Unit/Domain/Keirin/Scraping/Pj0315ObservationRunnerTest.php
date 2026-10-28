<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Scraping;

use App\Domain\Keirin\Scraping\PrestartCapture\Contract as CaptureContract;
use App\Domain\Keirin\Scraping\PrestartCapture\Observation\AttemptLedger;
use App\Domain\Keirin\Scraping\PrestartCapture\Observation\HistoricalComparison;
use App\Domain\Keirin\Scraping\PrestartCapture\Observation\ObservationCaptureService;
use App\Domain\Keirin\Scraping\PrestartCapture\Observation\ObservationContract;
use App\Domain\Keirin\Scraping\PrestartCapture\Observation\ObservationManifest;
use App\Domain\Keirin\Scraping\PrestartCapture\Observation\ObservationParser;
use App\Domain\Keirin\Scraping\PrestartCapture\Observation\ObservationPreflight;
use App\Domain\Keirin\Scraping\PrestartCapture\Observation\ObservationVerifier;
use App\Domain\Keirin\Scraping\PrestartCapture\Observation\Pj0315ObservationTransport;
use App\Domain\Keirin\Scraping\PrestartCapture\Observation\RosterDifferenceEvaluator;
use App\Domain\Keirin\Scraping\PrestartCapture\Observation\SlotPlanner;
use App\Domain\Keirin\Scraping\PrestartCapture\Operational\Contract;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\ApprovalPreflightFixture;
use Tests\Support\CompositionResultTemporaryDirectory;
use Tests\Support\ObservationRunnerFixture as Fixture;
use Tests\Support\Pj0315ProbeFixture;

final class Pj0315ObservationRunnerTest extends TestCase
{
    private array $owned = [];

    private array $initialized = [];

    protected function tearDown(): void
    {
        try {
            foreach ($this->owned as $directory) {
                $directory->retire($directory->path(), $this->status()->isSuccess() ? 'TEST_COMPLETE' : 'TEST_FAILURE');
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_synthetic_post_persisted_before_parser_and_independent_verifier(): void
    {
        [$manifest, $input, $registry, $output] = $this->fixture();
        $http = new Factory;
        Pj0315ProbeFixture::fake($http, Pj0315ProbeFixture::html(), options: $options);
        $result = $this->capture($http, $manifest, $input, $registry, $output);
        $this->assertSame('COMPLETE', $result['state']);
        $this->assertFalse($result['stop_scope']);
        $this->assertSame(0, $result['real_http_calls']);
        $this->assertSame('NOT_AUTHORIZED', $result['prediction_use']);
        $this->assertSame(Pj0315ProbeFixture::html(), file_get_contents($result['directory'].'/raw.bin'));
        $this->assertSame(false, $options['allow_redirects']);
        $this->assertSame(5, $options['connect_timeout']);
        $this->assertSame(20, $options['timeout']);
        $http->assertSentCount(1);
        $http->assertSent(fn ($request) => $request->method() === 'POST' && $request->url() === ObservationContract::URI && $request['disp'] === 'PJ0315');
        $child = new Process([PHP_BINARY, '-d', 'memory_limit=128M', '-r',
            'require $argv[1]; $r=(new App\\Domain\\Keirin\\Scraping\\PrestartCapture\\Observation\\ObservationVerifier)->verify($argv[2],$argv[3]); echo json_encode([$r,ini_get("memory_limit"),memory_get_peak_usage(true)]);',
            dirname(__DIR__, 5).'/vendor/autoload.php', $result['directory'], $result['manifest_sha256']]);
        $child->mustRun();
        $verified = json_decode($child->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('VERIFIED', $verified[0]['status']);
        $this->assertSame('128M', $verified[1]);
        $this->assertLessThan(134217728, $verified[2]);
        CaptureContract::writeNew($output, 'child-verification.json', $child->getOutput());
    }

    #[DataProvider('httpFailures')]
    public function test_http_failures_are_preserved_and_stop_all_remaining_slots(int $status): void
    {
        [$manifest, $input, $registry, $output] = $this->fixture();
        $http = new Factory;
        Pj0315ProbeFixture::fake($http, Pj0315ProbeFixture::html(), $status);
        $result = $this->capture($http, $manifest, $input, $registry, $output);
        $this->assertSame('FAILED', $result['state']);
        $this->assertTrue($result['stop_scope']);
        $this->assertSame('FAILED_EVIDENCE_VERIFIED', (new ObservationVerifier)->verify($result['directory'], $result['manifest_sha256'])['status']);
        Fixture::time($input, '2025-01-04T01:30:00Z', 2);
        $this->expectException(RuntimeException::class);
        try {
            $this->capture($http, $manifest, $input, $registry, $output, 2);
        } finally {
            $http->assertSentCount(1);
        }
    }

    public static function httpFailures(): iterable
    {
        foreach ([301, 302, 307, 308, 403, 429, 500, 503] as $status) {
            yield (string) $status => [$status];
        }
    }

    public function test_timeout_no_retry_and_reserved_interruption_cannot_restart_in_other_run(): void
    {
        [$manifest, $input, $registry, $output] = $this->fixture();
        $http = new Factory;
        $calls = 0;
        $http->fake(function () use (&$calls) {
            $calls++;
            throw new ConnectionException('cURL error 28: synthetic timeout');
        });
        $result = $this->capture($http, $manifest, $input, $registry, $output);
        $this->assertSame('FAILED', $result['state']);
        $this->assertSame(1, $calls);
        $transfer = json_decode(file_get_contents($result['directory'].'/private-response.json'), true);
        $this->assertNull($transfer['response_received_at']);
        $this->assertNotNull($transfer['failed_at']);
        $other = $output.'-other';
        mkdir($other, 0700);
        try {
            $this->capture($http, $manifest, $input, $registry, $other);
            $this->fail('Retry in another run accepted.');
        } catch (RuntimeException) {
            $this->assertSame(1, $calls);
            $this->assertSame(['.', '..'], scandir($other));
        }
    }

    public function test_duplicate_after_process_restart_and_concurrent_process_are_rejected(): void
    {
        [$data, , $registry, $output] = $this->fixture();
        $manifest = new ObservationManifest($data);
        CaptureContract::writeNew($output, 'target.json', json_encode($data, JSON_THROW_ON_ERROR));
        $script = 'require $argv[1]; $m=new App\\Domain\\Keirin\\Scraping\\PrestartCapture\\Observation\\ObservationManifest(json_decode(file_get_contents($argv[2]),true)); try { $l=new App\\Domain\\Keirin\\Scraping\\PrestartCapture\\Observation\\AttemptLedger($m,$argv[3]); $l->exclusive(fn($l)=>$l->reserve(901,1,"2025-01-04T00:45:00Z")); exit(0); } catch (Throwable) {exit(7);}';
        $child = fn () => new Process([PHP_BINARY, '-d', 'memory_limit=128M', '-r', $script, dirname(__DIR__, 5).'/vendor/autoload.php', $output.'/target.json', $registry]);
        $ledger = $this->ledger($manifest, $registry);
        $ledger->exclusive(function ($ledger) use ($child): void {
            $process = $child();
            $process->run();
            $this->assertSame(7, $process->getExitCode());
            $ledger->reserve(901, 1, '2025-01-04T00:45:00Z');
        });
        $process = $child();
        $process->run();
        $this->assertSame(7, $process->getExitCode());
    }

    public function test_plan_windows_and_unauthorized_slot_additions(): void
    {
        [$data] = $this->fixture();
        $manifest = new ObservationManifest($data);
        $plan = (new SlotPlanner)->plan($manifest);
        $this->assertSame('2025-01-04T09:45:00.000000+09:00', $plan[0]['starts_at']);
        $this->assertSame('2025-01-04T10:30:00.000000+09:00', $plan[1]['starts_at']);
        foreach (['2025-01-04T00:44:59Z', '2025-01-04T00:46:00Z'] as $at) {
            try {
                (new SlotPlanner)->requireOpen($manifest, 901, 1, $at);
                $this->fail('Out-of-window slot accepted.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
        $data['targets'][0]['slots'][] = 5;
        $this->expectException(RuntimeException::class);
        new ObservationManifest($data);
    }

    #[DataProvider('manifestFailures')]
    public function test_invalid_scope_does_not_reach_io(string $kind): void
    {
        [$data] = $this->fixture();
        match ($kind) {
            '2026' => $data['targets'][0]['race']['race_date'] = '2026-01-04',
            'JSJ017' => $data['targets'][0]['parameters']['disp'] = 'JSJ017',
            'URI' => $data['targets'][0]['request_uri'] = 'https://other.invalid/',
            'three races' => $data['targets'] = [$data['targets'][0], $data['targets'][0], $data['targets'][0]],
            'no evidence' => $data['targets'][0]['identity_evidence_sha256'] = null,
        };
        $this->expectException(RuntimeException::class);
        new ObservationManifest($data);
    }

    public static function manifestFailures(): iterable
    {
        foreach (['2026', 'JSJ017', 'URI', 'three races', 'no evidence'] as $kind) {
            yield $kind => [$kind];
        }
    }

    public function test_synthetic_approval_never_becomes_real_authorization_and_unknown_clock_is_blocked(): void
    {
        [$data, $input] = $this->fixture();
        $manifest = new ObservationManifest($data);
        $at = new \DateTimeImmutable('2025-01-04T00:45:00Z');
        $this->assertSame('OFFLINE_SIMULATION_VALIDATED', (new ObservationPreflight)->evaluate($manifest, 901, 1, $input, Contract::SIMULATED, $at)['status']);
        $result = (new ObservationPreflight)->evaluate($manifest, 901, 1, $input, Contract::OPERATIONAL, $at);
        $this->assertSame('NOT_AUTHORIZED', $result['status']);
        $this->assertFalse($result['real_send_allowed']);
        $input['timing']['clock_evidence'] = 'UNKNOWN';
        $this->assertSame('NOT_AUTHORIZED', (new ObservationPreflight)->evaluate($manifest, 901, 1, $input, Contract::SIMULATED, $at)['status']);
    }

    #[DataProvider('parserFailures')]
    public function test_raw_parser_category_identity_and_schedule_changes_are_retained(string $kind, string $expected): void
    {
        [$manifest, $input, $registry, $output] = $this->fixture();
        $raw = match ($kind) {
            'parser' => '<html>SYNTHETIC INCOMPATIBLE PAGE</html>',
            'girls' => str_replace('S級予選', 'ガールズ予選', Pj0315ProbeFixture::html()),
            'unknown' => str_replace('S級予選', '不明', Pj0315ProbeFixture::html()),
            'identity' => str_replace('20250104', '20250105', Pj0315ProbeFixture::html()),
            'roster' => str_replace('000001', '888888', Pj0315ProbeFixture::html()),
            'schedule' => str_replace('10:45', '10:46', Pj0315ProbeFixture::html()),
        };
        $http = new Factory;
        Pj0315ProbeFixture::fake($http, $raw);
        $result = $this->capture($http, $manifest, $input, $registry, $output);
        $saved = json_decode(file_get_contents($result['directory'].'/result.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($expected, $saved['stop_reason']);
        $this->assertTrue($saved['stop_scope']);
        $this->assertSame('UNKNOWN', $saved['official_change_reason']);
        $this->assertSame($raw, file_get_contents($result['directory'].'/raw.bin'));
        $this->assertSame($manifest['targets'][0]['entries'][0]['external_player_id'], '000001');
    }

    public static function parserFailures(): iterable
    {
        yield 'parser' => ['parser', 'PARSER_OR_INTERNAL_IDENTITY_FAILED'];
        yield 'girls' => ['girls', 'SKIPPED_UNSUPPORTED_CATEGORY'];
        yield 'unknown' => ['unknown', 'SKIPPED_UNSUPPORTED_CATEGORY'];
        yield 'identity' => ['identity', 'RACE_IDENTITY_MISMATCH'];
        yield 'roster' => ['roster', 'ROSTER_DIFFERENCE_UNKNOWN_REASON'];
        yield 'schedule' => ['schedule', 'SCHEDULE_REVISED_OR_UNKNOWN'];
    }

    public function test_a_b_a_comparison_keeps_every_version_and_missing_null_type_differences(): void
    {
        [$data, , , $output] = $this->fixture();
        $parser = new ObservationParser;
        $raws = [Pj0315ProbeFixture::html(), str_replace('000001', '888888', Pj0315ProbeFixture::html()), Pj0315ProbeFixture::html()];
        $versions = [];
        foreach ($raws as $i => $raw) {
            CaptureContract::writeNew($output, 'version-'.$i.'.html', $raw);
            $versions[] = $parser->parse(file_get_contents($output.'/version-'.$i.'.html'), 'text/html; charset=UTF-8', $data['targets'][0]['race']);
        }
        $compare = new RosterDifferenceEvaluator;
        $this->assertSame('ROSTER_CHANGED', $compare->compare($versions[0], $versions[1], true)['roster']['status']);
        $this->assertSame('ROSTER_CHANGED', $compare->compare($versions[1], $versions[2], true)['roster']['status']);
        $this->assertSame('ROSTER_UNCHANGED', $compare->compare($versions[0], $versions[2], false)['roster']['status']);
        foreach ([['present' => true, 'type' => 'null', 'value' => null], ['present' => true, 'type' => 'string', 'value' => '']] as $field) {
            $altered = $versions[0];
            $altered['entry_attributes'][1]['age'] = $field;
            $this->assertNotEmpty($compare->compare($versions[0], $altered, false)['attribute_changes']);
        }
        $this->assertCount(5, scandir($output));
    }

    public function test_raw_and_manifest_tampering_are_rejected(): void
    {
        [$data, $input, $registry, $output] = $this->fixture();
        $http = new Factory;
        Pj0315ProbeFixture::fake($http, Pj0315ProbeFixture::html());
        $result = $this->capture($http, $data, $input, $registry, $output);
        file_put_contents($result['directory'].'/raw.bin', 'x', FILE_APPEND);
        $this->expectException(RuntimeException::class);
        (new ObservationVerifier)->verify($result['directory'], $result['manifest_sha256']);
    }

    public function test_scope_cannot_reset_by_manifest_revision_or_schedule_change(): void
    {
        [$data, , $registry] = $this->fixture();
        $ledger = $this->ledger(new ObservationManifest($data), $registry);
        $ledger->exclusive(fn ($ledger) => $ledger->reserve(901, 1, '2025-01-04T00:45:00Z'));
        $data['revision'] = 2;
        $data['targets'][0]['scheduled_start_at'] = '2025-01-04T10:46:00+09:00';
        $this->expectException(RuntimeException::class);
        (new AttemptLedger(new ObservationManifest($data), $registry))->exclusive(fn () => null);
    }

    public function test_all_four_slots_spent_across_two_races_and_third_slot_is_rejected(): void
    {
        [$data, , $registry] = $this->fixture();
        $second = $data['targets'][0];
        $second['race']['race_id'] = 902;
        $second['race']['race_number'] = 2;
        $second['race']['external_race_id'] = '28:20250104:02';
        $second['scheduled_start_at'] = '2025-01-04T10:47:00+09:00';
        $data['targets'][] = $second;
        $manifest = new ObservationManifest($data);
        $ledger = $this->ledger($manifest, $registry);
        foreach ([[901, 1, '00:45:00'], [902, 1, '00:47:00'], [901, 2, '01:30:00'], [902, 2, '01:32:00']] as [$race, $slot, $time]) {
            $at = '2025-01-04T'.$time.'Z';
            $ledger->exclusive(function ($ledger) use ($race, $slot, $at): void {
                $r = $ledger->reserve($race, $slot, $at);
                $ledger->dispatch($r, $at);
                $ledger->outcome($r, $at, 'SUCCEEDED', 200, str_repeat('a', 64));
            });
        }
        $this->assertCount(12, $ledger->snapshot()['events']);
        foreach ([[901, 3], [901, 1], [903, 1]] as [$race, $slot]) {
            try {
                $ledger->exclusive(fn ($ledger) => $ledger->reserve($race, $slot, '2025-01-04T01:32:01Z'));
                $this->fail('Exhausted or unknown slot accepted.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_cross_race_interval_and_corrupted_ledger_fail_closed(): void
    {
        [$data, , $registry, $output] = $this->fixture();
        $second = $data['targets'][0];
        $second['race'] = [...$second['race'], 'race_id' => 902, 'race_number' => 2, 'external_race_id' => '28:20250104:02'];
        $data['targets'][] = $second;
        $ledger = $this->ledger(new ObservationManifest($data), $registry);
        $ledger->exclusive(function ($ledger): void {
            $r = $ledger->reserve(901, 1, '2025-01-04T00:45:00Z');
            $ledger->dispatch($r, '2025-01-04T00:45:00Z');
            $ledger->outcome($r, '2025-01-04T00:45:01Z', 'SUCCEEDED', 200, str_repeat('a', 64));
        });
        try {
            $ledger->exclusive(fn ($ledger) => $ledger->reserve(902, 1, '2025-01-04T00:45:59.999999Z'));
            $this->fail('Sub-minute cross-race spacing accepted.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
        $snapshot = $ledger->snapshot();
        $snapshot['events'][0]['at'] = '2025-01-04T00:44:00Z';
        $this->expectException(RuntimeException::class);
        AttemptLedger::validate($snapshot);
    }

    public function test_failure_after_raw_save_retains_bytes_and_partial_marker(): void
    {
        [$data, $input, $registry, $output] = $this->fixture();
        $http = new Factory;
        $capture = null;
        $http->fake(function ($request, $options) use (&$capture) {
            $capture = dirname(stream_get_meta_data($options['sink'])['uri']);
            CaptureContract::writeNew($capture, 'result.json', 'SYNTHETIC_STORAGE_COLLISION');

            return Factory::response(Pj0315ProbeFixture::html(), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        });
        try {
            $this->capture($http, $data, $input, $registry, $output);
            $this->fail('Storage collision accepted.');
        } catch (RuntimeException) {
            $this->assertNotNull($capture);
            $this->assertSame(Pj0315ProbeFixture::html(), file_get_contents($capture.'/raw.bin'));
            $this->assertTrue(json_decode(file_get_contents($capture.'/FAILED.json'), true)['partial']);
            $this->assertFileDoesNotExist($capture.'/COMPLETE.json');
            $http->assertSentCount(1);
        }
    }

    public function test_manifest_mutation_and_unknown_effective_source_are_rejected(): void
    {
        [$data, $input, $registry, $output] = $this->fixture();
        $http = new Factory;
        Pj0315ProbeFixture::fake($http, Pj0315ProbeFixture::html());
        $result = $this->capture($http, $data, $input, $registry, $output);
        foreach ([null, 'https://other.invalid/'] as $uri) {
            $analysis = ObservationCaptureService::analyze($data['targets'][0], Pj0315ProbeFixture::html(),
                ['http_status' => 200, 'failure' => null, 'effective_uri' => $uri, 'content_type' => 'text/html']);
            $this->assertSame('SOURCE_NOT_VERIFIED', $analysis['failure_reason']);
        }
        file_put_contents($result['directory'].'/manifest.json', ' ', FILE_APPEND);
        $this->expectException(RuntimeException::class);
        (new ObservationVerifier)->verify($result['directory'], $result['manifest_sha256']);
    }

    public function test_synthetic_transport_without_fake_never_opens_network(): void
    {
        [$data, $input, $registry, $output] = $this->fixture();
        $http = new Factory;
        $http->allowStrayRequests();
        $result = $this->capture($http, $data, $input, $registry, $output);
        $this->assertSame('FAILED', $result['state']);
        $this->assertSame('TRANSPORT_FAILURE', json_decode(file_get_contents($result['directory'].'/private-response.json'), true)['failure']);
        $this->assertTrue($http->preventingStrayRequests());
    }

    public function test_valid_decision_order_does_not_change_preflight_and_wrong_budget_or_storage_is_rejected(): void
    {
        [$data, $input] = $this->fixture();
        $data['limits'] = array_reverse($data['limits'], true);
        $manifest = new ObservationManifest($data);
        $at = new \DateTimeImmutable('2025-01-04T00:45:00Z');
        $input['decisions'] = array_reverse($input['decisions']);
        $this->assertSame('OFFLINE_SIMULATION_VALIDATED', (new ObservationPreflight)->evaluate($manifest, 901, 1, $input, Contract::SIMULATED, $at)['status']);
        foreach (['D2', 'D7'] as $id) {
            $modified = $input;
            foreach ($modified['decisions'] as &$decision) {
                if ($decision['decision_id'] === $id) {
                    $decision['approved_value'][$id === 'D2' ? 'max_attempts' : 'observation_storage_sha256'] = $id === 'D2' ? 5 : str_repeat('a', 64);
                }
            }
            unset($decision);
            ApprovalPreflightFixture::sealApprovals($modified);
            $result = (new ObservationPreflight)->evaluate($manifest, 901, 1, $modified, Contract::SIMULATED, $at);
            $this->assertContains('OBSERVATION_BUDGET_STORAGE_OR_VALIDITY_NOT_BOUND', $result['blocking_reasons']);
        }
    }

    public function test_unknown_frame_is_not_invented_as_an_identity_change(): void
    {
        [$data] = $this->fixture();
        $entries = $data['targets'][0]['entries'];
        $baseline = $entries;
        $baseline[0]['frame_number'] = null;
        $result = (new RosterDifferenceEvaluator)->roster($baseline, $entries);
        $this->assertSame('ROSTER_UNCHANGED', $result['status']);
        $this->assertSame('BIKE_REGISTRATION_SET_MATCH', $result['entrant_identity']);
        $this->assertSame('NOT_ESTABLISHED', $result['frame_comparison']);
        $this->assertSame([1], $result['unknown_frames']);
    }

    public function test_removed_complete_event_suffix_is_not_treated_as_unused_budget(): void
    {
        [$data, , $registry, $output] = $this->fixture();
        $manifest = new ObservationManifest($data);
        $ledger = $this->ledger($manifest, $registry);
        $ledger->exclusive(function ($ledger): void {
            $r = $ledger->reserve(901, 1, '2025-01-04T00:45:00Z');
            $ledger->dispatch($r, '2025-01-04T00:45:00Z');
            $ledger->outcome($r, '2025-01-04T00:45:01Z', 'SUCCEEDED', 200, str_repeat('a', 64));
        });
        $prefix = hash('sha256', $data['authorization_id'].'|'.$data['approval_scope']);
        foreach ($ledger->snapshot()['events'] as $event) {
            $name = sprintf('event-%03d.json', $event['sequence']);
            $this->assertTrue(rename($registry.'/'.$prefix.'/'.$name, $output.'/retained-'.$name));
        }
        $this->expectException(RuntimeException::class);
        (new AttemptLedger($manifest, $registry))->exclusive(fn () => null);
    }

    public function test_missing_ledger_is_unknown_and_does_not_create_an_available_scope(): void
    {
        [$data, , $registry] = $this->fixture();
        try {
            (new AttemptLedger(new ObservationManifest($data), $registry))->exclusive(fn () => null);
            $this->fail('Missing ledger inferred available.');
        } catch (RuntimeException) {
            $this->assertSame(['.', '..'], scandir($registry));
        }
    }

    public function test_historical_comparison_refuses_existing_output_before_reading_any_sources(): void
    {
        [, , , $output] = $this->fixture();
        CaptureContract::writeNew($output, 'existing.json', 'SYNTHETIC_EXISTING_ARTIFACT');
        try {
            (new HistoricalComparison)->save($output, ['/NOT_READ/source.json', str_repeat('a', 64), '/NOT_READ/probe', str_repeat('b', 64)]);
            $this->fail('Existing artifact directory modified.');
        } catch (RuntimeException) {
            $this->assertSame(['.', '..', 'existing.json'], scandir($output));
            $this->assertSame('SYNTHETIC_EXISTING_ARTIFACT', file_get_contents($output.'/existing.json'));
        }
    }

    private function capture(Factory $http, array $data, array $input, string $registry, string $output, int $slot = 1): array
    {
        $manifest = new ObservationManifest($data);

        return (new ObservationCaptureService(new Pj0315ObservationTransport($http)))->run($manifest, 901, $slot, $input,
            Contract::SIMULATED, new \DateTimeImmutable($input['budget']['planned_attempt']['request_started_at']),
            $this->ledger($manifest, $registry), $output);
    }

    private function ledger(ObservationManifest $manifest, string $registry): AttemptLedger
    {
        if (! isset($this->initialized[$registry])) {
            AttemptLedger::initializeForSimulation($manifest, $registry);
            $this->initialized[$registry] = true;
        }

        return new AttemptLedger($manifest, $registry);
    }

    private function fixture(): array
    {
        $directory = CompositionResultTemporaryDirectory::create();
        $this->owned[] = $directory;
        $root = $directory->path();
        foreach (['storage', 'storage/run', 'registry'] as $name) {
            mkdir($root.'/'.$name, 0700);
        }
        [$manifest, $input] = Fixture::make($root.'/storage');

        return [$manifest, $input, $root.'/registry', $root.'/storage/run'];
    }
}
