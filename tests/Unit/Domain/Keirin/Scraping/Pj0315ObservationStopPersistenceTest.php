<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Scraping;

use App\Domain\Keirin\Scraping\PrestartCapture\Observation\AttemptLedger;
use App\Domain\Keirin\Scraping\PrestartCapture\Observation\ObservationCaptureService;
use App\Domain\Keirin\Scraping\PrestartCapture\Observation\ObservationContract;
use App\Domain\Keirin\Scraping\PrestartCapture\Observation\ObservationManifest;
use App\Domain\Keirin\Scraping\PrestartCapture\Operational\Contract;
use App\Domain\Keirin\Scraping\PrestartEvidence\EvidenceFiles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\CompositionResultTemporaryDirectory;
use Tests\Support\ObservationRunnerFixture;

final class Pj0315ObservationStopPersistenceTest extends TestCase
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

    #[DataProvider('stoppingObservations')]
    public function test_durable_outcome_alone_blocks_next_regular_slot_after_process_exit(string $variation, string $reason): void
    {
        [$file, $sha, $data] = $this->fixture($variation);
        $child = $this->child($file, $sha, 'interrupt');
        $this->assertSame(73, $child->getExitCode(), $child->getErrorOutput().$child->getOutput());
        $root = dirname($file);
        $boundary = $this->document($root.'/boundary.json');
        $this->assertSame('AFTER_OUTCOME_BEFORE_STOP', $boundary['boundary']);
        $this->assertSame($reason, $boundary['stop_reason']);
        $this->assertSame(1, $boundary['http_calls']);
        $events = $boundary['snapshot']['events'];
        $this->assertSame(['RESERVED', 'DISPATCH', 'OUTCOME'], array_column($events, 'kind'));
        $this->assertSame('SUCCEEDED', $events[2]['state']);
        $capture = $data['output'].'/'.$events[0]['attempt_id'];
        $raw = file_get_contents($capture.'/raw.bin');
        $response = $this->document($capture.'/private-response.json');
        $analysis = ObservationCaptureService::analyze($data['manifest']['targets'][0], $raw, $response);
        $this->assertSame('OBSERVATION_VERIFIED', $analysis['status']);
        $this->assertTrue($analysis['stop_scope']);
        $this->assertSame($reason, $analysis['stop_reason']);
        $before = $this->snapshot($data['registry']);
        $rawSeal = EvidenceFiles::seal($raw);
        // A fresh Service and ledger in a new PHP process must reject a valid, unspent slot before dispatch.
        $resume = $this->childResult($file, $sha, 'resume');
        $this->assertSame('OFFLINE_SIMULATION_VALIDATED', $resume['preflight_status']);
        $this->assertSame('BLOCKED', $resume['result']['status'], json_encode($resume, JSON_THROW_ON_ERROR));
        $this->assertSame(0, $resume['http_calls']);
        $this->assertSame('Whole authorization is STOPPED by observation outcome.', $resume['result']['reason']);
        $this->assertSame($boundary['snapshot'], $resume['snapshot']);
        $this->assertSame($before, $this->snapshot($data['registry']));
        $this->assertSame($rawSeal, EvidenceFiles::seal(file_get_contents($capture.'/raw.bin')));
        $this->assertSame(['.', '..'], scandir($data['second_output']));
        $reserve = $this->childResult($file, $sha, 'reserve');
        $this->assertSame('OFFLINE_SIMULATION_VALIDATED', $reserve['preflight_status']);
        $this->assertSame('BLOCKED', $reserve['result']['status']);
        $this->assertSame('Whole authorization is STOPPED by observation outcome.', $reserve['result']['reason']);
        $this->assertSame(0, $reserve['http_calls']);
        $this->assertSame($before, $this->snapshot($data['registry']));
        $outcome = $events[2];
        $this->assertTrue($outcome['stop_scope']);
        $this->assertSame($reason, $outcome['stop_reason']);
        $this->assertTrue($outcome['budget_spent']);
        AttemptLedger::validate($boundary['snapshot']);
    }

    public static function stoppingObservations(): iterable
    {
        yield 'roster' => ['roster', 'ROSTER_DIFFERENCE_UNKNOWN_REASON'];
        yield 'schedule' => ['schedule', 'SCHEDULE_REVISED_OR_UNKNOWN'];
    }

    public function test_completed_unchanged_observation_allows_the_next_regular_slot_in_a_new_process(): void
    {
        [$file, $sha] = $this->fixture('normal');
        $first = $this->childResult($file, $sha, 'complete');
        $this->assertSame('COMPLETE', $first['result']['state']);
        $this->assertFalse($first['result']['stop_scope']);
        $this->assertSame(1, $first['http_calls']);
        $next = $this->childResult($file, $sha, 'resume');
        $this->assertSame('OFFLINE_SIMULATION_VALIDATED', $next['preflight_status']);
        $this->assertSame('COMPLETE', $next['result']['state']);
        $this->assertSame(1, $next['http_calls']);
        $this->assertSame(['RESERVED', 'DISPATCH', 'OUTCOME', 'RESERVED', 'DISPATCH', 'OUTCOME'], array_column($next['snapshot']['events'], 'kind'));
    }

    #[DataProvider('completedObservations')]
    public function test_complete_and_failed_observations_have_independently_verified_stop_decisions(string $variation, string $verified): void
    {
        [$file, $sha] = $this->fixture($variation);
        $saved = $this->childResult($file, $sha, 'complete');
        $this->assertSame(1, $saved['http_calls']);
        $verifiedResult = $this->childResult($file, $sha, 'verify', [$saved['result']['directory'], $saved['result']['manifest_sha256']]);
        $this->assertSame($verified, $verifiedResult['result']['status']);
        $this->assertSame('NOT_AUTHORIZED', $verifiedResult['result']['prediction_use']);
        $this->assertFalse($verifiedResult['result']['historical_as_of_available']);
        $outcome = $saved['snapshot']['events'][2];
        $this->assertSame($variation !== 'normal', $outcome['stop_scope']);
        $this->assertSame($variation === 'timeout' ? 'TIMEOUT' : ($variation === 'http429' ? 'FAILED' : 'SUCCEEDED'), $outcome['state']);
    }

    public static function completedObservations(): iterable
    {
        foreach (['normal', 'roster', 'schedule', 'http429', 'timeout'] as $variation) {
            yield $variation => [$variation, in_array($variation, ['http429', 'timeout'], true) ? 'FAILED_EVIDENCE_VERIFIED' : 'VERIFIED'];
        }
    }

    #[DataProvider('forgedStopDecisions')]
    public function test_independent_verifier_rejects_resealed_stop_information_that_disagrees_with_raw(string $variation, bool $stop, ?string $reason): void
    {
        [$file, $sha] = $this->fixture($variation);
        $saved = $this->childResult($file, $sha, 'complete');
        $directory = $saved['result']['directory'];
        $ledger = $this->document($directory.'/ledger.json');
        $ledger['events'][2]['stop_scope'] = $stop;
        $ledger['events'][2]['stop_reason'] = $reason;
        if ($stop) {
            if (count($ledger['events']) === 3) {
                $ledger['events'][] = ['kind' => 'STOP', 'at' => $ledger['events'][2]['at'], 'reason' => $reason];
            } else {
                $ledger['events'][3]['reason'] = $reason;
            }
        }
        $ledger = $this->rechain($ledger);
        AttemptLedger::validate($ledger);
        $newSha = $this->resealLedger($directory, $ledger);
        $before = $this->snapshot($directory);
        $result = $this->childResult($file, $sha, 'verify', [$directory, $newSha]);
        $this->assertSame('BLOCKED', $result['result']['status']);
        $this->assertSame('Outcome stop decision differs from independent analysis.', $result['result']['reason']);
        $this->assertSame($before, $this->snapshot($directory));
    }

    public static function forgedStopDecisions(): iterable
    {
        yield 'hidden roster stop' => ['roster', false, null];
        yield 'wrong roster reason' => ['roster', true, 'SCHEDULE_REVISED_OR_UNKNOWN'];
        yield 'invented normal stop' => ['normal', true, 'ROSTER_DIFFERENCE_UNKNOWN_REASON'];
    }

    public function test_required_stop_event_is_still_required_before_a_bundle_is_complete(): void
    {
        [$file, $sha] = $this->fixture('roster');
        $saved = $this->childResult($file, $sha, 'complete');
        $directory = $saved['result']['directory'];
        $ledger = $this->document($directory.'/ledger.json');
        $ledger['events'] = array_slice($ledger['events'], 0, 3);
        AttemptLedger::validate($ledger);
        $newSha = $this->resealLedger($directory, $ledger);
        $result = $this->childResult($file, $sha, 'verify', [$directory, $newSha]);
        $this->assertSame('BLOCKED', $result['result']['status']);
        $this->assertSame('Required whole-scope STOP missing.', $result['result']['reason']);
    }

    #[DataProvider('invalidStopDecisions')]
    public function test_missing_invalid_or_unknown_stop_metadata_is_not_treated_as_permission_to_continue(array $replacement, array $remove): void
    {
        [, , $data] = $this->fixture('normal');
        $snapshot = $this->successfulSnapshot($data);
        $snapshot['events'][2] = array_replace($snapshot['events'][2], $replacement);
        foreach ($remove as $key) {
            unset($snapshot['events'][2][$key]);
        }
        $snapshot = $this->rechain($snapshot);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid or missing outcome stop decision.');
        AttemptLedger::validate($snapshot);
    }

    public static function invalidStopDecisions(): iterable
    {
        yield 'legacy outcome' => [[], ['stop_scope', 'stop_reason']];
        yield 'missing flag' => [[], ['stop_scope']];
        yield 'missing reason' => [[], ['stop_reason']];
        foreach ([null, 0, 1, 'false', 'UNKNOWN', []] as $i => $value) {
            yield 'invalid flag '.$i => [['stop_scope' => $value], []];
        }
        yield 'reason without stop' => [['stop_reason' => 'ROSTER_DIFFERENCE_UNKNOWN_REASON'], []];
        yield 'stop without reason' => [['stop_scope' => true], []];
        yield 'unknown reason' => [['stop_scope' => true, 'stop_reason' => 'UNRECOGNIZED'], []];
        yield 'empty reason' => [['stop_scope' => true, 'stop_reason' => ''], []];
        yield 'invalid reason type' => [['stop_scope' => true, 'stop_reason' => []], []];
        yield 'failure without stop' => [['state' => 'FAILED'], []];
        yield 'unspent outcome' => [['budget_spent' => false], []];
    }

    public function test_legacy_ledger_is_refused_without_initialization_or_new_http(): void
    {
        [$file, $sha, $data] = $this->fixture('normal');
        $this->successfulSnapshot($data);
        $prefix = hash('sha256', $data['manifest']['authorization_id'].'|'.$data['manifest']['approval_scope']);
        $scope = $data['registry'].'/'.$prefix.'/scope.json';
        $header = $this->document($scope);
        unset($header['ledger_version']);
        $this->replaceDocument($scope, $header);
        $before = $this->snapshot($data['registry']);
        $result = $this->childResult($file, $sha, 'resume');
        $this->assertSame('OFFLINE_SIMULATION_VALIDATED', $result['preflight_status']);
        $this->assertSame('BLOCKED', $result['result']['status']);
        $this->assertSame('Unsupported ledger version; stopping outcome evidence required.', $result['result']['reason']);
        $this->assertSame(0, $result['http_calls']);
        $this->assertSame($before, $this->snapshot($data['registry']));
    }

    public function test_a_new_reservation_cannot_be_appended_after_a_stopping_outcome_even_with_a_valid_hash_chain(): void
    {
        [$file, $sha] = $this->fixture('normal');
        $this->childResult($file, $sha, 'complete');
        $next = $this->childResult($file, $sha, 'resume');
        $snapshot = $next['snapshot'];
        $snapshot['events'][2]['stop_scope'] = true;
        $snapshot['events'][2]['stop_reason'] = 'SCHEDULE_REVISED_OR_UNKNOWN';
        $snapshot = $this->rechain($snapshot);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Stopping outcome requires its matching whole-scope STOP.');
        AttemptLedger::validate($snapshot);
    }

    private function successfulSnapshot(array $data): array
    {
        $ledger = new AttemptLedger(new ObservationManifest($data['manifest']), $data['registry']);

        return $ledger->exclusive(function ($ledger): array {
            $reservation = $ledger->reserve(901, 1, '2025-01-04T00:45:00Z');
            $ledger->dispatch($reservation, '2025-01-04T00:45:00Z');
            $ledger->outcome($reservation, '2025-01-04T00:45:01Z', 'SUCCEEDED', 200, str_repeat('a', 64), false, null);

            return $ledger->snapshot();
        });
    }

    private function rechain(array $snapshot): array
    {
        $previous = null;
        foreach ($snapshot['events'] as $index => &$event) {
            unset($event['sha256']);
            $event['sequence'] = $index + 1;
            $event['previous_sha256'] = $previous;
            $previous = $event['sha256'] = Contract::digest($event);
        }

        return $snapshot;
    }

    private function resealLedger(string $directory, array $ledger): string
    {
        // Only this test's synthetic bundle is mutated; the independent checks must pass file/chain integrity first.
        $this->replaceDocument($directory.'/ledger.json', $ledger);
        $manifest = $this->document($directory.'/manifest.json');
        $manifest['files']['ledger.json'] = EvidenceFiles::seal(file_get_contents($directory.'/ledger.json'));
        $this->replaceDocument($directory.'/manifest.json', $manifest);
        $sha = hash_file('sha256', $directory.'/manifest.json');
        $marker = is_file($directory.'/COMPLETE.json') ? 'COMPLETE' : 'FAILED';
        $this->replaceDocument($directory.'/'.$marker.'.json', ['state' => $marker, 'manifest_sha256' => $sha]);

        return $sha;
    }

    private function replaceDocument(string $file, array $document): void
    {
        $bytes = json_encode($document, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)."\n";
        $this->assertSame(strlen($bytes), file_put_contents($file, $bytes));
    }

    private function fixture(string $variation): array
    {
        $directory = CompositionResultTemporaryDirectory::create();
        $this->owned[] = $directory;
        $root = $directory->path();
        foreach (['storage', 'storage/first', 'storage/second', 'registry'] as $name) {
            mkdir($root.'/'.$name, 0700);
        }
        [$manifest, $input] = ObservationRunnerFixture::make($root.'/storage');
        AttemptLedger::initializeForSimulation(new ObservationManifest($manifest), $root.'/registry');
        $data = ['variation' => $variation, 'manifest' => $manifest, 'input' => $input,
            'registry' => $root.'/registry', 'output' => $root.'/storage/first', 'second_output' => $root.'/storage/second'];
        $sha = ObservationContract::write($root, 'child-input.json', $data);

        return [$root.'/child-input.json', $sha, $data];
    }

    private function child(string $file, string $sha, string $action, array $extra = []): Process
    {
        $command = [PHP_BINARY, '-d', 'memory_limit=128M', dirname(__DIR__, 4).'/Support/pj0315-observation-stop-process.php', $file, $sha, $action, ...$extra];
        $process = new Process($command);
        $process->run();
        ObservationContract::write(dirname($file), $action.'-process.json', ['command' => $command, 'exit' => $process->getExitCode(),
            'stdout' => $process->getOutput(), 'stderr' => $process->getErrorOutput()]);

        return $process;
    }

    private function childResult(string $file, string $sha, string $action, array $extra = []): array
    {
        $child = $this->child($file, $sha, $action, $extra);
        $this->assertSame(0, $child->getExitCode(), $child->getErrorOutput());
        $result = json_decode($child->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('128M', $result['memory_limit']);

        return $result;
    }

    private function document(string $file): array
    {
        return json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    }

    private function snapshot(string $root): array
    {
        $files = [];
        foreach (scandir($root) as $name) {
            if ($name !== '.' && $name !== '..') {
                $path = $root.'/'.$name;
                $files[$name] = is_dir($path) ? $this->snapshot($path) : EvidenceFiles::seal(file_get_contents($path));
            }
        }

        return $files;
    }
}
