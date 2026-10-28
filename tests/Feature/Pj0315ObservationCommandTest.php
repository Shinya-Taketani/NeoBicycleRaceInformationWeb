<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Keirin\Scraping\PrestartCapture\Contract;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Tests\Support\CompositionResultTemporaryDirectory;
use Tests\Support\ObservationRunnerFixture;
use Tests\TestCase;
use Throwable;

final class Pj0315ObservationCommandTest extends TestCase
{
    private ?CompositionResultTemporaryDirectory $temporary = null;

    public function createApplication(): Application
    {
        $this->temporary = CompositionResultTemporaryDirectory::create();
        $root = $this->temporary->path();
        try {
            foreach (['bootstrap', 'bootstrap/cache', 'runtime', 'storage'] as $name) {
                if (! mkdir($root.'/'.$name, 0700)) {
                    throw new RuntimeException('Cannot create isolated runtime.');
                }
            }
            $env = "# Isolated test fixture. No application secrets.\n";
            $handle = fopen($root.'/.env', 'xb');
            if ($handle === false || fwrite($handle, $env) !== strlen($env) || ! fflush($handle)) {
                throw new RuntimeException('Cannot create artificial environment file.');
            }
            fclose($handle);
            $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
            $this->traitsUsedByTest = class_uses_recursive(self::class);
            $app->useEnvironmentPath($root);
            $app->useBootstrapPath($root.'/bootstrap');
            $app->useStoragePath($root.'/runtime');
            $app->make(Kernel::class)->bootstrap();
            foreach (['db', 'db.connection', 'redis', 'filesystem'] as $service) {
                $app->bind($service, fn () => throw new RuntimeException('Production I/O forbidden.'));
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

    public function test_default_plan_and_unapproved_execute_never_dispatch(): void
    {
        $http = new Factory;
        $http->fake();
        $this->app->instance(Factory::class, $http);
        $this->assertSame($this->temporary->path().'/.env', $this->app->environmentFilePath());
        $this->assertSame(0, Artisan::call('keirin:prestart:observe-pj0315'));
        $this->assertSame('PLAN_ONLY_NO_HTTP', json_decode(Artisan::output(), true)['status']);
        $this->assertSame(1, Artisan::call('keirin:prestart:observe-pj0315', ['--execute' => true]));
        $this->assertSame('NOT_AUTHORIZED', json_decode(Artisan::output(), true)['status']);
        $http->assertNothingSent();
    }

    public function test_sealed_manifest_plan_and_embedded_synthetic_approvals_cannot_enable_real_send(): void
    {
        $root = $this->temporary->path();
        [$manifest, $input] = ObservationRunnerFixture::make($root.'/storage');
        Contract::writeNew($root, 'manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        Contract::writeNew($root, 'approval.json', json_encode($input, JSON_THROW_ON_ERROR));
        $args = ['--manifest' => $root.'/manifest.json', '--manifest-sha' => hash_file('sha256', $root.'/manifest.json'),
            '--preflight' => $root.'/approval.json', '--preflight-sha' => hash_file('sha256', $root.'/approval.json'),
            '--race' => '901', '--slot' => '1'];
        $http = new Factory;
        $http->fake();
        $this->app->instance(Factory::class, $http);
        $this->assertSame(0, Artisan::call('keirin:prestart:observe-pj0315', $args));
        $this->assertCount(2, json_decode(Artisan::output(), true)['slots']);
        $this->assertSame(1, Artisan::call('keirin:prestart:observe-pj0315', [...$args, '--execute' => true]));
        $result = json_decode(Artisan::output(), true);
        $this->assertSame('NOT_AUTHORIZED', $result['status']);
        $this->assertFalse($result['real_send_allowed']);
        $this->assertSame(0, $result['http_calls']);
        $this->assertSame('NOT_AUTHORIZED', $result['prediction_use']);
        $this->assertStringNotContainsString('SYNTHETIC_NOT_REAL', Artisan::output());
        $http->assertNothingSent();
    }
}
