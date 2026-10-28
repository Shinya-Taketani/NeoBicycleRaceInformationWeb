<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Scraping;

use App\Domain\Keirin\Scraping\PrestartCapture\Contract;
use App\Domain\Keirin\Scraping\PrestartCapture\Pj0315Probe\Probe;
use App\Domain\Keirin\Scraping\PrestartCapture\Pj0315Probe\ProbeFailure;
use App\Domain\Keirin\Scraping\PrestartCapture\Pj0315Probe\ScopeAttempt;
use App\Domain\Keirin\Scraping\PrestartCapture\Pj0315Probe\SinglePostTransport;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\CompositionResultTemporaryDirectory;
use Tests\Support\Pj0315ProbeFixture as Fixture;

final class Pj0315ScopeAttemptTest extends TestCase
{
    private CompositionResultTemporaryDirectory $temporary;

    protected function setUp(): void
    {
        $this->temporary = CompositionResultTemporaryDirectory::create();
        foreach (['one', 'two', 'three', 'input', 'calls'] as $name) {
            if (! mkdir($this->path($name), 0700)) {
                throw new RuntimeException('Cannot create synthetic test directory.');
            }
        }
        Fixture::scope($this->temporary->path());
    }

    protected function tearDown(): void
    {
        try {
            $this->temporary->retire($this->temporary->path(), $this->status()->isSuccess() ? 'TEST_COMPLETE' : 'TEST_FAILURE');
        } finally {
            parent::tearDown();
        }
    }

    #[DataProvider('outcomes')]
    public function test_scope_is_consumed_across_roots_and_transport_instances(string $kind, string $state): void
    {
        $http = new Factory;
        $calls = 0;
        if ($kind === 'timeout') {
            $http->preventStrayRequests();
            $http->fake(function () use (&$calls) {
                $calls++;
                throw new ConnectionException('cURL error 28: SYNTHETIC_TIMEOUT');
            });
        } else {
            Fixture::fake($http, Fixture::html(), $kind === 'success' ? 200 : 429);
        }
        (new Probe(new SinglePostTransport($http), $this->scope()))->run(Fixture::target(), $this->path('one'), true);
        $this->assertSame(['state' => $state, 'budget_consumed' => true], $this->scope()->status());
        foreach (['one', 'two', 'three'] as $name) {
            try {
                (new Probe(new SinglePostTransport($http), $this->scope()))->run(Fixture::target(), $this->path($name), true);
                $this->fail('Consumed technical scope re-dispatched.');
            } catch (ProbeFailure $failure) {
                $this->assertFalse($failure->evidence['output_write_started']);
                $this->assertFalse($failure->evidence['evidence_retained']);
            }
        }
        if ($kind === 'timeout') {
            $this->assertSame(1, $calls);
        } else {
            $http->assertSentCount(1);
        }
        $this->assertSame(['.', '..'], scandir($this->path('two')));
    }

    public static function outcomes(): iterable
    {
        yield '200' => ['success', 'HTTP_SUCCEEDED'];
        yield '429' => ['429', 'HTTP_FAILED'];
        yield 'timeout' => ['timeout', 'TIMEOUT'];
    }

    #[DataProvider('unfinishedStates')]
    public function test_reserved_interrupted_and_unknown_states_never_restore_budget(string $kind, string $state): void
    {
        $scope = $this->scope();
        $reservation = $scope->reserve(Fixture::target(), $this->path('one'));
        if ($kind === 'interrupted') {
            $scope->dispatching($reservation);
        } elseif ($kind === 'unknown') {
            file_put_contents($this->path('scope/reservation.json'), '{SYNTHETIC_TRUNCATED');
        }
        $this->assertSame(['state' => $state, 'budget_consumed' => true], $this->scope()->status());
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake();
        try {
            (new Probe(new SinglePostTransport($http), $this->scope()))->run(Fixture::target(), $this->path('two'), true);
            $this->fail('Unfinished or unknown attempt accepted.');
        } catch (ProbeFailure) {
            $http->assertNothingSent();
        }
    }

    public static function unfinishedStates(): iterable
    {
        yield 'reservation without dispatch' => ['reserved', 'RESERVED'];
        yield 'dispatch without outcome' => ['interrupted', 'INTERRUPTED_OR_UNKNOWN'];
        yield 'corrupt reservation' => ['unknown', 'UNKNOWN'];
    }

    public function test_separate_process_restart_cannot_reuse_the_same_sealed_evidence(): void
    {
        [$file, $sha] = Fixture::saved($this->path('input'));
        $first = $this->child($file, $sha, 'one', 'first');
        $first->run();
        $this->assertSame(0, $first->getExitCode(), $first->getErrorOutput());
        $result = json_decode($first->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('128M', $result['memory_limit']);
        $this->assertLessThan(128 * 1024 * 1024, $result['peak_memory_bytes']);
        $second = $this->child($file, $sha, 'two', 'second');
        $second->run();
        $this->assertSame(1, $second->getExitCode(), $second->getErrorOutput());
        $this->assertFileExists($this->path('calls/sent-first.json'));
        $this->assertFileDoesNotExist($this->path('calls/sent-second.json'));
        $this->assertSame('HTTP_SUCCEEDED', $this->scope()->status()['state']);
    }

    public function test_two_concurrent_processes_can_only_dispatch_one_http_fake(): void
    {
        [$file, $sha] = Fixture::saved($this->path('input'));
        $barrier = $this->path('calls/start.json');
        $children = [$this->child($file, $sha, 'one', 'a', $barrier), $this->child($file, $sha, 'two', 'b', $barrier)];
        try {
            foreach ($children as $child) {
                $child->start();
            }
            $until = hrtime(true) + 5_000_000_000;
            while (! is_file($this->path('calls/ready-a.json')) || ! is_file($this->path('calls/ready-b.json'))) {
                if (hrtime(true) >= $until) {
                    $this->fail('Synthetic children failed to reach their start barrier.');
                }
                usleep(1000);
            }
            Contract::writeNew($this->path('calls'), 'start.json', '{}');
        } finally {
            foreach ($children as $child) {
                $child->wait();
            }
        }
        $codes = array_map(fn ($child) => $child->getExitCode(), $children);
        sort($codes);
        $this->assertSame([0, 1], $codes);
        $this->assertCount(1, glob($this->path('calls/sent-*.json')));
        $this->assertSame('HTTP_SUCCEEDED', $this->scope()->status()['state']);
    }

    public function test_unconfirmed_failure_retention_is_null_not_true(): void
    {
        $http = new Factory;
        Fixture::fake($http, Fixture::html());
        $probe = new class(new SinglePostTransport($http), $this->scope()) extends Probe
        {
            protected function write(string $root, string $name, array $data): void
            {
                if (in_array($name, ['result.json', 'FAILED.json'], true)) {
                    throw new RuntimeException('Synthetic unconfirmed retention.');
                }
                parent::write($root, $name, $data);
            }
        };
        try {
            $probe->run(Fixture::target(), $this->path('one'), true);
            $this->fail('Synthetic persistence failure ignored.');
        } catch (ProbeFailure $failure) {
            $this->assertSame('WRITE_STARTED_RETENTION_UNCONFIRMED', $failure->evidence['evidence_status']);
            $this->assertNull($failure->evidence['evidence_retained']);
            $this->assertTrue($failure->evidence['output_write_started']);
            $this->assertFalse($failure->evidence['failed_evidence_confirmed']);
            $this->assertFileExists($this->path('one/raw.bin'));
            $this->assertFileDoesNotExist($this->path('one/FAILED.json'));
        }
    }

    public function test_output_not_provided_generates_no_evidence_and_uninitialized_scope_is_unknown(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake();
        try {
            (new Probe(new SinglePostTransport($http), $this->scope()))->run(Fixture::target(), null, true);
            $this->fail('Output must be explicit.');
        } catch (ProbeFailure $failure) {
            $this->assertSame('NOT_CREATED', $failure->evidence['evidence_status']);
            $this->assertFalse($failure->evidence['evidence_retained']);
            $this->assertFalse($failure->evidence['output_write_started']);
            $this->assertSame('AVAILABLE', $this->scope()->status()['state']);
        }
        $unknown = new ScopeAttempt($this->path('two'));
        $this->assertSame(['state' => 'UNKNOWN', 'budget_consumed' => true], $unknown->status());
        try {
            $unknown->reserve(Fixture::target(), $this->path('three'));
            $this->fail('Missing initialization must not mean unattempted.');
        } catch (RuntimeException) {
            $http->assertNothingSent();
        }
    }

    private function child(string $file, string $sha, string $output, string $id, string $barrier = '-'): Process
    {
        $child = new Process([PHP_BINARY, '-d', 'memory_limit=128M', dirname(__DIR__, 4).'/Support/pj0315-scope-probe.php',
            $this->path('scope'), $file, $sha, $this->path($output), $this->path('calls'), $id, $barrier]);
        $child->setTimeout(10);

        return $child;
    }

    private function path(string $name): string
    {
        return $this->temporary->path().'/'.$name;
    }

    private function scope(): ScopeAttempt
    {
        return new ScopeAttempt($this->path('scope'));
    }
}
