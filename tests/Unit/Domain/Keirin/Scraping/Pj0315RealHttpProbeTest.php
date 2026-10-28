<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Scraping;

use App\Domain\Keirin\Scraping\PrestartCapture\Contract;
use App\Domain\Keirin\Scraping\PrestartCapture\Pj0315Probe\Probe;
use App\Domain\Keirin\Scraping\PrestartCapture\Pj0315Probe\SinglePostTransport;
use App\Domain\Keirin\Scraping\PrestartCapture\Pj0315Probe\Target;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\TransferStats;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\CompositionResultTemporaryDirectory;
use Tests\Support\Pj0315ProbeFixture as Fixture;

final class Pj0315RealHttpProbeTest extends TestCase
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

    #[DataProvider('statuses')]
    public function test_single_post_statuses_never_retry_or_follow_redirects(int $status): void
    {
        $http = new Factory;
        Fixture::fake($http, Fixture::html(), $status, options: $options);
        $root = $this->root();
        $seal = Contract::codeSeal();
        $result = $this->probe($http)->run(Fixture::target(), $root, true);
        $this->assertSame($status === 200 ? 'FETCH_AND_PARSE_VERIFIED' : 'FAILED', $result['status']);
        $this->assertSame(1, $result['http_dispatch_count']);
        $this->assertSame($status, $result['http_status']);
        $this->assertSame(Fixture::html(), file_get_contents($root.'/raw.bin'));
        $this->assertSame(hash('sha256', Fixture::html()), $result['raw_seal']['sha256']);
        $this->assertFileExists($root.($status === 200 ? '/COMPLETE.json' : '/FAILED.json'));
        $this->assertSame(false, $options['allow_redirects']);
        $this->assertSame(5, $options['connect_timeout']);
        $this->assertSame(20, $options['timeout']);
        $this->assertFalse($options['decode_content']);
        $this->assertSame(Contract::codeSeal(), $seal);
        $http->assertSentCount(1);
        $http->assertSent(fn ($request) => $request->method() === 'POST' && $request->url() === Target::URI
            && $request['disp'] === 'PJ0315' && $request['encp'] === 'SYNTHETIC_NOT_REAL');
        $this->assertSame('NOT_AUTHORIZED', $result['prediction_use']);
        $this->assertSame('UNVERIFIED', $result['permission_status']);
        if ($status === 200) {
            $this->assertSame(7, $result['parser']['entrant_count']);
            $this->assertArrayNotHasKey('attributes', $result['parser']);
            $private = json_decode(file_get_contents($root.'/result.json'), true, flags: JSON_THROW_ON_ERROR);
            $this->assertCount(7, $private['parser']['attributes']['entries']);
            $this->assertNotNull($result['raw_saved_at']);
        }
    }

    public static function statuses(): iterable
    {
        foreach ([200, 301, 302, 307, 308, 403, 429, 500, 503] as $status) {
            yield (string) $status => [$status];
        }
    }

    #[DataProvider('unparseableResponses')]
    public function test_http_success_and_parser_failure_are_separate(string $body, string $reason): void
    {
        $http = new Factory;
        Fixture::fake($http, $body);
        $root = $this->root();
        $result = $this->probe($http)->run(Fixture::target(), $root, true);
        $this->assertSame('HTTP_200_RECEIVED', $result['fetch_status']);
        $this->assertSame('FAILED', $result['status']);
        $this->assertSame($reason, $result['parser']['status']);
        $this->assertSame($body, file_get_contents($root.'/raw.bin'));
        $this->assertFileDoesNotExist($root.'/COMPLETE.json');
        $http->assertSentCount(1);
    }

    public static function unparseableResponses(): iterable
    {
        yield 'empty' => ['', 'EMPTY_RESPONSE'];
        yield 'HTML structure changed' => ['<html>synthetic incompatible page</html>', 'PARSER_FAILED'];
        yield 'invalid UTF8' => ["\xff\xfe", 'ENCODING_FAILED'];
        yield 'different race' => [str_replace('20250104', '20250105', Fixture::html()), 'RACE_IDENTITY_MISMATCH'];
        yield 'different registration' => [str_replace('000001', '999999', Fixture::html()), 'ENTRANT_IDENTITY_MISMATCH'];
    }

    #[DataProvider('connectionFailures')]
    public function test_connection_failures_retain_partial_evidence_without_retry(string $message, string $reason): void
    {
        $http = new Factory;
        $calls = 0;
        $http->fake(function () use (&$calls, $message) {
            $calls++;
            throw new ConnectionException($message);
        });
        $root = $this->root();
        $result = $this->probe($http)->run(Fixture::target(), $root, true);
        $this->assertSame(1, $calls);
        $this->assertSame('FAILED', $result['status']);
        $this->assertContains($reason, $result['reasons']);
        $this->assertFalse($result['raw_complete']);
        $this->assertNull($result['response_received_at']);
        $this->assertFileExists($root.'/raw.bin');
        $this->assertFileExists($root.'/FAILED.json');
        $this->assertStringNotContainsString($message, file_get_contents($root.'/private-response.json'));
    }

    public static function connectionFailures(): iterable
    {
        yield 'timeout' => ['cURL error 28: SYNTHETIC_SECRET', 'TIMEOUT'];
        yield 'connection' => ['cURL error 7: SYNTHETIC_SECRET', 'CONNECTION_FAILURE'];
    }

    public function test_plan_does_not_send_or_create_files_and_existing_attempt_cannot_be_reused(): void
    {
        $http = new Factory;
        Fixture::fake($http, Fixture::html());
        $probe = $this->probe($http);
        $root = $this->root();
        $before = scandir($root);
        $this->assertSame('PLAN_ONLY_NO_HTTP', $probe->run(Fixture::target(), $root, false)['status']);
        $this->assertSame($before, scandir($root));
        $http->assertNothingSent();
        $probe->run(Fixture::target(), $root, true);
        try {
            $this->probe($http)->run(Fixture::target(), $root, true);
            $this->fail('Existing probe must not be re-dispatched.');
        } catch (RuntimeException) {
            $http->assertSentCount(1);
        }
    }

    public function test_2026_and_js_targets_are_rejected_before_network_or_output(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake();
        foreach (['year', 'source'] as $case) {
            $target = Fixture::target();
            try {
                new Target([...$target->race, 'race_date' => $case === 'year' ? '2026-01-04' : '2025-01-04'],
                    $target->entries, [...$target->parameters, 'disp' => $case === 'source' ? 'JSJ017' : 'PJ0315'], []);
                $this->fail('Forbidden synthetic input accepted.');
            } catch (RuntimeException) {
                $http->assertNothingSent();
            }
        }
    }

    public function test_saved_evidence_and_raw_must_match_external_seals(): void
    {
        $root = $this->root();
        [$path, $sha] = Fixture::saved($root);
        $target = Target::load($path, $sha);
        $this->assertSame(Fixture::target()->entries, $target->entries);
        file_put_contents($root.'/baseline.html', 'altered synthetic copy', FILE_APPEND);
        $this->expectException(RuntimeException::class);
        Target::load($path, $sha);
    }

    public function test_save_failure_keeps_raw_and_marks_failed(): void
    {
        $http = new Factory;
        Fixture::fake($http, Fixture::html());
        $root = $this->root();
        $probe = new class(new SinglePostTransport($http), Fixture::scope($this->root())) extends Probe
        {
            protected function write(string $root, string $name, array $data): void
            {
                if ($name === 'result.json') {
                    throw new RuntimeException('Synthetic storage failure.');
                }
                parent::write($root, $name, $data);
            }
        };
        try {
            $probe->run(Fixture::target(), $root, true);
            $this->fail('Persistence failure accepted.');
        } catch (RuntimeException) {
            $this->assertSame(Fixture::html(), file_get_contents($root.'/raw.bin'));
            $this->assertFileExists($root.'/FAILED.json');
            $this->assertFileDoesNotExist($root.'/COMPLETE.json');
            $http->assertSentCount(1);
        }
    }

    public function test_raw_mutation_before_publication_is_rejected(): void
    {
        $http = new Factory;
        Fixture::fake($http, Fixture::html());
        $root = $this->root();
        $probe = new class(new SinglePostTransport($http), Fixture::scope($this->root())) extends Probe
        {
            protected function write(string $root, string $name, array $data): void
            {
                parent::write($root, $name, $data);
                if ($name === 'result.json') {
                    file_put_contents($root.'/raw.bin', 'x', FILE_APPEND);
                }
            }
        };
        try {
            $probe->run(Fixture::target(), $root, true);
            $this->fail('Synthetic Raw mutation accepted.');
        } catch (RuntimeException) {
            $this->assertFileExists($root.'/FAILED.json');
            $this->assertFileDoesNotExist($root.'/COMPLETE.json');
        }
    }

    public function test_unknown_or_different_effective_uri_is_not_replaced_with_request_uri(): void
    {
        foreach ([null, 'https://other.invalid/'] as $uri) {
            $http = new Factory;
            $http->preventStrayRequests();
            $http->fake(function ($request, $options) use ($uri) {
                if ($uri !== null) {
                    $options['on_stats'](new TransferStats(new Request('POST', $uri), new Response(200)));
                }

                // Array stubs avoid Factory::fake's automatic requested-URI transfer stats.
                return ['synthetic_response' => 'UNVERIFIED_SOURCE'];
            });
            $result = $this->probe($http)->run(Fixture::target(), $this->root(), true);
            $this->assertContains('EFFECTIVE_URI_NOT_VERIFIED', $result['reasons']);
            $http->assertSentCount(1);
        }
    }

    public function test_raw_collision_prevents_dispatch_and_preserves_existing_bytes(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake();
        $root = $this->root();
        Contract::writeNew($root, 'raw.bin', 'SYNTHETIC_EXISTING_BYTES');
        try {
            $this->probe($http)->run(Fixture::target(), $root, true);
            $this->fail('Existing Raw must not be overwritten.');
        } catch (RuntimeException) {
            $http->assertNothingSent();
            $this->assertSame('SYNTHETIC_EXISTING_BYTES', file_get_contents($root.'/raw.bin'));
            $this->assertFileExists($root.'/FAILED.json');
        }
    }

    private function probe(Factory $http): Probe
    {
        return new Probe(new SinglePostTransport($http), Fixture::scope($this->root()));
    }

    private function root(): string
    {
        $directory = CompositionResultTemporaryDirectory::create();
        $this->owned[] = $directory;

        return $directory->path();
    }
}
