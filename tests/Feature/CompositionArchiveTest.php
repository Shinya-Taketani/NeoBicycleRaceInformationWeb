<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Keirin\Backtest\Calculators\Bt03e05MetricEvaluator;
use App\Domain\Keirin\Backtest\Experiments\C1CompositionArchive\Builder;
use App\Domain\Keirin\Backtest\Experiments\C1CompositionArchive\Metrics;
use App\Domain\Keirin\Backtest\Experiments\C1CompositionArchive\Sources;
use App\Domain\Keirin\Backtest\Experiments\C1CompositionRequest\Contract;
use App\Domain\Keirin\Backtest\Experiments\C1CompositionRequest\PredictionVerifier;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Contract as Model;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Forward;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Input;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Package;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Predictor;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact as Jsonl;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionResult\Matcher;
use App\Domain\Keirin\Presentation\CompositionArchiveView\Reader;
use App\Domain\Keirin\Presentation\CompositionResultView\Presenter;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\CompositionResultFixture;
use Tests\Support\CompositionResultTemporaryDirectory;
use Tests\TestCase;
use Throwable;

final class CompositionArchiveTest extends TestCase
{
    private ?CompositionResultTemporaryDirectory $temporary = null;

    public function createApplication(): Application
    {
        $this->temporary = CompositionResultTemporaryDirectory::create();
        $root = $this->temporary->path();
        foreach (['bootstrap', 'bootstrap/cache', 'runtime', 'compiled'] as $name) {
            mkdir($root.'/'.$name, 0700);
        }
        try {
            $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
            $this->traitsUsedByTest = class_uses_recursive(self::class);
            $app->useEnvironmentPath($root);
            $app->useBootstrapPath($root.'/bootstrap');
            $app->useStoragePath($root.'/runtime');
            $app->make(Kernel::class)->bootstrap();
            foreach (['db', 'redis', Factory::class, Package::class,
                Forward::class,
                Predictor::class] as $service) {
                $app->bind($service, fn () => throw new RuntimeException('External data / inference forbidden.'));
            }
            $app['config']->set(['view.compiled' => $root.'/compiled', 'logging.default' => 'null', 'app.key' => null,
                'composition_archive_view.enabled' => true]);

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

    public function test_201_races_preserve_prediction_order_cross_page_boundaries_and_original_predictions(): void
    {
        $data = $this->sources(201);
        $built = $this->build($data);
        $this->assertSame(201, $built['matched']);
        $this->assertSame(3, $built['pages']);
        $ids = [];
        foreach ([1 => 100, 2 => 100, 3 => 1] as $page => $size) {
            $saved = app(Reader::class)->read($page);
            $this->assertCount($size, $saved['rows']);
            foreach ($saved['rows'] as $row) {
                $id = $row['input']['race_id'];
                $ids[] = $id;
                $this->assertSame(CompositionResultFixture::prediction($row['input']), $row['joined']['prediction']);
                $this->assertSame(array_column($row['input']['entries'], 'id'), array_column($row['joined']['context']['entries'], 'id'));
            }
        }
        $this->assertSame(array_reverse(range(7000, 7200)), $ids);
        $this->assertSame(3, app(Reader::class)->read(null, 7000)['page']);
        $this->local('/development/keirin/composition-archive/2025?race_id=7000')->assertRedirect('/development/keirin/composition-archive/2025/7000');
        $this->local('/development/keirin/composition-archive/2025?page=3')->assertOk();
        $this->local('/development/keirin/composition-archive/2025/7000')->assertOk()->assertSee('70001')->assertSee('未収録');
        $this->assertNull(app(Reader::class)->read(null, 99999));
    }

    public function test_outcomes_join_by_id_not_order_and_null_third_and_ties_keep_metric_denominators(): void
    {
        $data = $this->sources(3, 'outcomes');
        $built = $this->build($data);
        $summary = $built['summary'];
        $this->assertSame(2.0, $summary['metrics']['POSITION_1_ACCURACY']['denominator']);
        $this->assertSame(2.0, $summary['metrics']['POSITION_3_ACCURACY']['denominator']);
        $this->assertSame(3.0, $summary['metrics']['POSITION_HIT_RATE_AT_3']['denominator']);
        $this->local('/development/keirin/composition-archive/2025/7000')->assertOk()->assertSee('評価対象外')->assertSee('1・2');
        $this->local('/development/keirin/composition-archive/2025/7001')->assertOk()->assertSee('公式順位なし');
        $this->assertNull($data['input'][0]['entries'][0]['stat35_mean6']);
        $this->assertSame(0, $data['input'][0]['entries'][0]['history'][0]);
        $this->assertSame('未取得', Presenter::percentage(null));
        $this->assertSame('0.0000%', Presenter::percentage(0));
    }

    #[DataProvider('invalidSources')]
    public function test_incomplete_duplicate_mismatched_or_contaminated_sources_never_publish(string $kind): void
    {
        $data = $this->sources(3, $kind);
        try {
            $this->build($data);
            $this->fail('Invalid source must fail.');
        } catch (Throwable $error) {
            $this->assertNotSame('', $error->getMessage());
            $this->assertDirectoryDoesNotExist($this->root().'/archive');
        }
    }

    public static function invalidSources(): array
    {
        return array_map(fn ($v) => [$v], ['missing-input', 'extra-input', 'duplicate-input', 'missing-result', 'extra-result',
            'duplicate-result', 'wrong-bike', 'wrong-entry', 'duplicate-prediction', 'corrupt-prediction', 'input-2026', 'label-2026']);
    }

    public function test_predictions_are_validated_before_any_label_is_parsed(): void
    {
        $data = $this->sources(3, 'corrupt-prediction');
        $h = fopen($data['sources']['labels']['path'], 'wb');
        fwrite($h, "not-json\n");
        fclose($h);
        // Keep the explicit synthetic seal coherent so failure order tests parsing, not the initial hash gate.
        $data['sources']['labels']['seal'] = ['rows' => 1, ...Files::identity($data['sources']['labels']['path'])];
        file_put_contents($data['sources']['labels']['path'].'.manifest.json', Files::canonical($data['sources']['labels']['seal']));
        try {
            $this->build($data);
            $this->fail('Must fail on prediction first.');
        } catch (RuntimeException $error) {
            $this->assertStringNotContainsString('Syntax', $error->getMessage());
            $this->assertStringContainsString('Saved prediction validation failed', $error->getMessage());
        }
    }

    public function test_source_end_drift_refuses_publication_and_preserves_failed_stage(): void
    {
        $data = $this->sources();
        $sources = new class($data['sources']) extends Sources
        {
            public function end(array $start): void
            {
                throw new RuntimeException('Synthetic source END drift');
            }
        };
        try {
            $this->builder($sources)->build($this->root().'/archive');
            $this->fail('Drift must fail.');
        } catch (RuntimeException $error) {
            $this->assertSame('Synthetic source END drift', $error->getMessage());
        }
        $this->assertDirectoryDoesNotExist($this->root().'/archive');
        $this->assertCount(1, glob($this->root().'/archive.inprogress-*/FAILED.json'));
    }

    #[DataProvider('invalidQueries')]
    public function test_strict_ids_pages_and_http_cannot_select_arbitrary_files(string $query): void
    {
        $this->build($this->sources());
        $this->local('/development/keirin/composition-archive/2025'.$query)->assertNotFound();
    }

    public static function invalidQueries(): array
    {
        return array_map(fn ($v) => [$v], ['?page=0', '?page=01', '?page=-1', '?page=2', '?page[]=1', '?page=abc',
            '?race_id=01', '?race_id=0', '?race_id=99999', '?race_id[]=7000', '?race_id=99999999999999999999',
            '?root=/etc', '?year=2026', '/0', '/70000']);
    }

    #[DataProvider('broken')]
    public function test_missing_or_corrupt_pages_summary_and_pin_are_rejected_without_fallback(string $kind): void
    {
        $this->build($this->sources());
        if ($kind === 'pin') {
            config(['composition_archive_view.manifest.sha256' => str_repeat('0', 64)]);
        } elseif ($kind === 'unconfigured') {
            config(['composition_archive_view.root' => null]);
        } elseif ($kind === 'missing-page') {
            rename($this->root().'/archive/pages/000001.jsonl', $this->root().'/archive/pages/held-page.jsonl');
        } else {
            file_put_contents($this->root().'/archive/'.$kind, '{}');
        }
        $this->local('/development/keirin/composition-archive/2025')->assertStatus(503)->assertDontSee('/home/shinya')->assertDontSee('RuntimeException');
    }

    public static function broken(): array
    {
        return [['pin'], ['unconfigured'], ['missing-page'], ['summary.json'], ['pages/000001.jsonl'], ['race-index.jsonl']];
    }

    public function test_get_search_csp_escape_no_sessions_and_no_source_or_model_access(): void
    {
        $this->build($this->sources());
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->post('/development/keirin/composition-archive/2025')->assertStatus(405);
        $manifest = Files::json($this->root().'/archive/manifest.json');
        $manifest['generated_at'] = '<script>alert(1)</script>';
        file_put_contents($this->root().'/archive/manifest.json', Files::canonical($manifest));
        $pin = Files::identity($this->root().'/archive/manifest.json');
        file_put_contents($this->root().'/archive/COMPLETE.json', Files::canonical($pin));
        config(['composition_archive_view.manifest' => $pin, 'session.driver' => 'database']);
        $this->app->bind('session.store', fn () => throw new RuntimeException('No session.'));
        rename($this->root().'/sources', $this->root().'/held-sources');
        $response = $this->local('/development/keirin/composition-archive/2025')->assertOk()->assertSee($manifest['generated_at'])
            ->assertDontSee('<script>', false)->assertSee('method="get"', false)->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringContainsString("form-action 'self'", $response->headers->get('Content-Security-Policy'));
        $this->assertFalse($response->headers->has('Set-Cookie'));
        $this->local('/development/keirin/composition-archive/2025/7000')->assertOk();
    }

    #[DataProvider('denied')]
    public function test_denied_before_reader_is_resolved(mixed $flag, string $env, string $address): void
    {
        config(['composition_archive_view.enabled' => $flag]);
        $this->app->instance('env', $env);
        $this->app->bind(Reader::class, fn () => throw new RuntimeException('Must not read.'));
        $this->withServerVariables(['REMOTE_ADDR' => $address])->get('/development/keirin/composition-archive/2025')->assertNotFound();
    }

    public static function denied(): array
    {
        return [[false, 'local', '127.0.0.1'], [true, 'production', '127.0.0.1'], [true, 'local', '192.0.2.1'], ['true', 'testing', '127.0.0.1']];
    }

    public function test_existing_destination_is_never_overwritten_and_plan_does_not_read_sources(): void
    {
        $this->build($this->sources());
        $pin = Files::identity($this->root().'/archive/manifest.json');
        $this->artisan('keirin:c1:composition-archive', ['action' => 'plan'])->assertSuccessful();
        $this->assertSame($pin, Files::identity($this->root().'/archive/manifest.json'));
        $this->expectException(RuntimeException::class);
        $this->builder(new Sources([]))->build($this->root().'/archive');
    }

    private function root(): string
    {
        return $this->temporary->path();
    }

    private function local(string $url): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->get($url);
    }

    private function builder(Sources $sources): Builder
    {
        return new Builder($sources, CompositionResultFixture::publication($this->root()), app(Matcher::class),
            app(PredictionVerifier::class), app(Bt03e05MetricEvaluator::class), app(Metrics::class));
    }

    private function build(array $data): array
    {
        $built = $this->builder(new Sources($data['sources']))->build($this->root().'/archive');
        config(['composition_archive_view.root' => $built['path'], 'composition_archive_view.manifest' => $built['manifest']]);

        return $built;
    }

    private function sources(int $count = 3, string $kind = 'normal'): array
    {
        $root = Files::directory($this->root().'/sources');
        Files::directory($root.'/predictions');
        $input = $predictions = $labels = [];
        foreach (range(7000, 7000 + $count - 1) as $id) {
            $race = CompositionResultFixture::race($id, 7);
            $input[] = $race;
            $predictions[] = CompositionResultFixture::prediction($race);
            $labels[] = ['year' => 2025, 'race_id' => $id, 'entries' => array_map(fn ($e) => [
                'id' => $e['id'], 'bike' => $e['bike'], 'rank' => $e['bike'], 'status' => 'FINISHED', 'raw' => 'IGNORED_RESULT_FEATURE',
            ], array_reverse($race['entries']))];
        }
        if ($kind === 'outcomes') {
            foreach ($labels[0]['entries'] as &$entry) {
                if (in_array($entry['bike'], [1, 2], true)) {
                    $entry['rank'] = 1;
                    $entry['status'] = 'TIED';
                }
            }
            unset($entry);
            foreach ($labels[1]['entries'] as &$entry) {
                if ($entry['bike'] === 3) {
                    $entry['rank'] = null;
                    $entry['status'] = 'DISQUALIFIED';
                }
            }
            unset($entry);
        }
        match ($kind) {
            'missing-input' => array_pop($input),
            'extra-input' => $input[] = CompositionResultFixture::race(9999, 7),
            'duplicate-input' => $input[] = $input[0],
            'missing-result' => array_pop($labels),
            'extra-result' => $labels[] = ['year' => 2025, 'race_id' => 9999, 'entries' => $labels[0]['entries']],
            'duplicate-result' => $labels[] = $labels[0],
            'wrong-bike' => $labels[0]['entries'][0]['bike'] = 8,
            'wrong-entry' => $labels[0]['entries'][0]['id'] = 99999,
            'duplicate-prediction' => $predictions[] = $predictions[0],
            'corrupt-prediction' => $predictions[0]['probabilities']['entries'][0]['position_1_probability'] = -1.0,
            'input-2026' => $input[0]['year'] = 2026,
            'label-2026' => $labels[0]['year'] = 2026,
            default => null,
        };
        $inputPath = $root.'/input.jsonl';
        $predictionPath = $root.'/predictions/predictions.jsonl';
        $labelPath = $root.'/labels.jsonl';
        $inputSeal = Input::write($inputPath, $input);
        $predictionSeal = Jsonl::write($predictionPath, array_reverse($predictions));
        $labelSeal = Jsonl::write($labelPath, $labels);
        Jsonl::json(dirname($predictionPath).'/COMPLETE.json', ['status' => 'FEATURE_ONLY_PREDICTIONS_SEALED',
            'publication_version' => Model::PUBLICATION_VERSION, 'output_dir' => dirname($predictionPath),
            'predictions_path' => $predictionPath, 'predictions' => $predictionSeal, 'input' => Files::identity($inputPath),
            'runtime_code' => ['synthetic'], 'package' => Contract::ARTIFACT_SEAL]);

        return ['input' => $input, 'sources' => [
            'predictions' => ['path' => $predictionPath, 'seal' => $predictionSeal],
            'input' => ['path' => $inputPath, 'seal' => $inputSeal], 'labels' => ['path' => $labelPath, 'seal' => $labelSeal]]];
    }
}
