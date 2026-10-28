<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\Keirin\ProbePj0315RealHttpCommand;
use App\Domain\Keirin\Scraping\Http\KeirinHttpClient;
use App\Domain\Keirin\Scraping\PrestartCapture\Pj0315Probe\Probe;
use App\Domain\Keirin\Scraping\PrestartCapture\Pj0315Probe\ScopeAttempt;
use App\Domain\Keirin\Scraping\PrestartCapture\Pj0315Probe\SinglePostTransport;
use App\Domain\Keirin\Scraping\PrestartCapture\Pj0315Probe\Target;
use App\Domain\Keirin\Scraping\Services\ScrapingFetchService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\CompositionResultTemporaryDirectory;
use Tests\Support\Pj0315ProbeFixture as Fixture;
use Tests\TestCase;
use Throwable;

final class Pj0315RealHttpProbeCommandTest extends TestCase
{
    private ?CompositionResultTemporaryDirectory $temporary = null;

    public function createApplication(): Application
    {
        $this->temporary = CompositionResultTemporaryDirectory::create();
        $root = $this->temporary->path();
        try {
            foreach (['bootstrap', 'bootstrap/cache', 'runtime', 'source', 'output'] as $name) {
                if (! mkdir($root.'/'.$name, 0700)) {
                    throw new RuntimeException('Cannot create isolated test runtime.');
                }
            }
            $env = "# Isolated test fixture. No application secrets.\n";
            $stream = fopen($root.'/.env', 'xb');
            if ($stream === false || fwrite($stream, $env) !== strlen($env) || ! fflush($stream)) {
                throw new RuntimeException('Cannot create isolated test environment file.');
            }
            fclose($stream);
            $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
            $this->traitsUsedByTest = class_uses_recursive(self::class);
            $app->useEnvironmentPath($root);
            $app->useBootstrapPath($root.'/bootstrap');
            $app->useStoragePath($root.'/runtime');
            $app->make(Kernel::class)->bootstrap();
            foreach (['db', 'db.connection', 'redis', 'filesystem', KeirinHttpClient::class, ScrapingFetchService::class] as $service) {
                $app->bind($service, fn () => throw new RuntimeException('Production I/O is forbidden in probe tests.'));
            }
            $app['config']->set(['logging.default' => 'null', 'app.key' => null]);
            $app->instance(ScopeAttempt::class, Fixture::scope($root));

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

    public function test_default_never_sends_and_uses_only_artificial_environment(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake();
        $this->app->instance(Factory::class, $http);
        $this->assertSame($this->temporary->path().'/.env', $this->app->environmentFilePath());
        $this->assertSame("# Isolated test fixture. No application secrets.\n", file_get_contents($this->app->environmentFilePath()));
        $this->assertSame(0, Artisan::call('keirin:prestart:probe-pj0315'));
        $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('PLAN_ONLY_NO_HTTP', $result['status']);
        $this->assertSame(0, $result['http_dispatch_count']);
        $http->assertNothingSent();
        $this->assertSame(['.', '..'], scandir($this->temporary->path().'/output'));
    }

    public function test_explicit_execute_uses_sealed_target_once_without_db_or_secret_output(): void
    {
        [$file, $sha] = Fixture::saved($this->temporary->path().'/source');
        $http = new Factory;
        Fixture::fake($http, Fixture::html());
        $this->app->instance(Factory::class, $http);
        $arguments = ['--execute' => true, '--evidence' => $file, '--evidence-sha' => $sha,
            '--output' => $this->temporary->path().'/output'];
        $this->assertSame(0, Artisan::call('keirin:prestart:probe-pj0315', $arguments));
        $output = Artisan::output();
        $result = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('FETCH_AND_PARSE_VERIFIED', $result['status']);
        $this->assertSame(0, $result['database_writes']);
        $this->assertSame('NOT_AUTHORIZED', $result['prediction_use']);
        $this->assertStringNotContainsString('SYNTHETIC_NOT_REAL', $output);
        $this->assertFileExists($this->temporary->path().'/output/COMPLETE.json');
        $this->assertSame(1, Artisan::call('keirin:prestart:probe-pj0315', $arguments));
        $other = $this->temporary->path().'/other-output';
        $this->assertTrue(mkdir($other, 0700));
        $command = new ProbePj0315RealHttpCommand;
        $command->setLaravel($this->app);
        $output = new BufferedOutput;
        $this->assertSame(1, $command->run(new ArrayInput([...$arguments, '--output' => $other]), $output));
        $blocked = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('HTTP_SUCCEEDED', $blocked['scope_attempt']['state']);
        $this->assertFalse($blocked['output_write_started']);
        $this->assertSame(['.', '..'], scandir($other));
        $http->assertSentCount(1);
        $this->assertSame($sha, hash_file('sha256', $file));
    }

    public function test_invalid_evidence_is_rejected_before_dispatch(): void
    {
        [$file] = Fixture::saved($this->temporary->path().'/source');
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake();
        $this->app->instance(Factory::class, $http);
        $this->assertSame(1, Artisan::call('keirin:prestart:probe-pj0315', ['--execute' => true,
            '--evidence' => $file, '--evidence-sha' => str_repeat('0', 64), '--output' => $this->temporary->path().'/output']));
        $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('NOT_CREATED', $result['evidence_status']);
        $this->assertFalse($result['evidence_retained']);
        $http->assertNothingSent();
        $this->assertSame(['.', '..'], scandir($this->temporary->path().'/output'));
    }

    public function test_missing_output_reports_no_generated_evidence_and_does_not_reserve(): void
    {
        [$file, $sha] = Fixture::saved($this->temporary->path().'/source');
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake();
        $this->app->instance(Factory::class, $http);
        $this->assertSame(1, Artisan::call('keirin:prestart:probe-pj0315', ['--execute' => true,
            '--evidence' => $file, '--evidence-sha' => $sha]));
        $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('NOT_CREATED', $result['evidence_status']);
        $this->assertFalse($result['evidence_retained']);
        $this->assertFalse($result['output_write_started']);
        $this->assertSame('AVAILABLE', $this->app->make(ScopeAttempt::class)->status()['state']);
        $http->assertNothingSent();
    }

    #[DataProvider('failureRetention')]
    public function test_failure_after_writing_begins_reports_only_read_confirmed_failed_evidence(bool $retain): void
    {
        [$file, $sha] = Fixture::saved($this->temporary->path().'/source');
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake();
        $probe = new class(new SinglePostTransport($http), $this->app->make(ScopeAttempt::class)) extends Probe
        {
            public bool $retainFailure = true;

            protected function write(string $root, string $name, array $data): void
            {
                if ($name === 'private-request.json' || ($name === 'FAILED.json' && ! $this->retainFailure)) {
                    throw new RuntimeException('Synthetic storage failure before dispatch.');
                }
                parent::write($root, $name, $data);
            }
        };
        $probe->retainFailure = $retain;
        $this->app->instance(Probe::class, $probe);
        $this->assertSame(1, Artisan::call('keirin:prestart:probe-pj0315', ['--execute' => true,
            '--evidence' => $file, '--evidence-sha' => $sha, '--output' => $this->temporary->path().'/output']));
        $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($retain ? 'FAILED_EVIDENCE_CONFIRMED' : 'WRITE_STARTED_RETENTION_UNCONFIRMED', $result['evidence_status']);
        $this->assertSame($retain ? true : null, $result['evidence_retained']);
        $this->assertTrue($result['output_write_started']);
        $this->assertSame($retain, $result['failed_evidence_confirmed']);
        $this->assertSame($retain, is_file($this->temporary->path().'/output/FAILED.json'));
        $this->assertSame('RESERVED', $result['scope_attempt']['state']);
        $http->assertNothingSent();
    }

    public static function failureRetention(): iterable
    {
        yield 'FAILED evidence confirmed' => [true];
        yield 'retention cannot be confirmed' => [false];
    }

    public function test_synthetic_2026_and_js_inputs_do_not_reserve_or_dispatch(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake();
        $this->app->instance(Factory::class, $http);
        foreach (['year', 'source'] as $case) {
            $directory = $this->temporary->path().'/source/'.$case;
            $this->assertTrue(mkdir($directory, 0700));
            [$file] = Fixture::saved($directory);
            $input = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
            if ($case === 'year') {
                $input['cases'][0]['target']['race']['race_date'] = '2026-01-04';
            } else {
                $input['cases'][0]['log']['request_parameters']['disp'] = 'JSJ017';
            }
            file_put_contents($file, json_encode($input, JSON_THROW_ON_ERROR));
            $this->assertSame(1, Artisan::call('keirin:prestart:probe-pj0315', ['--execute' => true,
                '--evidence' => $file, '--evidence-sha' => hash_file('sha256', $file), '--output' => $this->temporary->path().'/output']));
            $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('NOT_CREATED', $result['evidence_status']);
            $this->assertFalse($result['evidence_retained']);
            $this->assertSame('AVAILABLE', $this->app->make(ScopeAttempt::class)->status()['state']);
        }
        $http->assertNothingSent();
    }

    public function test_unexpected_processing_exception_does_not_claim_evidence_was_never_created(): void
    {
        [$file, $sha] = Fixture::saved($this->temporary->path().'/source');
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake();
        $probe = new class(new SinglePostTransport($http), $this->app->make(ScopeAttempt::class)) extends Probe
        {
            public function run(Target $target, ?string $root, bool $execute): array
            {
                throw new RuntimeException('SYNTHETIC_UNEXPECTED_EXCEPTION_MUST_NOT_LEAK');
            }
        };
        $this->app->instance(Probe::class, $probe);
        $this->assertSame(1, Artisan::call('keirin:prestart:probe-pj0315', ['--execute' => true,
            '--evidence' => $file, '--evidence-sha' => $sha, '--output' => $this->temporary->path().'/output']));
        $output = Artisan::output();
        $result = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('RETENTION_UNKNOWN', $result['evidence_status']);
        $this->assertNull($result['evidence_retained']);
        $this->assertNull($result['output_write_started']);
        $this->assertStringNotContainsString('SYNTHETIC_UNEXPECTED_EXCEPTION_MUST_NOT_LEAK', $output);
        $http->assertNothingSent();
    }
}
