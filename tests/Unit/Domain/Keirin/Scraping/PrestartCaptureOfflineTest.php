<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Scraping;

use App\Domain\Keirin\Scraping\Parsers\RaceDetailParser;
use App\Domain\Keirin\Scraping\PrestartCapture\CaptureRequest;
use App\Domain\Keirin\Scraping\PrestartCapture\CaptureResponse;
use App\Domain\Keirin\Scraping\PrestartCapture\CaptureStore;
use App\Domain\Keirin\Scraping\PrestartCapture\CaptureVerifier;
use App\Domain\Keirin\Scraping\PrestartCapture\Contract;
use App\Domain\Keirin\Scraping\PrestartCapture\FixtureTransport;
use App\Domain\Keirin\Scraping\PrestartCapture\OfflineBridge;
use App\Domain\Keirin\Scraping\PrestartCapture\OfflineTransport;
use App\Domain\Keirin\Scraping\PrestartCapture\Projection;
use App\Domain\Keirin\Scraping\PrestartEvidence\Attributes\BundleFiles;
use App\Domain\Keirin\Scraping\PrestartEvidence\EvidenceFiles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\CompositionResultTemporaryDirectory;

/** All race data, response metadata and transport clocks here are explicitly synthetic. */
final class PrestartCaptureOfflineTest extends TestCase
{
    private array $owned = [];

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

    public function test_offline_flow_saves_original_bytes_and_independently_recomputes_all_validation(): void
    {
        $request = $this->request();
        $transport = new FixtureTransport($this->response($request));
        $saved = (new OfflineBridge($transport, new CaptureStore($this->root())))->capture($request);
        $report = (new CaptureVerifier)->verify($saved->path, $saved->manifestSha256);
        $this->assertSame(1, $transport->calls);
        $this->assertSame($saved->validation, $report);
        $this->assertSame('VERIFIED', $report['status']);
        $this->assertSame($this->raw(), EvidenceFiles::read($saved->path, 'raw.bin'));
        $this->assertSame([1, 2, 3, 4, 6], array_column($report['roster']['entries'], 'bike_number'));
        $this->assertSame('000001', $report['roster']['entries'][0]['external_player_id']);
        $this->assertSame('SIMULATED_BEFORE_SAME_RAW_START', $report['timing']['received_vs_schedule']);
        $this->assertSame('STRICTLY_ORDERED', $report['timing']['request_response_order']);
        $this->assertNotSame($report['timing']['request_started_at'], $report['timing']['response_received_at']);
        $this->assertNotNull($report['timing']['raw_saved_at']);
        $this->assertNull($report['timing']['historical_raw_saved_at']);
        $this->assertSame('CURRENT_OFFLINE_COPY_AFTER_FSYNC', $report['timing']['raw_saved_at_scope']);
        $this->assertSame('AT_OR_AFTER', $report['timing']['current_copy_saved_vs_input_as_of']);
        $this->assertSame('NOT_ESTABLISHED', $report['timing']['prediction_input_availability']);
        foreach (Contract::restrictions() as $key => $value) {
            $this->assertSame($value, $report[$key]);
        }
        $this->assertSame(0600, fileperms($saved->path.'/raw.bin') & 0777);
        foreach (['manifest.json', 'request.json', 'response.json', 'validation.json'] as $file) {
            $this->assertStringNotContainsString('fixture-secret', EvidenceFiles::read($saved->path, $file));
        }
        $this->assertFileExists($saved->path.'/COMPLETE.json');
    }

    #[DataProvider('failedResponses')]
    public function test_failed_response_evidence_is_retained_but_never_published_as_complete(array $changes, string $reason): void
    {
        $request = $this->request();
        $transport = new FixtureTransport($this->response($request, $changes));
        $saved = (new OfflineBridge($transport, new CaptureStore($this->root())))->capture($request);
        $this->assertSame(1, $transport->calls); // No automatic redirects or retries.
        $this->assertSame('FAILED', $saved->validation['status']);
        $this->assertContains($reason, $saved->validation['reasons']);
        $this->assertFileExists($saved->path.'/FAILED.json');
        $this->assertFileDoesNotExist($saved->path.'/COMPLETE.json');
        $this->assertSame($saved->validation, (new CaptureVerifier)->verify($saved->path, $saved->manifestSha256));
        if (array_key_exists('body', $changes) && $changes['body'] === null) {
            $this->assertFileDoesNotExist($saved->path.'/raw.bin');
            $this->assertNull($saved->validation['timing']['raw_saved_at']);
        } else {
            $this->assertSame($changes['body'] ?? $this->raw(), EvidenceFiles::read($saved->path, 'raw.bin'));
        }
    }

    public static function failedResponses(): iterable
    {
        yield 'connection failure' => [['httpStatus' => null, 'body' => null, 'effectiveUri' => null, 'failureClass' => 'CONNECTION_FAILURE'], 'CONNECTION_FAILURE'];
        yield 'timeout' => [['httpStatus' => null, 'body' => null, 'failureClass' => 'TIMEOUT'], 'TIMEOUT'];
        foreach ([301, 302, 307, 308] as $status) {
            yield 'redirect '.$status => [['httpStatus' => $status], 'HTTP_3XX_REJECTED'];
        }
        yield 'redirect detected' => [['redirectDetected' => true], 'REDIRECT_REFUSED'];
        yield 'redirect unknown' => [['redirectDetected' => null], 'REDIRECT_UNKNOWN'];
        yield '429' => [['httpStatus' => 429], 'HTTP_RESPONSE_NOT_OK'];
        yield '503' => [['httpStatus' => 503], 'HTTP_RESPONSE_NOT_OK'];
        yield 'effective host mismatch' => [['effectiveUri' => 'https://other.invalid/pc/racelive'], 'SOURCE_NOT_ALLOWED'];
        yield 'effective path mismatch' => [['effectiveUri' => 'https://keirin.jp/other'], 'SOURCE_NOT_ALLOWED'];
        yield 'effective URI unknown' => [['effectiveUri' => null], 'SOURCE_UNVERIFIED'];
        yield 'different attempt ID' => [['attemptId' => str_repeat('e', 32)], 'REQUEST_RESPONSE_ID_MISMATCH'];
        yield 'different capture ID' => [['captureId' => str_repeat('f', 32)], 'REQUEST_RESPONSE_ID_MISMATCH'];
        yield 'size mismatch' => [['sealMismatch' => 'bytes'], 'RAW_ABSENT_EMPTY_OR_SEAL_MISMATCH'];
        yield 'SHA mismatch' => [['sealMismatch' => 'sha256'], 'RAW_ABSENT_EMPTY_OR_SEAL_MISMATCH'];
        yield 'empty actual body' => [['body' => ''], 'RAW_ABSENT_EMPTY_OR_SEAL_MISMATCH'];
        yield 'request and receive equal' => [['requestStartedAt' => '2025-01-01T00:01:00Z'], 'NON_STRICT_REQUEST_RESPONSE_ORDER'];
        yield 'request after receive' => [['requestStartedAt' => '2025-01-01T09:02:00+09:00'], 'NON_STRICT_REQUEST_RESPONSE_ORDER'];
    }

    #[DataProvider('timings')]
    public function test_scenario_timing_does_not_claim_historical_availability(array $changes, string $classification, string $order): void
    {
        $request = $this->request();
        $saved = (new OfflineBridge(new FixtureTransport($this->response($request, $changes)), new CaptureStore($this->root())))->capture($request);
        $this->assertSame('VERIFIED', $saved->validation['status']);
        $this->assertSame($classification, $saved->validation['timing']['received_vs_schedule']);
        $this->assertSame($order, $saved->validation['timing']['request_response_order']);
        $this->assertSame('UNKNOWN', $saved->validation['timing']['clock_trust']);
        $this->assertFalse($saved->validation['historical_as_of_available']);
    }

    public static function timings(): iterable
    {
        yield 'before' => [[], 'SIMULATED_BEFORE_SAME_RAW_START', 'STRICTLY_ORDERED'];
        yield 'same schedule instant UTC' => [['responseReceivedAt' => '2025-01-01T01:00:00Z'], 'SIMULATED_AT_OR_AFTER_SAME_RAW_START', 'STRICTLY_ORDERED'];
        yield 'after' => [['responseReceivedAt' => '2025-01-01T10:00:01+09:00'], 'SIMULATED_AT_OR_AFTER_SAME_RAW_START', 'STRICTLY_ORDERED'];
        yield 'unknown clock' => [['timingProvenance' => 'UNKNOWN'], 'UNKNOWN', 'STRICTLY_ORDERED'];
        yield 'unknown request start' => [['requestStartedAt' => null], 'SIMULATED_BEFORE_SAME_RAW_START', 'UNKNOWN'];
        yield 'unknown received' => [['responseReceivedAt' => null], 'UNKNOWN', 'UNKNOWN'];
    }

    #[DataProvider('invalidHistoricalBindings')]
    public function test_historical_request_mode_requires_bound_log_evidence_despite_response_flags(array $responseChanges, array $sourceChanges): void
    {
        $request = $this->request(['mode' => 'HISTORICAL_RAW_REPLAY']);
        $response = $this->response($request, array_replace([
            'requestStartedAt' => null, 'timingProvenance' => 'HISTORICAL_FETCH_LOG',
            'historicalSource' => array_replace($this->historicalSource(), $sourceChanges),
        ], $responseChanges));
        $saved = (new OfflineBridge(new FixtureTransport($response), new CaptureStore($this->root())))->capture($request);
        $this->assertSame($this->raw(), EvidenceFiles::read($saved->path, 'raw.bin'));
        $this->assertSame('FAILED', $saved->validation['status']);
        $this->assertContains('HISTORICAL_SOURCE_BINDING_NOT_ESTABLISHED', $saved->validation['reasons']);
        $this->assertNull($saved->validation['roster']);
        $this->assertFileExists($saved->path.'/FAILED.json');
        $this->assertFileDoesNotExist($saved->path.'/COMPLETE.json');
        $this->assertSame($saved->validation, (new CaptureVerifier)->verify($saved->path, $saved->manifestSha256));
        foreach (Contract::restrictions() as $key => $value) {
            $this->assertSame($value, $saved->validation[$key]);
        }
    }

    public static function invalidHistoricalBindings(): iterable
    {
        foreach (['SIMULATED_SCENARIO', 'UNKNOWN'] as $provenance) {
            yield $provenance.' null start' => [['timingProvenance' => $provenance], []];
            yield $provenance.' invented start' => [['timingProvenance' => $provenance, 'requestStartedAt' => '2025-01-01T09:00:00+09:00'], []];
            yield $provenance.' no source' => [['timingProvenance' => $provenance, 'historicalSource' => null], []];
        }
        yield 'no source' => [['historicalSource' => null], []];
        yield 'non-null historical request start' => [['requestStartedAt' => '2025-01-01T09:00:00+09:00'], []];
        yield 'different receipt instant' => [[], ['fetched_at' => '2025-01-01T09:01:01+09:00']];
        yield 'missing receipt' => [['responseReceivedAt' => null], []];
        yield 'both receipt timestamps null' => [['responseReceivedAt' => null], ['fetched_at' => null]];
        yield 'invalid timestamp' => [[], ['fetched_at' => '2025-02-30T09:01:00+09:00']];
        yield 'timestamp without offset' => [[], ['fetched_at' => '2025-01-01T09:01:00']];
        yield 'invalid log ID' => [[], ['fetch_log_id' => 0]];
        yield 'log ID wrong type' => [[], ['fetch_log_id' => '1']];
        yield 'empty parser version' => [[], ['source_parser_version' => '']];
        yield 'blank parser version' => [[], ['source_parser_version' => ' ']];
        yield 'invalid reference hash' => [[], ['reference_sha256' => 'unverified']];
        yield 'reference hash wrong type' => [[], ['reference_sha256' => []]];
        yield 'source raw SHA mismatch' => [[], ['raw_sha256' => str_repeat('0', 64)]];
        yield 'source raw size mismatch' => [[], ['raw_bytes' => 1]];
    }

    public function test_historical_request_mode_accepts_only_bound_evidence_and_equivalent_offset_instants(): void
    {
        $request = $this->request(['mode' => 'HISTORICAL_RAW_REPLAY']);
        $response = $this->response($request, ['requestStartedAt' => null, 'timingProvenance' => 'HISTORICAL_FETCH_LOG',
            'historicalSource' => $this->historicalSource()]);
        $saved = (new OfflineBridge(new FixtureTransport($response), new CaptureStore($this->root())))->capture($request);
        $this->assertSame('2025-01-01T00:01:00Z', $response->historicalSource['fetched_at']);
        $this->assertSame('2025-01-01T09:01:00+09:00', $response->responseReceivedAt);
        $this->assertSame('VERIFIED', $saved->validation['status']);
        $this->assertSame('HISTORICAL_RAW_REPLAY', $saved->validation['mode']);
        $this->assertNull($saved->validation['timing']['request_started_at']);
        $this->assertSame($saved->validation, (new CaptureVerifier)->verify($saved->path, $saved->manifestSha256));
        $this->assertSame($this->raw(), EvidenceFiles::read($saved->path, 'raw.bin'));
        $this->assertFileExists($saved->path.'/COMPLETE.json');
        $this->assertFileDoesNotExist($saved->path.'/FAILED.json');
    }

    #[DataProvider('invalidSimulatedBindings')]
    public function test_simulated_request_mode_cannot_claim_historical_source_evidence(string $provenance, bool $withSource): void
    {
        $request = $this->request();
        $response = $this->response($request, ['requestStartedAt' => null, 'timingProvenance' => $provenance,
            'historicalSource' => $withSource ? $this->historicalSource() : null]);
        $saved = (new OfflineBridge(new FixtureTransport($response), new CaptureStore($this->root())))->capture($request);
        $this->assertSame('FAILED', $saved->validation['status']);
        $this->assertContains('HISTORICAL_SOURCE_BINDING_NOT_ESTABLISHED', $saved->validation['reasons']);
        $this->assertSame($this->raw(), EvidenceFiles::read($saved->path, 'raw.bin'));
        $this->assertFileExists($saved->path.'/FAILED.json');
        $this->assertFileDoesNotExist($saved->path.'/COMPLETE.json');
        $this->assertSame($saved->validation, (new CaptureVerifier)->verify($saved->path, $saved->manifestSha256));
    }

    public static function invalidSimulatedBindings(): iterable
    {
        yield 'simulated with historical source' => ['SIMULATED_SCENARIO', true];
        yield 'unknown with historical source' => ['UNKNOWN', true];
        yield 'historical provenance and source' => ['HISTORICAL_FETCH_LOG', true];
        yield 'historical provenance without source' => ['HISTORICAL_FETCH_LOG', false];
    }

    #[DataProvider('unsupported')]
    public function test_overview_category_is_checked_before_any_mens_detail_parser(?string $type, ?string $dbType, string $status, string $reason): void
    {
        $parser = $this->createMock(RaceDetailParser::class);
        $parser->expects($this->never())->method('parse');
        $request = $this->request(['databaseRaceType' => $dbType]);
        $response = $this->response($request, ['body' => $this->raw($type, false)]);
        $saved = (new OfflineBridge(new FixtureTransport($response), new CaptureStore($this->root(), new CaptureVerifier(new Projection($parser)))))->capture($request);
        $this->assertSame($status, $saved->validation['status']);
        $this->assertContains($reason, $saved->validation['reasons']);
        $this->assertNull($saved->validation['roster']);
    }

    public static function unsupported(): iterable
    {
        yield 'Girls no detailed section' => ['ガールズ予選', null, 'SKIPPED_UNSUPPORTED_CATEGORY', 'UNSUPPORTED_RACE_CATEGORY'];
        yield 'L class' => ['Ｌ級予選', null, 'SKIPPED_UNSUPPORTED_CATEGORY', 'UNSUPPORTED_RACE_CATEGORY'];
        yield 'unknown cannot use DB Men' => ['未定', 'A級予選', 'SKIPPED_UNSUPPORTED_CATEGORY', 'RAW_CATEGORY_UNKNOWN'];
        yield 'missing cannot use DB Men' => [null, 'S級予選', 'SKIPPED_UNSUPPORTED_CATEGORY', 'RAW_CATEGORY_UNKNOWN'];
        yield 'known contradiction' => ['ガールズ予選', 'S級予選', 'FAILED', 'ROSTER_CATEGORY_MISMATCH'];
    }

    public function test_target_manifest_route_and_year_are_checked_before_transport_and_reservation(): void
    {
        foreach (['year', 'manifest', 'route', 'parameters', 'target', 'timestamp'] as $bad) {
            $root = $this->root();
            try {
                $changes = match ($bad) {
                    'year' => ['target' => [...$this->target(), 'race_date' => '2026-01-01']],
                    'manifest' => ['allowedManifestSha256' => str_repeat('0', 64)],
                    'route' => ['requestUri' => 'https://keirin.jp/forbidden'],
                    'parameters' => ['parameters' => ['disp' => 'PJ0326', 'encp' => 'fixture-secret']],
                    'target' => ['target' => [...$this->target(), 'race_id' => 999]],
                    'timestamp' => ['predictionInputAsOf' => '2025-02-30T00:00:00+09:00'],
                };
                $this->request($changes);
                $this->fail('Invalid request accepted.');
            } catch (RuntimeException) {
                $this->assertSame(['.', '..'], scandir($root));
            }
        }
    }

    public function test_same_content_distinct_capture_keeps_history_and_duplicate_ids_do_not_call_transport(): void
    {
        $root = $this->root();
        $first = $this->request();
        $transport = new FixtureTransport($this->response($first));
        $bridge = new OfflineBridge($transport, new CaptureStore($root));
        $saved = $bridge->capture($first);
        $sha = hash_file('sha256', $saved->path.'/manifest.json');
        try {
            $bridge->capture($first);
            $this->fail('Duplicate capture accepted.');
        } catch (RuntimeException) {
            $this->assertSame(1, $transport->calls);
            $this->assertSame($sha, hash_file('sha256', $saved->path.'/manifest.json'));
        }
        $second = $this->request();
        $other = (new OfflineBridge(new FixtureTransport($this->response($second)), new CaptureStore($root)))->capture($second);
        $this->assertNotSame($saved->path, $other->path);
        $this->assertSame(EvidenceFiles::seal(EvidenceFiles::read($saved->path, 'raw.bin')), EvidenceFiles::seal(EvidenceFiles::read($other->path, 'raw.bin')));
        $duplicateAttempt = $this->request(['attemptId' => $first->attemptId]);
        $this->expectException(RuntimeException::class);
        (new OfflineBridge(new FixtureTransport($this->response($duplicateAttempt)), new CaptureStore($root)))->capture($duplicateAttempt);
    }

    #[DataProvider('tamperFiles')]
    public function test_one_byte_file_tampering_is_rejected_by_independent_verifier(string $file): void
    {
        $request = $this->request();
        $saved = (new OfflineBridge(new FixtureTransport($this->response($request)), new CaptureStore($this->root())))->capture($request);
        $h = fopen($saved->path.'/'.$file, 'r+b');
        fwrite($h, '!');
        fclose($h);
        $this->expectException(RuntimeException::class);
        (new CaptureVerifier)->verify($saved->path, $saved->manifestSha256);
    }

    public static function tamperFiles(): iterable
    {
        foreach (['raw.bin', 'manifest.json', 'request.json', 'response.json', 'validation.json', 'COMPLETE.json'] as $file) {
            yield $file => [$file];
        }
    }

    public function test_raw_save_failure_preserves_failed_record_and_never_cleans_or_publishes(): void
    {
        $root = $this->root();
        $store = new class($root) extends CaptureStore
        {
            protected function write(string $directory, string $name, string $bytes): void
            {
                if ($name === 'raw.bin') {
                    throw new RuntimeException('Isolated raw persistence failure.');
                }
                parent::write($directory, $name, $bytes);
            }
        };
        $request = $this->request();
        $this->expectExceptionMessage('Isolated raw persistence failure');
        try {
            (new OfflineBridge(new FixtureTransport($this->response($request)), $store))->capture($request);
        } finally {
            $this->assertFileExists($root.'/'.$request->captureId.'/FAILED.json');
            $this->assertFileExists($root.'/'.$request->captureId.'/request.json');
            $this->assertFileDoesNotExist($root.'/'.$request->captureId.'/COMPLETE.json');
        }
    }

    public function test_code_drift_is_rejected_before_manifest_publication_with_raw_preserved(): void
    {
        $root = $this->root();
        $store = new class($root) extends CaptureStore
        {
            private int $checks = 0;

            protected function codeSeal(): array
            {
                return ++$this->checks === 1 ? parent::codeSeal() : ['simulated_drift' => true];
            }
        };
        $request = $this->request();
        try {
            (new OfflineBridge(new FixtureTransport($this->response($request)), $store))->capture($request);
            $this->fail('Code drift was published.');
        } catch (RuntimeException $error) {
            $this->assertSame('Offline code seal changed before publication.', $error->getMessage());
            $this->assertFileExists($root.'/'.$request->captureId.'/raw.bin');
            $this->assertFileExists($root.'/'.$request->captureId.'/FAILED.json');
            $this->assertFileDoesNotExist($root.'/'.$request->captureId.'/manifest.json');
            $this->assertFileDoesNotExist($root.'/'.$request->captureId.'/COMPLETE.json');
        }
    }

    public function test_resealed_false_validation_and_contradictory_markers_are_rejected(): void
    {
        foreach (['report', 'marker'] as $tamper) {
            $request = $this->request();
            $saved = (new OfflineBridge(new FixtureTransport($this->response($request)), new CaptureStore($this->root())))->capture($request);
            $hash = $saved->manifestSha256;
            if ($tamper === 'marker') {
                Contract::writeNew($saved->path, 'FAILED.json', EvidenceFiles::json(['state' => 'FAILED']));
            } else {
                $false = [...$saved->validation, 'historical_as_of_available' => true];
                file_put_contents($saved->path.'/validation.json', EvidenceFiles::json($false));
                $manifest = EvidenceFiles::object(EvidenceFiles::read($saved->path, 'manifest.json'));
                $manifest['files']['validation.json'] = EvidenceFiles::seal(EvidenceFiles::json($false));
                $bytes = EvidenceFiles::json($manifest);
                file_put_contents($saved->path.'/manifest.json', $bytes);
                $hash = hash('sha256', $bytes);
            }
            try {
                (new CaptureVerifier)->verify($saved->path, $hash);
                $this->fail('False re-sealed evidence or contradictory marker accepted.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_unexpected_transport_exception_is_not_forged_as_a_successful_response(): void
    {
        $transport = new class implements OfflineTransport
        {
            public function exchange(CaptureRequest $request): CaptureResponse
            {
                throw new RuntimeException('Controlled transport exception.');
            }
        };
        $root = $this->root();
        $request = $this->request();
        $this->expectExceptionMessage('Controlled transport exception');
        try {
            (new OfflineBridge($transport, new CaptureStore($root)))->capture($request);
        } finally {
            $this->assertFileExists($root.'/'.$request->captureId.'/FAILED.json');
            $this->assertFileDoesNotExist($root.'/'.$request->captureId.'/response.json');
            $this->assertFileDoesNotExist($root.'/'.$request->captureId.'/raw.bin');
        }
    }

    public function test_raw_race_and_both_roster_identity_tables_must_match_the_fixed_target(): void
    {
        foreach (['race' => str_replace('20250101', '20250102', $this->raw()),
            'summary' => str_replace('"numPlayer":"000001"', '"numPlayer":"000009"', $this->raw()),
            'registration' => str_replace('000001', '000009', $this->raw())] as $body) {
            $request = $this->request();
            $saved = (new OfflineBridge(new FixtureTransport($this->response($request, ['body' => $body])), new CaptureStore($this->root())))->capture($request);
            $this->assertSame('FAILED', $saved->validation['status']);
            $this->assertNull($saved->validation['roster']);
        }
    }

    public function test_old_evidence_code_seals_remain_unchanged_and_invalid_cleanup_roots_are_not_used(): void
    {
        $old = EvidenceFiles::codeSeal();
        $attributes = BundleFiles::codeSeal();
        $request = $this->request();
        (new OfflineBridge(new FixtureTransport($this->response($request)), new CaptureStore($this->root())))->capture($request);
        $this->assertSame($old, EvidenceFiles::codeSeal());
        $this->assertSame($attributes, BundleFiles::codeSeal());
        foreach (['', '.', '..', '/var/www/NeoBicycleRaceInformationWeb', '/home/shinya/Desktop/Trash'] as $bad) {
            try {
                new CaptureStore($bad);
                $this->fail('Invalid root accepted.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function root(): string
    {
        $directory = CompositionResultTemporaryDirectory::create();
        $this->owned[] = $directory;
        $root = $directory->path().'/captures';
        if (! mkdir($root, 0700)) {
            throw new RuntimeException('Cannot create dedicated artificial evidence root.');
        }

        return $root;
    }

    private function target(): array
    {
        return ['race_id' => 1, 'race_date' => '2025-01-01', 'track_code' => '11', 'race_number' => 1,
            'entries' => array_map(fn ($bike, $i) => ['bike_number' => $bike, 'external_player_id' => sprintf('%06d', $i + 1)], [1, 2, 3, 4, 6], range(0, 4))];
    }

    private function request(array $changes = []): CaptureRequest
    {
        $manifest = ['version' => 'OFFLINE-ROSTER-TARGETS-v1', 'targets' => [$this->target()]];

        return new CaptureRequest(...array_replace(['captureId' => bin2hex(random_bytes(16)), 'attemptId' => bin2hex(random_bytes(16)),
            'target' => $this->target(), 'requestUri' => 'https://keirin.jp/pc/racelive',
            'parameters' => ['disp' => 'PJ0315', 'encp' => 'fixture-secret'], 'allowedManifest' => $manifest,
            'allowedManifestSha256' => hash('sha256', EvidenceFiles::json($manifest)), 'manifestReference' => 'synthetic-fixture-v1',
            'mode' => 'SIMULATED_SCENARIO', 'predictionInputAsOf' => '2025-01-01T09:30:00+09:00'], $changes));
    }

    private function response(CaptureRequest $request, array $changes = []): CaptureResponse
    {
        if (isset($changes['sealMismatch'])) {
            $seal = EvidenceFiles::seal($this->raw());
            $changes['declaredRawSeal'] = array_replace($seal, [$changes['sealMismatch'] => $changes['sealMismatch'] === 'bytes' ? $seal['bytes'] + 1 : str_repeat('0', 64)]);
            unset($changes['sealMismatch']);
        }

        return new CaptureResponse(...array_replace(['captureId' => $request->captureId, 'attemptId' => $request->attemptId,
            'requestStartedAt' => '2025-01-01T09:00:00+09:00', 'responseReceivedAt' => '2025-01-01T09:01:00+09:00',
            'httpStatus' => 200, 'effectiveUri' => $request->requestUri, 'redirectDetected' => false, 'body' => $this->raw(),
            'contentType' => 'text/html; charset=UTF-8', 'timingProvenance' => 'SIMULATED_SCENARIO'], $changes));
    }

    private function historicalSource(): array
    {
        return ['fetch_log_id' => 1, 'fetched_at' => '2025-01-01T00:01:00Z',
            'raw_bytes' => strlen($this->raw()), 'raw_sha256' => hash('sha256', $this->raw()),
            'reference_sha256' => str_repeat('a', 64), 'source_parser_version' => 'SYNTHETIC-PJ0315-TEST-v1'];
    }

    private function raw(?string $type = 'A級予選', bool $includeDetails = true): string
    {
        $entries = $summary = [];
        foreach ($this->target()['entries'] as $entry) {
            $entries[] = ['syaban' => (string) $entry['bike_number'], 'sensyuRegistNo' => $entry['external_player_id'], 'wakuban' => null];
            $summary[] = ['carNum' => (string) $entry['bike_number'], 'numPlayer' => $entry['external_player_id']];
        }
        $pc = ['C0201data' => ['selKaisai' => '20250101', 'selKjyoCd' => '11', 'selRaceNo' => '1',
            'C0201racedtl' => ['syumoku' => $type, 'aftStartTime' => '10:00', 'C0201sensyu' => $summary]]];

        return '<!-- SYNTHETIC OFFLINE TEST ONLY --><script>jsonData["PC0201"] = '.json_encode($pc, JSON_THROW_ON_ERROR).';'
            .($includeDetails ? 'jsonData["PJ0315"] = '.json_encode(['sensyuTypeInfo' => $entries], JSON_THROW_ON_ERROR).';' : '').'</script>';
    }
}
