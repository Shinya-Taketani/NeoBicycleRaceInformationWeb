<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\Keirin\PrestartApprovalPreflightCommand;
use App\Domain\Keirin\Scraping\Http\KeirinHttpClient;
use App\Domain\Keirin\Scraping\PrestartCapture\CaptureStore;
use App\Domain\Keirin\Scraping\PrestartCapture\OfflineBridge;
use App\Domain\Keirin\Scraping\PrestartCapture\Operational\Contract;
use App\Domain\Keirin\Scraping\Services\RaceRosterSnapshotService;
use App\Domain\Keirin\Scraping\Services\ScrapingFetchService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Support\ApprovalPreflightFixture as Fixture;
use Tests\Support\CompositionResultTemporaryDirectory;
use Tests\TestCase;
use Throwable;

final class PrestartApprovalPreflightCommandTest extends TestCase
{
    private ?CompositionResultTemporaryDirectory $temporary = null;

    public function createApplication(): Application
    {
        $this->temporary = CompositionResultTemporaryDirectory::create();
        $root = $this->temporary->path();
        try {
            foreach (['bootstrap', 'bootstrap/cache', 'runtime'] as $name) {
                if (! mkdir($root.'/'.$name, 0700)) {
                    throw new RuntimeException('Cannot create isolated bootstrap directories.');
                }
            }
            $this->write($root.'/.env', "# Isolated test fixture. No application secrets.\n");
            $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
            $this->traitsUsedByTest = class_uses_recursive(self::class);
            $app->useEnvironmentPath($root);
            $app->useBootstrapPath($root.'/bootstrap');
            $app->useStoragePath($root.'/runtime');
            $app->make(Kernel::class)->bootstrap();
            foreach (['db', 'db.connection', 'redis', 'filesystem', KeirinHttpClient::class, ScrapingFetchService::class,
                RaceRosterSnapshotService::class, OfflineBridge::class, CaptureStore::class] as $service) {
                $app->bind($service, fn () => throw new RuntimeException('External I/O or persistence forbidden in approval preflight.'));
            }
            $app['config']->set(['logging.default' => 'null', 'app.key' => null]);

            return $app;
        } catch (Throwable $error) {
            $this->temporary->retire($this->temporary->path(), 'TEST_FAILURE');
            $this->temporary = null;
            throw $error;
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
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

    public function test_default_plan_explains_missing_decisions_without_external_io(): void
    {
        $this->assertSame($this->temporary->path().'/.env', $this->app->environmentFilePath());
        $this->assertSame("# Isolated test fixture. No application secrets.\n", file_get_contents($this->app->environmentFilePath()));
        $this->assertSame(0, Artisan::call('keirin:prestart:approval-preflight'));
        $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('NOT_AUTHORIZED', $result['status']);
        $this->assertSame(Contract::DECISIONS, $result['missing_decisions']);
        $this->assertSafe($result);
    }

    public function test_synthetic_json_is_reproducible_and_never_causes_fetch_save_or_model_updates(): void
    {
        $path = $this->input(Fixture::make());
        $sha = hash_file('sha256', $path);
        $files = scandir($this->temporary->path());
        $options = ['--input' => $path, '--scenario' => Contract::SIMULATED, '--at' => Fixture::now()->format(DATE_ATOM)];
        $this->assertSame(0, Artisan::call('keirin:prestart:approval-preflight', $options));
        $first = Artisan::output();
        $this->assertSame('OFFLINE_SIMULATION_VALIDATED', json_decode($first, true, flags: JSON_THROW_ON_ERROR)['status']);
        $this->assertSame(0, Artisan::call('keirin:prestart:approval-preflight', $options));
        $this->assertSame($first, Artisan::output());
        $this->assertSame($sha, hash_file('sha256', $path));
        $this->assertSame($files, scandir($this->temporary->path()));
        $this->assertSafe(json_decode($first, true, flags: JSON_THROW_ON_ERROR));
    }

    public function test_history_interval_and_future_expiration_are_blocked_through_the_command(): void
    {
        foreach (['history', 'expiry'] as $case) {
            $input = $case === 'history' ? Fixture::make() : Fixture::futureRequest();
            if ($case === 'history') {
                $input['budget']['attempts'] = [Fixture::attempt(42, 1, '2024-12-31T23:55:00Z', 'FAILED'),
                    Fixture::attempt(42, 2, '2024-12-31T23:55:01Z', 'INTERRUPTED')];
            } else {
                $input['decisions'][1]['expires_at'] = '2025-01-01T00:00:10Z';
                Fixture::sealApprovals($input);
            }
            $path = $this->input($input, $case.'.json');
            $sha = hash_file('sha256', $path);
            $this->assertSame(0, Artisan::call('keirin:prestart:approval-preflight',
                ['--input' => $path, '--scenario' => Contract::SIMULATED, '--at' => Fixture::now()->format(DATE_ATOM)]));
            $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('NOT_AUTHORIZED', $result['status']);
            $this->assertContains($case === 'history' ? 'budget:ATTEMPT_INTERVAL_OR_ORDER_INVALID' : 'D2:APPROVAL_EXPIRED_AT_PLANNED_REQUEST',
                $result['blocking_reasons']);
            $this->assertSame($sha, hash_file('sha256', $path));
            $this->assertSafe($result);
        }
    }

    public function test_operational_input_cannot_use_synthetic_approval_even_when_every_record_says_approved(): void
    {
        $this->assertSame(0, Artisan::call('keirin:prestart:approval-preflight', ['--input' => $this->input(Fixture::make())]));
        $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('NOT_AUTHORIZED', $result['status']);
        $this->assertSame(Contract::DECISIONS, $result['missing_decisions']);
        $this->assertSafe($result);
    }

    public function test_synthetic_2026_and_js_targets_are_rejected_without_resolving_io_services(): void
    {
        foreach (['year', 'source'] as $case) {
            $input = Fixture::make();
            if ($case === 'year') {
                $input['manifest']['target']['race_date'] = '2026-01-01';
            } else {
                $input['manifest']['source'] = $input['source']['source'] = 'JSJ017';
            }
            $path = $this->input($input, $case.'.json');
            $this->assertSame(0, Artisan::call('keirin:prestart:approval-preflight',
                ['--input' => $path, '--scenario' => Contract::SIMULATED, '--at' => Fixture::now()->format(DATE_ATOM)]));
            $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertContains($case === 'year' ? 'TARGET_YEAR_FORBIDDEN' : 'SOURCE_NOT_APPROVED', $result['manifest_validation']['failure_reasons']);
            $this->assertSame('NOT_AUTHORIZED', $result['status']);
            $this->assertSafe($result);
        }
    }

    public function test_malformed_input_has_a_distinct_exit_code_without_leaking_input_contents(): void
    {
        $path = $this->temporary->path().'/invalid.json';
        $this->write($path, '{SYNTHETIC_SECRET_MUST_NOT_BE_LOGGED');
        $this->assertSame(1, Artisan::call('keirin:prestart:approval-preflight', ['--input' => $path]));
        $this->assertSame('INPUT_ERROR', json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['status']);
        $this->assertStringNotContainsString('SYNTHETIC_SECRET', Artisan::output());
        Http::assertNothingSent();
    }

    public function test_urls_missing_files_and_operational_clock_overrides_are_command_errors(): void
    {
        foreach ([['--input' => 'https://fixture.invalid/not-fetched'], ['--input' => $this->temporary->path().'/absent.json'],
            ['--at' => '2025-01-01T00:00:00Z'], ['--scenario' => 'LIVE_AUTHORIZED']] as $options) {
            $this->assertSame(1, Artisan::call('keirin:prestart:approval-preflight', $options));
            $this->assertSame('INPUT_ERROR', json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['status']);
        }
        Http::assertNothingSent();
    }

    public function test_no_execute_or_persistence_options_are_exposed(): void
    {
        $definition = $this->app->make(PrestartApprovalPreflightCommand::class)->getDefinition();
        foreach (['execute', 'force', 'output', 'reserve', 'fetch'] as $option) {
            $this->assertFalse($definition->hasOption($option));
        }
    }

    private function assertSafe(array $result): void
    {
        foreach (['http_calls', 'database_writes', 'model_updates', 'attempt_reservations'] as $key) {
            $this->assertSame(0, $result[$key]);
        }
        $this->assertSame('NOT_RUN_NOT_AUTHORIZED', $result['real_http_capture']);
        $this->assertSame('NOT_AUTHORIZED', $result['prediction_use']);
        $this->assertSame('SECOND_SOURCE_NOT_CAPTURED', $result['second_source_status']);
        $this->assertSame('NOT_ESTABLISHED', $result['two_source_consistency']);
        Http::assertNothingSent();
    }

    private function input(array $input, string $name = 'input.json'): string
    {
        $path = $this->temporary->path().'/'.$name;
        $this->write($path, json_encode($input, JSON_THROW_ON_ERROR));

        return $path;
    }

    private function write(string $path, string $bytes): void
    {
        $stream = fopen($path, 'xb');
        if ($stream === false) {
            throw new RuntimeException('Cannot exclusively create synthetic input.');
        }
        try {
            if (fwrite($stream, $bytes) !== strlen($bytes) || ! fflush($stream)) {
                throw new RuntimeException('Cannot persist synthetic input.');
            }
        } finally {
            fclose($stream);
        }
    }
}
