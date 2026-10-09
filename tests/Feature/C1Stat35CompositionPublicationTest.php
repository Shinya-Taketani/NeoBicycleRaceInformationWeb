<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\Keirin\FinalC1Stat35CompositionCommand;
use App\Domain\Keirin\Backtest\Calculators\Bt03e03OneSeSelector;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Contract;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Experiment;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Forward;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Input;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Package;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Prediction;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Publication;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Repackage;
use App\Domain\Keirin\Backtest\Experiments\Stat35C1Comparison\Trainer;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact as Jsonl;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\C1Stat35CompositionFinalFixture as Fixture;
use Tests\TestCase;

class C1Stat35CompositionPublicationTest extends TestCase
{
    private static ?array $source = null;

    private static string $shared;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        FinalC1Stat35CompositionCommand::denyExternalAccess();
        $this->directory = Files::directory(sys_get_temp_dir().'/composition-publication-'.bin2hex(random_bytes(8)));
        if (self::$source === null) {
            self::$shared = sys_get_temp_dir().'/composition-publication-fixture-'.bin2hex(random_bytes(8));
            ob_start();
            try {
                self::$source = Fixture::make(self::$shared);
            } finally {
                ob_end_clean();
            }
        }
    }

    protected function tearDown(): void
    {
        self::remove($this->directory);
        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$source !== null) {
            self::remove(self::$shared);
            self::$source = null;
        }
        parent::tearDownAfterClass();
    }

    #[DataProvider('uncommittedStates')]
    public function test_prepared_failed_or_relocated_package_is_not_public(string $state): void
    {
        $root = Files::directory($this->directory.'/fit.inprogress-test');
        Files::directory($root.'/run-01');
        $artifact = $this->stage($root.'/run-01/package', '../..');
        $this->assertSame(17, app(Package::class)->prepared($artifact)['c2']->layout->featureCount());
        if ($state === 'failed') {
            Jsonl::json($root.'/FAILED.json', ['state' => 'FAILED']);
        } elseif ($state === 'relocated') {
            rename(dirname($artifact), $this->directory.'/moved-package');
            $artifact = $this->directory.'/moved-package/artifact.json';
        } elseif ($state === 'forged-package-complete') {
            Jsonl::json(dirname($artifact).'/COMPLETE.json', ['artifact' => Files::identity($artifact), 'status' => 'FINAL_COMPOSITION_FIT_REPRODUCED_AWAITING_REVIEW']);
        }
        $input = $this->input();
        $this->reject(static fn () => app(Package::class)->load($artifact));
        $this->reject(fn () => app(Prediction::class)->run($artifact, $input, $this->directory.'/predictions'));
        $this->assertDirectoryDoesNotExist($this->directory.'/predictions');
    }

    public static function uncommittedStates(): array
    {
        return array_map(static fn ($state) => [$state], ['prepared', 'failed', 'relocated', 'forged-package-complete']);
    }

    #[DataProvider('proofChanges')]
    public function test_committed_package_rejects_missing_changed_or_reused_proof(string $change): void
    {
        $artifact = $this->package();
        $root = dirname($artifact);
        if ($change === 'missing') {
            unlink($root.'/COMPLETE.json');
        } else {
            $proof = Files::json($root.'/publication.json');
            if ($change === 'state') {
                $proof['state'] = 'PREPARED';
            } elseif ($change === 'membership') {
                $proof['packages'] = ['other/artifact.json'];
            } else {
                $proof['files']['artifact.json']['sha256'] = str_repeat('0', 64);
            }
            unlink($root.'/publication.json');
            unlink($root.'/COMPLETE.json');
            Jsonl::json($root.'/publication.json', $proof);
            Jsonl::json($root.'/COMPLETE.json', Files::identity($root.'/publication.json'));
        }
        $this->reject(static fn () => app(Package::class)->load($artifact));
    }

    public static function proofChanges(): array
    {
        return array_map(static fn ($state) => [$state], ['missing', 'state', 'membership', 'seal']);
    }

    #[DataProvider('predictionFailures')]
    public function test_real_publication_boundaries_preserve_failed_stage_without_partial_output(string $failure): void
    {
        $artifact = $this->package();
        $input = $this->input();
        $publication = new class($failure, $input) extends Publication
        {
            public function __construct(private string $failure, private string $input) {}

            public function rows(string $path, iterable $rows): array
            {
                if ($this->failure === 'manifest') {
                    $rows = (function () use ($rows, $path): \Generator {
                        yield from $rows;
                        mkdir($path.'.manifest.json');
                    })();
                }
                $seal = parent::rows($path, $rows);
                if ($this->failure === 'input-end') {
                    file_put_contents($this->input.'.input.json', "\n", FILE_APPEND);
                } elseif ($this->failure === 'body-end') {
                    file_put_contents($path, "\n", FILE_APPEND);
                }

                return $seal;
            }

            public function json(string $path, array $value): void
            {
                if ($this->failure === 'complete' && basename($path) === 'COMPLETE.json') {
                    mkdir($path);
                }
                parent::json($path, $value);
            }

            protected function rename(string $stage, string $destination): void
            {
                if ($this->failure === 'rename') {
                    file_put_contents($destination, 'competing owner');
                }
                parent::rename($stage, $destination);
            }
        };
        $output = $this->directory.'/predictions';
        $this->reject(fn () => (new Prediction(app(Package::class), app(Forward::class), $publication))->run($artifact, $input, $output));
        if ($failure === 'rename') {
            $this->assertSame('competing owner', file_get_contents($output));
        } else {
            $this->assertDirectoryDoesNotExist($output);
        }
        $stages = glob($output.'.inprogress-*');
        $this->assertCount(1, $stages);
        $this->assertFileExists($stages[0].'/predictions.jsonl');
        $this->assertFileExists($stages[0].'/FAILED.json');
        $this->assertSame('FAILED', Files::json($stages[0].'/FAILED.json')['state']);
        if ($failure === 'manifest') {
            $this->assertDirectoryExists($stages[0].'/predictions.jsonl.manifest.json');
        }
        if (in_array($failure, ['manifest', 'complete'], true)) {
            $failed = Files::identity($stages[0].'/FAILED.json');
            $result = app(Prediction::class)->run($artifact, $input, $output);
            $this->assertSame(2, $result['predictions']['rows']);
            $this->assertSame($failed, Files::identity($stages[0].'/FAILED.json'));
            $this->assertSame($stages, glob($output.'.inprogress-*'));
            $this->assertBundle($output);
        }
    }

    public static function predictionFailures(): array
    {
        return array_map(static fn ($state) => [$state], ['manifest', 'complete', 'input-end', 'body-end', 'rename']);
    }

    public function test_fit_root_rename_failure_blocks_its_prepared_packages_even_after_moving_one(): void
    {
        $publication = new class extends Publication
        {
            protected function rename(string $stage, string $destination): void
            {
                mkdir($destination);
                file_put_contents($destination.'/owner', 'other');
                parent::rename($stage, $destination);
            }
        };
        $this->app->instance(Publication::class, $publication);
        $target = $this->directory.'/fit';
        ob_start();
        try {
            $this->reject(fn () => app(Experiment::class)->execute($target, self::$source));
        } finally {
            ob_end_clean();
        }
        $stage = glob($target.'.inprogress-*')[0];
        $this->assertFileExists($stage.'/FAILED.json');
        $this->assertSame('other', file_get_contents($target.'/owner'));
        $this->reject(fn () => app(Package::class)->load($stage.'/run-01/package/artifact.json'));
        rename($stage.'/run-01/package', $this->directory.'/moved');
        $this->reject(fn () => app(Package::class)->load($this->directory.'/moved/artifact.json'));
        $this->reject(fn () => app(Prediction::class)->run($this->directory.'/moved/artifact.json', $stage.'/verified-inputs/features-2025.jsonl', $this->directory.'/bad'));
        $this->assertDirectoryDoesNotExist($this->directory.'/bad');
    }

    #[DataProvider('occupiedDestinations')]
    public function test_existing_file_directory_or_symlink_is_never_overwritten(string $kind): void
    {
        $artifact = $this->package();
        $input = $this->input();
        $output = $this->directory.'/occupied';
        match ($kind) {
            'file' => file_put_contents($output, 'existing'),
            'directory' => mkdir($output),
            'link' => symlink($input, $output),
            'dangling' => symlink($this->directory.'/missing', $output),
        };
        $before = lstat($output);
        $this->reject(fn () => app(Prediction::class)->run($artifact, $input, $output));
        $this->assertSame($before, lstat($output));
        $this->assertSame([], glob($output.'.inprogress-*'));
    }

    public static function occupiedDestinations(): array
    {
        return array_map(static fn ($state) => [$state], ['file', 'directory', 'link', 'dangling']);
    }

    public function test_invalid_root_and_source_overlap_are_rejected_before_creating_stage_or_lock(): void
    {
        $artifact = $this->package();
        $input = $this->input();
        $before = scandir(dirname($artifact));
        $this->reject(fn () => app(Prediction::class)->run($artifact, $input, dirname($artifact).'/prediction'));
        $this->assertSame($before, scandir(dirname($artifact)));
        $before = scandir(base_path('storage'));
        $this->reject(fn () => app(Prediction::class)->run($artifact, $input, base_path('storage/bad-composition-output')));
        $this->assertSame($before, scandir(base_path('storage')));
    }

    public function test_cli_requires_output_directory_and_rejects_legacy_or_both_options(): void
    {
        $artifact = $this->package();
        $input = $this->input();
        foreach ([['--output' => $this->directory.'/legacy'], ['--output' => $this->directory.'/legacy', '--output-dir' => $this->directory.'/new'], []] as $options) {
            $this->artisan('keirin:c1:stat35-composition-predict', $options + ['--artifact' => $artifact, '--input' => $input])
                ->expectsOutputToContain('--output-dir')->assertFailed();
        }
        $this->assertDirectoryDoesNotExist($this->directory.'/new');
        $this->assertFileDoesNotExist($this->directory.'/legacy');
    }

    public function test_two_processes_same_normalized_destination_have_exactly_one_success(): void
    {
        $artifact = $this->package();
        $input = $this->input();
        $destination = $this->directory.'/concurrent';
        $first = $this->process($artifact, $input, $destination, 'before');
        $first->start();
        try {
            $this->waitFor($destination.'.ready');
            $second = $this->process($artifact, $input, $this->directory.'/./concurrent', 'none');
            $second->run();
            $this->assertSame(1, $second->getExitCode(), $second->getErrorOutput());
            $this->assertStringContainsString('locked', $second->getErrorOutput());
            file_put_contents($destination.'.release', 'go');
            $first->wait();
            $this->assertSame(0, $first->getExitCode(), $first->getErrorOutput());
            $this->assertBundle($destination);
            $this->assertCount(1, glob($this->directory.'/.composition-lock-'.hash('sha256', $destination)));
            $this->assertSame([], glob($destination.'.inprogress-*'));
            $this->reject(fn () => app(Prediction::class)->run($artifact, $input, $destination));
        } finally {
            if ($first->isRunning()) {
                $first->stop(0);
            }
        }
    }

    #[DataProvider('interruptPoints')]
    public function test_process_interruption_preserves_commit_state_and_releases_lock(string $point): void
    {
        $artifact = $this->package();
        $input = $this->input();
        $destination = $this->directory.'/interrupted';
        $process = $this->process($artifact, $input, $destination, $point);
        $process->start();
        try {
            $this->waitFor($destination.'.ready');
        } finally {
            $process->stop(0, SIGKILL);
        }
        if ($point === 'before') {
            $this->assertDirectoryDoesNotExist($destination);
            $this->assertCount(1, glob($destination.'.inprogress-*'));
            $result = app(Prediction::class)->run($artifact, $input, $destination);
            $this->assertSame(2, $result['predictions']['rows']);
        } else {
            $this->assertBundle($destination);
            $this->assertFileDoesNotExist($destination.'/FAILED.json');
            $this->reject(fn () => app(Prediction::class)->run($artifact, $input, $destination));
        }
        $this->assertFileExists($this->directory.'/.composition-lock-'.hash('sha256', $destination));
    }

    public static function interruptPoints(): array
    {
        return [['before'], ['after']];
    }

    public function test_postcommit_exception_does_not_relabel_or_recreate_successful_bundle(): void
    {
        $artifact = $this->package();
        $input = $this->input();
        $publication = new class extends Publication
        {
            public function commit(string $stage, string $destination): void
            {
                parent::commit($stage, $destination);
                throw new RuntimeException('Auxiliary caller log failed after commit.');
            }
        };
        $output = $this->directory.'/published';
        $result = (new Prediction(app(Package::class), app(Forward::class), $publication))->run($artifact, $input, $output);
        $this->assertSame('FEATURE_ONLY_PREDICTIONS_SEALED', $result['status']);
        $this->assertStringContainsString('after commit', $result['postcommit_warning']);
        $this->assertBundle($output);
        $this->assertFileDoesNotExist($output.'/FAILED.json');
        $this->assertSame([], glob($output.'.inprogress-*'));
    }

    #[DataProvider('legacyDrifts')]
    public function test_repackage_refuses_legacy_root_model_selection_or_input_drift(string $drift): void
    {
        [$source, $pin] = $this->legacy();
        $files = ['root' => 'manifest.json', 'model' => 'run-01/package/c2/model.json', 'selection' => 'run-01/selection.json',
            'input' => 'verified-inputs/features-2025.jsonl', 'complete' => 'COMPLETE.json'];
        if ($drift === 'complete') {
            file_put_contents($source.'/COMPLETE.json', '{}');
        } else {
            file_put_contents($source.'/'.$files[$drift], "\n", FILE_APPEND);
        }
        $this->reject(fn () => $this->repackager($source, $pin)->run($source, $this->directory.'/export'));
        $this->assertDirectoryDoesNotExist($this->directory.'/export');
    }

    public static function legacyDrifts(): array
    {
        return array_map(static fn ($state) => [$state], ['root', 'model', 'selection', 'input', 'complete']);
    }

    public function test_repackage_has_no_fit_path_and_preserves_models_old_code_source_and_predictions(): void
    {
        [$source, $pin] = $this->legacy();
        $this->app->bind(Trainer::class, static fn () => throw new RuntimeException('Trainer must not be resolved.'));
        $this->app->bind(Bt03e03OneSeSelector::class, static fn () => throw new RuntimeException('Selector must not be resolved.'));
        $before = Files::identity($source.'/run-01/package/artifact.json');
        $old = Files::json($source.'/run-01/package/artifact.json');
        $result = $this->repackager($source, $pin)->run($source, $this->directory.'/export');
        $this->assertSame(0, $result['retraining_count']);
        $this->assertSame('REPACKAGED_WITHOUT_RETRAINING_AWAITING_REVIEW', $result['status']);
        $this->assertSame($before, Files::identity($source.'/run-01/package/artifact.json'));
        $this->assertSame($pin, Files::identity($source.'/manifest.json'));
        foreach ($result['read_files'] as $path => $seal) {
            Files::verify($path, $seal);
        }
        $artifact = $result['artifact'];
        $new = Files::json($artifact);
        $this->assertSame($old['generation_code'], $new['generation_code']);
        $this->assertSame($old['files'], $new['files']);
        rename(dirname($artifact), $this->directory.'/moved-export');
        $artifact = $this->directory.'/moved-export/artifact.json';
        $this->assertSame(17, app(Package::class)->load($artifact)['c2']->layout->featureCount());
        $prediction = app(Prediction::class)->run($artifact, $source.'/verified-inputs/features-2025.jsonl', $this->directory.'/prediction');
        $this->assertSame(Files::json(dirname($source).'/public-predictions-2025.jsonl.manifest.json'), $prediction['predictions']);
    }

    public function test_production_repackage_never_accepts_an_unpinned_root_even_in_testing(): void
    {
        [$source] = $this->legacy();
        $this->reject(fn () => app(Repackage::class)->run($source, $this->directory.'/export'));
        $this->assertDirectoryDoesNotExist($this->directory.'/export');
    }

    #[DataProvider('legacyPublicationFailures')]
    public function test_review_head_reproduces_failed_root_acceptance_and_partial_prediction_publication(string $boundary): void
    {
        $script = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
App\Console\Commands\Keirin\FinalC1Stat35CompositionCommand::denyExternalAccess();
foreach (['Package', 'Prediction'] as $name) {
    $p = new Symfony\Component\Process\Process(['git', 'show', '69cf3ca0bccefa2df15fb4100904d2ac2dd9eec4:app/Domain/Keirin/Backtest/Experiments/C1Stat35CompositionFinal/'.$name.'.php']);
    $p->mustRun(); eval(substr($p->getOutput(), 5));
}
$source = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$root = $argv[2].'/legacy.inprogress-test'; mkdir($root); mkdir($root.'/run-01');
$package = $app->make(App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Package::class);
$artifact = $package->stage($source['c1_artifact'], dirname($source['outer_c2'][2024]), ['lambda'=>1.0], $root.'/run-01/package', Tests\Support\C1Stat35CompositionFinalFixture::provenance($source['c1_artifact']));
$package->publish($artifact);
$package->load($artifact); $preparedAccepted = true;
App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact::json($root.'/FAILED.json', ['status'=>'FAILED_NOT_PUBLISHED']);
$package->load($artifact); $failedAccepted = true;
rename(dirname($artifact), $argv[2].'/moved'); $artifact = $argv[2].'/moved/artifact.json';
$package->load($artifact); $movedAccepted = true;
$input = $argv[2].'/input.jsonl';
App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Input::write($input, (function () {
    for ($id=1; $id<=1000; $id++) { yield Tests\Support\C1Stat35CompositionFinalFixture::feature($id); }
})());
set_error_handler(static function ($severity, $message) { throw new RuntimeException($message); });
try { $app->make(App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Prediction::class)->run($artifact, $input, $argv[2].'/output'); }
catch (Throwable $e) { $error=$e->getMessage(); }
echo json_encode(['prepared_accepted'=>$preparedAccepted, 'failed_accepted'=>$failedAccepted, 'moved_accepted'=>$movedAccepted,
    'body_public'=>is_file($argv[2].'/output'), 'manifest_public'=>is_file($argv[2].'/output.manifest.json'),
    'complete_public'=>is_file($argv[2].'/output.COMPLETE.json'), 'error'=>$error??null], JSON_THROW_ON_ERROR);
PHP;
        Jsonl::json($this->directory.'/source.json', self::$source);
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=128M', '-r', $script, $this->directory.'/source.json', $this->directory], base_path(),
            ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'CACHE_STORE' => 'array']);
        $process->start();
        try {
            $deadline = microtime(true) + 15;
            while (glob($this->directory.'/output.stage-*') === [] && $process->isRunning() && microtime(true) < $deadline) {
                usleep(1000);
            }
            $this->assertNotSame([], glob($this->directory.'/output.stage-*'), $process->getErrorOutput());
            mkdir($this->directory.'/output.'.($boundary === 'manifest' ? 'manifest.json' : 'COMPLETE.json'));
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertTrue($result['prepared_accepted']);
            $this->assertTrue($result['failed_accepted']);
            $this->assertTrue($result['moved_accepted']);
            $this->assertTrue($result['body_public']);
            $this->assertSame($boundary === 'complete', $result['manifest_public']);
            $this->assertFalse($result['complete_public']);
            $this->assertNotNull($result['error']);
        } finally {
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }
    }

    public static function legacyPublicationFailures(): array
    {
        return [['manifest'], ['complete']];
    }

    private function stage(string $path, string $owner = '.'): string
    {
        return app(Package::class)->stage(self::$source['c1_artifact'], dirname(self::$source['outer_c2'][2024]),
            ['lambda' => 1.0], $path, Fixture::provenance(self::$source['c1_artifact']), $owner);
    }

    private function package(): string
    {
        $destination = $this->directory.'/package';
        $stage = $destination.'.inprogress-'.bin2hex(random_bytes(8));
        $this->stage($stage);
        app(Publication::class)->seal($stage, $destination, 'SYNTHETIC_EXPORT', ['artifact.json']);
        app(Publication::class)->commit($stage, $destination);

        return $destination.'/artifact.json';
    }

    private function input(): string
    {
        $input = $this->directory.'/input.jsonl';
        Input::write($input, [Fixture::feature(100), Fixture::feature(2)]);

        return $input;
    }

    private function assertBundle(string $directory): void
    {
        $manifest = Files::json($directory.'/predictions.jsonl.manifest.json');
        Files::verify($directory.'/predictions.jsonl', $manifest);
        $this->assertSame($manifest, Files::json($directory.'/COMPLETE.json')['predictions']);
        $this->assertSame(2, $manifest['rows']);
    }

    private function reject(callable $action): void
    {
        try {
            $action();
            $this->fail('Expected a fail-closed publication error.');
        } catch (RuntimeException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
    }

    private function process(string $artifact, string $input, string $destination, string $point): Process
    {
        $script = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
App\Console\Commands\Keirin\FinalC1Stat35CompositionCommand::denyExternalAccess();
$publication = new class($argv[4]) extends App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Publication {
    public function __construct(private string $point) {}
    public function commit(string $stage, string $destination): void {
        if ($this->point === 'before') { $this->pause($destination); }
        parent::commit($stage, $destination);
        if ($this->point === 'after') { $this->pause($destination); }
    }
    private function pause(string $destination): void {
        file_put_contents($destination.'.ready', 'ready');
        while (!file_exists($destination.'.release')) { usleep(10000); }
    }
};
try {
    $service = new App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Prediction(
        $app->make(App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Package::class),
        $app->make(App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Forward::class), $publication);
    echo json_encode($service->run($argv[1], $argv[2], $argv[3]), JSON_THROW_ON_ERROR);
} catch (Throwable $e) { fwrite(STDERR, $e->getMessage()); exit(1); }
PHP;

        return new Process([PHP_BINARY, '-d', 'memory_limit=128M', '-r', $script, $artifact, $input, $destination, $point], base_path(),
            ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'CACHE_STORE' => 'array']);
    }

    private function waitFor(string $path): void
    {
        $deadline = microtime(true) + 15;
        while (! is_file($path) && microtime(true) < $deadline) {
            usleep(10000);
        }
        $this->assertFileExists($path, 'Child did not reach the actual commit boundary.');
    }

    private function legacy(): array
    {
        $source = Files::directory($this->directory.'/legacy');
        $code = Contract::code();
        $files = [];
        foreach (['run-01', 'run-02'] as $run) {
            Files::directory($source.'/'.$run);
            $artifact = $this->stage($source.'/'.$run.'/package');
            $data = Files::json($artifact);
            unset($data['publication_version'], $data['publication_owner']);
            unlink($artifact);
            Jsonl::json($artifact, $data);
            Jsonl::json(dirname($artifact).'/COMPLETE.json', ['artifact' => Files::identity($artifact), 'status' => 'FINAL_COMPOSITION_FIT_REPRODUCED_AWAITING_REVIEW']);
            Files::directory($source.'/'.$run.'/final');
            foreach (['model.json', 'layout.json'] as $name) {
                copy($source.'/'.$run.'/package/c2/'.$name, $source.'/'.$run.'/final/'.$name);
            }
            copy($source.'/'.$run.'/package/c2/selection.json', $source.'/'.$run.'/selection.json');
        }
        Files::directory($source.'/verified-inputs');
        $input = $source.'/verified-inputs/features-2025.jsonl';
        Input::write($input, [Fixture::feature(100), Fixture::feature(2)]);
        $prepared = $this->package();
        $prediction = app(Prediction::class)->run($prepared, $input, $this->directory.'/reference-prediction');
        foreach (['predictions.jsonl' => 'public-predictions-2025.jsonl', 'predictions.jsonl.manifest.json' => 'public-predictions-2025.jsonl.manifest.json',
            'COMPLETE.json' => 'public-predictions-2025.jsonl.COMPLETE.json'] as $from => $to) {
            copy($this->directory.'/reference-prediction/'.$from, $this->directory.'/'.$to);
        }
        Files::directory($this->directory.'/log-execute');
        Jsonl::json($this->directory.'/log-execute/execution.json', ['mode' => 'execute', 'commands' => [['exit_code' => 0, 'command' => ['synthetic-fit-record', '--output-dir='.$source]]]]);
        Jsonl::json($source.'/frozen.json', ['code' => $code]);
        Jsonl::json($source.'/reproduction.json', ['identical' => true, 'semantic_file_count' => 37,
            'files' => ['composition-before-package.jsonl' => ['bytes' => $prediction['predictions']['bytes'], 'sha256' => $prediction['predictions']['sha256']]]]);
        Jsonl::json($source.'/result.json', ['status' => 'FINAL_COMPOSITION_FIT_REPRODUCED_AWAITING_REVIEW', 'semantic_file_count' => 37,
            'new_fit_paths' => 4, 'candidate_attempts' => 8, 'c1_retraining_count' => 0]);
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $files[substr($file->getPathname(), strlen($source) + 1)] = Files::identity($file->getPathname());
        }
        Jsonl::json($source.'/manifest.json', ['status' => 'FINAL_COMPOSITION_FIT_REPRODUCED_AWAITING_REVIEW', 'contract' => Contract::plan(), 'code' => $code, 'files' => $files]);
        $pin = Files::identity($source.'/manifest.json');
        Jsonl::json($source.'/COMPLETE.json', $pin);

        return [$source, $pin];
    }

    private function repackager(string $source, array $pin): Repackage
    {
        return new class(app(Package::class), app(Publication::class), $source, $pin) extends Repackage
        {
            public function __construct(Package $packages, Publication $publication, private string $source, private array $pin)
            {
                parent::__construct($packages, $publication);
            }

            protected function sourcePin(): array
            {
                return [$this->source, $this->pin];
            }

            protected function publicationKind(): string
            {
                return 'SYNTHETIC_EXPORT';
            }

            protected function verifyCode(array $code): void
            {
                foreach ($code as $path => $seal) {
                    Files::verify(base_path($path), $seal);
                }
            }
        };
    }

    private static function remove(string $path): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() && ! $file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($path);
    }
}
