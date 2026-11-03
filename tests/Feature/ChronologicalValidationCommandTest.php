<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Keirin\Backtest\ChronologicalValidation\Json;
use App\Domain\Keirin\Backtest\ChronologicalValidation\Store;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\ModelLoader;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\Factory;
use RuntimeException;
use Tests\Support\ChronologicalFixture;
use Tests\Support\CompositionResultTemporaryDirectory;
use Tests\TestCase;

final class ChronologicalValidationCommandTest extends TestCase
{
    private CompositionResultTemporaryDirectory $temporary;

    public function createApplication(): Application
    {
        $this->temporary = CompositionResultTemporaryDirectory::create('/tmp');
        $root = $this->temporary->path();
        foreach (['bootstrap', 'bootstrap/cache', 'runtime'] as $directory) {
            mkdir($root.'/'.$directory, 0700);
        }
        Json::write($root.'/.env', "# Isolated test fixture. No application secrets.\n");
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $this->traitsUsedByTest = class_uses_recursive(self::class);
        $app->useEnvironmentPath($root);
        $app->useBootstrapPath($root.'/bootstrap');
        $app->useStoragePath($root.'/runtime');
        $app->make(Kernel::class)->bootstrap();
        foreach (['db', 'redis', Factory::class] as $service) {
            $app->bind($service, fn () => throw new RuntimeException('Offline test forbids external connections.'));
        }
        $app['config']->set(['logging.default' => 'null', 'app.key' => null]);

        return $app;
    }

    public function test_plan_has_no_artifacts_and_preserves_artificial_environment(): void
    {
        $this->artisan('keirin:backtest:chronological-validation', ['--plan' => true])->assertExitCode(0);
        $this->assertSame($this->temporary->path().'/.env', $this->app->environmentFilePath());
        $this->assertSame("# Isolated test fixture. No application secrets.\n", file_get_contents($this->app->environmentFilePath()));
        $this->assertDirectoryDoesNotExist($this->temporary->path().'/objects');
    }

    public function test_modes_are_exclusive_and_required_arguments_fail_without_sources_or_db(): void
    {
        $this->artisan('keirin:backtest:chronological-validation', ['--plan' => true, '--verify' => true])->assertExitCode(1);
        $this->artisan('keirin:backtest:chronological-validation', ['--verify' => true])->assertExitCode(1);
        $this->artisan('keirin:backtest:chronological-validation', ['--execute-offline' => true, '--output-root' => $this->temporary->path().'/not-created'])->assertExitCode(1);
        $this->assertDirectoryDoesNotExist($this->temporary->path().'/not-created');
    }

    public function test_real_execution_is_refused_before_output_creation_or_model_access(): void
    {
        $root = $this->temporary->path();
        Json::write($root.'/real-request.json', Json::encode(['scope' => ['kind' => 'REAL_EXECUTION', 'from' => '2026-01-01', 'to' => '2026-01-02']]));
        $this->artisan('keirin:backtest:chronological-validation', ['--execute-offline' => true, '--input-manifest' => $root.'/real-request.json',
            '--result-root' => '/forbidden-results', '--artifact' => '/forbidden-model', '--output-root' => $root.'/not-created'])->assertExitCode(1);
        $this->assertDirectoryDoesNotExist($root.'/not-created');
    }

    public function test_verify_and_reproduce_use_saved_bundles_not_original_sources_or_prediction(): void
    {
        $f = new ChronologicalFixture([5]);
        $store = new Store($f->root.'/output');
        $f->coordinator()->execute($f->manifest, $f->model(), Json::hash($f->model()->artifact), $store, $f->source($store));
        $this->app->bind(ModelLoader::class, fn () => throw new RuntimeException('Model loader must not be resolved by read-only modes.'));
        $this->artisan('keirin:backtest:chronological-validation', ['--verify' => true, '--output-root' => $store->root])->assertExitCode(0);
        $this->artisan('keirin:backtest:chronological-validation', ['--reproduce-evaluation' => true, '--output-root' => $store->root])->assertExitCode(0);
    }
}
