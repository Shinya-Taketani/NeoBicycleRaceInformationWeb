<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Keirin\Scraping\DTO\RaceRosterSnapshotDto;
use App\Domain\Keirin\Scraping\PrestartCapture\CaptureRequest;
use App\Domain\Keirin\Scraping\PrestartCapture\CaptureResponse;
use App\Domain\Keirin\Scraping\PrestartCapture\CaptureStore;
use App\Domain\Keirin\Scraping\PrestartCapture\CaptureVerifier;
use App\Domain\Keirin\Scraping\PrestartCapture\Contract;
use App\Domain\Keirin\Scraping\PrestartCapture\FixtureTransport;
use App\Domain\Keirin\Scraping\PrestartCapture\OfflineBridge;
use App\Domain\Keirin\Scraping\PrestartEvidence\EvidenceFiles;
use App\Domain\Keirin\Statistics\Calculators\Stat46RosterDifferenceCalculator;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\CompositionResultTemporaryDirectory;

/** Real saved 2022..2025 Raw replay; never an HTTP fetch or historical prestart acceptance. */
final class PrestartCaptureReplayTest extends TestCase
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

    public function test_real_cohort_replays_into_new_bundles_with_independent_process_verification_and_frozen_roster_differences(): void
    {
        $cases = $this->cases();
        $receiptFile = getenv('STAT46_ACCEPTANCE_FIXTURE') ?: '/home/shinya/neo-keirin-artifacts/stat46-roster-snapshot-01/pr120-tests-20261024-073619-8Nc4kMdB/logs/roster-real-raw-acceptance.json';
        $receipt = $this->sealedJson($receiptFile, 'f3091e255c89924aaf47bae7d523d6a09973c6b0b564adb3f1d88ae8e7cfb6d4');
        $targets = [];
        foreach ($cases as $case) {
            $target = $this->target($case);
            if (isset($targets[$target['race_id']])) {
                $this->assertSame($targets[$target['race_id']], $target);
            }
            $targets[$target['race_id']] = $target;
        }
        $allowed = ['version' => 'OFFLINE-ROSTER-TARGETS-v1', 'targets' => array_values($targets)];
        $reportDirectory = getenv('PRESTART_TEST_REPORT_DIRECTORY');
        if (is_string($reportDirectory) && $reportDirectory !== '') {
            $reportDirectory .= '/replay-evidence';
            if (! mkdir($reportDirectory, 0700)) {
                throw new RuntimeException('Cannot reserve a new replay report directory.');
            }
            EvidenceFiles::directory($reportDirectory);
            $root = $reportDirectory.'/actual-replays';
            if (! mkdir($root, 0700)) {
                throw new RuntimeException('Cannot reserve a new replay artifact root.');
            }
        } else {
            $root = $this->temporaryRoot();
        }
        $store = new CaptureStore($root);
        $originalRaces = $originalEntries = $groups = $outputs = [];
        $occurrences = $originalVersions = 0;
        foreach ($cases as $case) {
            $raw = $this->realRaw($case);
            $request = new CaptureRequest(bin2hex(random_bytes(16)), bin2hex(random_bytes(16)), $this->target($case),
                $case['log']['request_url'], $case['log']['request_parameters'], $allowed,
                hash('sha256', EvidenceFiles::json($allowed)), 'pr120-fixed-development-rosters', 'HISTORICAL_RAW_REPLAY',
                $case['target']['race']['race_date'].'T00:00:00+09:00');
            $transport = new FixtureTransport($this->response($request, $case, $raw));
            $saved = (new OfflineBridge($transport, $store))->capture($request);
            $result = $saved->validation;
            $this->assertSame(1, $transport->calls);
            $this->assertSame('VERIFIED', $result['status']);
            $this->assertSame('HISTORICAL_RAW_REPLAY', $result['mode']);
            $this->assertSame('MEN', $result['raw_category']);
            $this->assertSame('RECORDED_AT_OR_AFTER_SAME_RAW_START', $result['timing']['received_vs_schedule']);
            $this->assertNull($result['timing']['request_started_at']);
            $this->assertNull($result['timing']['historical_raw_saved_at']);
            $this->assertSame('UNKNOWN', $result['timing']['request_response_order']);
            $this->assertSame('UNKNOWN', $result['original_http_redirect_evidence']);
            $this->assertSame('UNKNOWN', $result['original_http_effective_uri_evidence']);
            $this->assertSame('OFFLINE_REQUEST_RESPONSE_BINDING_ONLY', $result['source_validation']['scope']);
            $this->assertSame('AT_OR_AFTER', $result['timing']['current_copy_saved_vs_input_as_of']);
            $this->assertSame($raw, EvidenceFiles::read($saved->path, 'raw.bin'));
            foreach (Contract::restrictions() as $key => $value) {
                $this->assertSame($value, $result[$key]);
            }
            $this->assertSame($result, (new CaptureVerifier)->verify($saved->path, $saved->manifestSha256));
            $child = $this->independent($saved->path, $saved->manifestSha256);
            $logId = $case['log']['id'];
            $stored = array_values(array_filter($receipt['snapshots'], fn ($row) => $row['scraping_fetch_log_id'] === $logId));
            if (! ($case['additional_nine'] ?? false)) {
                $this->assertCount(1, $stored);
                $reference = $stored[0];
                $this->assertSame($reference['raw_sha256'], hash('sha256', $raw));
                $expected = array_map(fn ($entry) => array_diff_key($entry, ['raw_fields' => true]), $reference['entries']);
                $this->assertSame($expected, $result['roster']['entries']);
                $this->assertEquals(new DateTimeImmutable($reference['fetched_at']), new DateTimeImmutable($result['timing']['response_received_at']));
                $date = (new DateTimeImmutable($reference['race_date']))->setTimezone(new DateTimeZone('Asia/Tokyo'))->format('Y-m-d');
                $this->assertSame($case['target']['race']['race_date'], $date);
                $groups[$reference['race_id']][] = new RaceRosterSnapshotDto([...$reference, 'race_date' => $date, 'entries' => $result['roster']['entries']]);
                $originalRaces[$reference['race_id']] = true;
                foreach ($result['roster']['entries'] as $entry) {
                    $originalEntries[$reference['race_id'].':'.$entry['external_player_id']] = true;
                    $occurrences++;
                }
                $originalVersions++;
            }
            $outputs[] = ['capture_id' => $request->captureId, 'attempt_id' => $request->attemptId,
                'race_id' => $request->target['race_id'], 'race_date' => $request->target['race_date'], 'fetch_log_id' => $logId,
                'relative_directory' => 'actual-replays/'.$request->captureId, 'manifest_sha256' => $saved->manifestSha256,
                'raw_seal' => EvidenceFiles::seal($raw), 'independent_process' => $child, 'additional_nine' => $case['additional_nine'] ?? false];
        }
        $comparisons = [];
        foreach ($receipt['pairs'] as $pair) {
            [$a, $b] = $groups[$pair['race_id']];
            $new = (new Stat46RosterDifferenceCalculator)->calculate($b, $a, new DateTimeImmutable($a->attributes['race_date'].'T00:00:00+09:00'));
            $this->assertSame($pair['comparison']['status'], $new['status']);
            $this->assertSame($pair['comparison']['difference'], $new['difference']);
            $this->assertNull($new['official_entry_change_type']);
            $comparisons[] = ['race_id' => $pair['race_id'], 'status' => $new['status'], 'difference' => $new['difference']];
        }
        $this->assertCount(6, $originalRaces);
        $this->assertCount(39, $originalEntries);
        $this->assertSame(8, $originalVersions);
        $this->assertSame(51, $occurrences);
        $this->assertCount(2, $comparisons);
        if (is_string($reportDirectory) && $reportDirectory !== '') {
            Contract::writeNew($reportDirectory, 'real-replay-verification.json', EvidenceFiles::json([
                'version' => Contract::VERSION, 'original_races' => count($originalRaces), 'original_entries' => count($originalEntries),
                'original_capture_versions' => $originalVersions, 'original_entry_observations' => $occurrences,
                'additional_nine' => count($cases) === 9 ? 'VERIFIED' : 'NOT_AVAILABLE',
                'frozen_stat46_receipt_sha256' => hash_file('sha256', $receiptFile), 'captures' => $outputs,
                'roster_comparisons' => $comparisons, ...Contract::restrictions()]));
        }
    }

    public function test_historical_replay_cannot_invent_a_request_start_from_the_old_fetch_log(): void
    {
        $case = $this->cases()[0];
        $target = $this->target($case);
        $allowed = ['version' => 'OFFLINE-ROSTER-TARGETS-v1', 'targets' => [$target]];
        $request = new CaptureRequest(bin2hex(random_bytes(16)), bin2hex(random_bytes(16)), $target,
            $case['log']['request_url'], $case['log']['request_parameters'], $allowed, hash('sha256', EvidenceFiles::json($allowed)),
            'historical-clock-rejection-only', 'HISTORICAL_RAW_REPLAY');
        $response = $this->response($request, $case, $this->realRaw($case), '2025-01-01T09:00:00+09:00');
        $saved = (new OfflineBridge(new FixtureTransport($response), new CaptureStore($this->temporaryRoot())))->capture($request);
        $this->assertSame('FAILED', $saved->validation['status']);
        $this->assertContains('HISTORICAL_SOURCE_BINDING_NOT_ESTABLISHED', $saved->validation['reasons']);
        $this->assertNull($saved->validation['roster']);
    }

    #[DataProvider('unboundReplayProvenances')]
    public function test_real_raw_replay_with_unbound_timing_stays_failed_in_a_separate_verifier(string $provenance): void
    {
        // The Raw is real; the contradictory response metadata is an isolated rejection fixture only.
        $case = $this->cases()[0];
        $target = $this->target($case);
        $allowed = ['version' => 'OFFLINE-ROSTER-TARGETS-v1', 'targets' => [$target]];
        $request = new CaptureRequest(bin2hex(random_bytes(16)), bin2hex(random_bytes(16)), $target,
            $case['log']['request_url'], $case['log']['request_parameters'], $allowed, hash('sha256', EvidenceFiles::json($allowed)),
            'historical-response-provenance-rejection', 'HISTORICAL_RAW_REPLAY');
        $raw = $this->realRaw($case);
        $response = new CaptureResponse($request->captureId, $request->attemptId, null,
            (new DateTimeImmutable($case['log']['fetched_at']))->format('Y-m-d\TH:i:s.uP'), 200,
            $request->requestUri, false, $raw, $case['log']['content_type'], $provenance);
        $saved = (new OfflineBridge(new FixtureTransport($response), new CaptureStore($this->temporaryRoot())))->capture($request);
        $this->assertSame($raw, EvidenceFiles::read($saved->path, 'raw.bin'));
        $this->assertSame('FAILED', $saved->validation['status']);
        $this->assertContains('HISTORICAL_SOURCE_BINDING_NOT_ESTABLISHED', $saved->validation['reasons']);
        $this->assertFileExists($saved->path.'/FAILED.json');
        $this->assertFileDoesNotExist($saved->path.'/COMPLETE.json');
        $this->assertSame($saved->validation, (new CaptureVerifier)->verify($saved->path, $saved->manifestSha256));
        $this->independent($saved->path, $saved->manifestSha256, 'FAILED');
    }

    public static function unboundReplayProvenances(): iterable
    {
        yield 'simulated flag' => ['SIMULATED_SCENARIO'];
        yield 'unknown flag' => ['UNKNOWN'];
    }

    private function cases(): array
    {
        $path = getenv('STAT38_TEST_FIXTURE') ?: '/home/shinya/neo-keirin-artifacts/stat38-distance-laps-mvp-01-20261021-01/actual-fixture.json';
        $cases = [];
        foreach ([$path => '7ec6fae7e6ff5f8137754986c8f9e8d10bd766b9c65f61aa1252944494ba04dc',
            dirname($path).'/history-fixture.json' => '0344d20e9db4918a6a1a8cc0f691cdea724c22fc1b90c9ce2520ce12a02379ca'] as $file => $hash) {
            foreach ($this->sealedJson($file, $hash)['cases'] as $case) {
                $case['fixture_root'] = dirname($file);
                Contract::target($this->target($case));
                $cases[] = $case;
            }
        }
        $nine = getenv('STAT46_NINE_FIXTURE') ?: '/home/shinya/neo-keirin-artifacts/stat46-roster-snapshot-01/pr120-nine-20261024-073534-oHV4inO8/fixtures/nine-fixture.json';
        if (is_file($nine)) {
            $case = $this->sealedJson($nine, '039c77ad09f052144ba07287b0bdaee039b7c75aaebe7887ce327f9f87e47b1f')['cases'][0];
            $case['fixture_root'] = dirname($nine);
            $case['additional_nine'] = true;
            Contract::target($this->target($case));
            $cases[] = $case;
        }

        return $cases;
    }

    private function sealedJson(string $path, string $hash): array
    {
        if (! is_file($path) || hash_file('sha256', $path) !== $hash) {
            throw new RuntimeException('Required fixed historical metadata missing or seal mismatch.');
        }

        return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    private function target(array $case): array
    {
        $race = $case['target']['race'];

        return ['race_id' => $race['race_id'], 'race_date' => $race['race_date'], 'track_code' => $race['track_code'], 'race_number' => $race['race_number'],
            'entries' => array_map(fn ($entry) => array_intersect_key($entry, array_flip(['bike_number', 'external_player_id'])), $case['target']['entries'])];
    }

    private function realRaw(array $case): string
    {
        Contract::target($this->target($case));
        $path = $case['fixture_root'].'/'.$case['fixture_file'];
        if (realpath($path) !== $path || is_link($path) || filesize($path) !== $case['original']['bytes']
            || hash_file('sha256', $path) !== $case['original']['sha256']) {
            throw new RuntimeException('Fixed real replay Raw missing or changed.');
        }

        return file_get_contents($path);
    }

    private function response(CaptureRequest $request, array $case, string $raw, ?string $started = null): CaptureResponse
    {
        $received = (new DateTimeImmutable($case['log']['fetched_at']))->format('Y-m-d\TH:i:s.uP');

        return new CaptureResponse($request->captureId, $request->attemptId, $started, $received, $case['log']['http_status'],
            $request->requestUri, false, $raw, $case['log']['content_type'], 'HISTORICAL_FETCH_LOG', null,
            ['bytes' => $case['original']['bytes'], 'sha256' => $case['original']['sha256']],
            ['fetch_log_id' => $case['log']['id'], 'fetched_at' => $received, 'raw_bytes' => $case['original']['bytes'],
                'raw_sha256' => $case['original']['sha256'], 'reference_sha256' => hash_file('sha256', $case['fixture_root'].'/'.(($case['additional_nine'] ?? false) ? 'nine-fixture.json' : (str_starts_with($case['fixture_file'], 'history-') ? 'history-fixture.json' : 'actual-fixture.json'))),
                'source_parser_version' => $case['log']['parser_version']]);
    }

    private function independent(string $directory, string $hash, string $expectedStatus = 'VERIFIED'): array
    {
        $code = 'require $argv[1]."/vendor/autoload.php"; if (ini_get("memory_limit")!=="128M") {throw new RuntimeException("Wrong verifier memory limit");} '
            .'$r=(new App\\Domain\\Keirin\\Scraping\\PrestartCapture\\CaptureVerifier)->verify($argv[2],$argv[3]); '
            .'echo json_encode(["status"=>$r["status"],"prediction_use"=>$r["prediction_use"],"pid"=>getmypid(),"peak_memory_bytes"=>memory_get_peak_usage(true)],JSON_THROW_ON_ERROR);';
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=128M', '-r', $code, dirname(__DIR__, 2), $directory, $hash]);
        $process->setTimeout(30);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame('', $process->getErrorOutput());
        $report = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($expectedStatus, $report['status']);
        $this->assertNotSame(getmypid(), $report['pid']);
        $this->assertLessThanOrEqual(128 * 1024 * 1024, $report['peak_memory_bytes']);

        return $report;
    }

    private function temporaryRoot(): string
    {
        $directory = CompositionResultTemporaryDirectory::create();
        $this->owned[] = $directory;
        $root = $directory->path().'/replay';
        if (! mkdir($root, 0700)) {
            throw new RuntimeException('Cannot reserve artificial replay root.');
        }

        return $root;
    }
}
