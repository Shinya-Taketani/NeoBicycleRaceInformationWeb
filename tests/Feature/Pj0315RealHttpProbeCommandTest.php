<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Keirin\Scraping\Http\KeirinHttpClient;
use App\Domain\Keirin\Scraping\Services\ScrapingFetchService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
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
        $http->assertNothingSent();
        $this->assertSame(['.', '..'], scandir($this->temporary->path().'/output'));
    }
}
