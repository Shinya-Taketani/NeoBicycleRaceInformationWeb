<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Keirin\Backtest\Experiments\C1CompositionRequest\Contract as RequestContract;
use App\Domain\Keirin\Backtest\Experiments\C1CompositionRequest\Store as RequestStore;
use App\Domain\Keirin\Backtest\Experiments\C1CompositionResult\Calculation;
use App\Domain\Keirin\Backtest\Experiments\C1CompositionResult\Contract as ResultContract;
use App\Domain\Keirin\Backtest\Experiments\C1CompositionResult\Store;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Contract as ModelContract;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Forward;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Package;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Predictor;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Presentation\CompositionResultView\Presenter;
use App\Domain\Keirin\Presentation\CompositionResultView\Reader;
use App\Http\Middleware\LocalCompositionViewAccess;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\CompositionResultFixture;
use Tests\Support\CompositionResultTemporaryDirectory;
use Tests\TestCase;
use Throwable;

final class CompositionResultViewTest extends TestCase
{
    private ?CompositionResultTemporaryDirectory $temporary = null;

    private ?CompositionResultFixture $fixture = null;

    private ?Store $readerStore = null;

    public function createApplication(): Application
    {
        $this->temporary = CompositionResultTemporaryDirectory::create();
        $root = $this->temporary->path();
        foreach (['bootstrap', 'bootstrap/cache', 'runtime', 'compiled'] as $name) {
            mkdir($root.'/'.$name, 0700);
        }
        try {
            $environment = fopen($root.'/.env', 'xb');
            if ($environment === false) {
                throw new RuntimeException('Could not create the isolated test environment file.');
            }
            try {
                $content = "# Isolated test fixture. No application secrets.\n";
                if (fwrite($environment, $content) !== strlen($content) || ! fflush($environment)) {
                    throw new RuntimeException('Could not write the isolated test environment file.');
                }
            } finally {
                fclose($environment);
            }
            $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
            $this->traitsUsedByTest = class_uses_recursive(self::class);
            $app->useEnvironmentPath($root);
            $app->useBootstrapPath($root.'/bootstrap');
            $app->useStoragePath($root.'/runtime');
            $app->make(Kernel::class)->bootstrap();
            foreach (['db', 'redis', Factory::class, Package::class, Forward::class, Predictor::class] as $service) {
                $app->bind($service, fn () => throw new RuntimeException('External data / inference is forbidden in view tests.'));
            }
            $app['config']->set(['view.compiled' => $root.'/compiled', 'logging.default' => 'null', 'app.key' => null,
                'composition_result_view.enabled' => true]);

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
        parent::tearDown();
        $this->fixture?->retire($reason);
        if ($this->temporary !== null) {
            $this->temporary->retire($this->temporary->path(), $reason);
        }
    }

    public function test_index_uses_verified_saved_metrics_once_and_has_ten_detail_links(): void
    {
        $this->assertSame($this->temporary->path().'/.env', $this->app->environmentFilePath());
        $this->assertFileExists($this->app->environmentFilePath());
        $this->assertSame("# Isolated test fixture. No application secrets.\n", file_get_contents($this->app->environmentFilePath()));
        $saved = $this->bundle();
        $before = $this->inventory($saved['path']);
        $response = $this->localGet('/development/keirin/composition-results')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $overview = app(Presenter::class)->overview($saved);
        foreach ($overview['metrics'] as $metric) {
            $response->assertSee($metric['rate'])->assertSee($metric['numerator'].' / '.$metric['denominator']);
        }
        $response->assertSee('位置Hit@3')->assertSee('Primary完全順序一致')
            ->assertSee('開発用・2025年の保存データ')->assertSee('将来レースの精度を示すものではありません')
            ->assertSee('発走前取得時点の保証なし／LIVE利用未承認');
        $this->assertSame(10, substr_count($response->getContent(), 'class="detail-link"'));
        $this->assertSame(1, $this->readerStore->reads);
        $this->assertFalse($response->headers->has('Set-Cookie'));
        $this->assertSame($before, $this->inventory($saved['path']));
        $response->assertDontSee('/home/shinya')->assertDontSee('/var/www')->assertDontSee('utilities');
    }

    public function test_detail_preserves_saved_primary_and_joins_probability_and_result_by_entry_id_and_bike(): void
    {
        $saved = $this->bundle();
        $detail = app(Reader::class)->detail($saved, 7000);
        $response = $this->localGet('/development/keirin/composition-results/7000')->assertOk();
        $response->assertSee('出走ID')->assertSee('2着周辺確率')->assertSee('3着周辺確率')->assertSee('一覧へ戻る');
        foreach ($detail['prediction']['probabilities']['entries'] as $entry) {
            $response->assertSee('data-entry-id="'.$entry['id'].'" data-bike="'.$entry['bike'].'"', false);
            foreach ([1, 2, 3] as $position) {
                $response->assertSee(Presenter::percentage($entry['position_'.$position.'_probability']));
            }
        }
        $this->assertSame(1, $this->readerStore->reads);
        $response->assertDontSee('Q2')->assertDontSee('Q3')->assertSee('未収録');
    }

    public function test_missing_official_third_position_is_excluded_not_a_miss(): void
    {
        $this->bundle('missing-third');
        $this->localGet('/development/keirin/composition-results/7000')->assertOk()
            ->assertSee('公式順位なし')->assertSee('評価対象外')->assertSee('公式順位が一意でないため評価対象外');
        $this->localGet('/development/keirin/composition-results')->assertOk()->assertSee('評価除外 1 レース');
    }

    public function test_tied_official_position_is_a_set_and_null_denominator_is_not_zero_percent(): void
    {
        $this->bundle('tie');
        $this->localGet('/development/keirin/composition-results/7000')->assertOk()->assertSee('1・2')->assertSee('評価対象外');
        $this->assertSame('未取得', Presenter::percentage(null));
        $this->assertSame('0.0000%', Presenter::percentage(0));
    }

    #[DataProvider('denied')]
    public function test_access_is_denied_before_reader_resolution(bool|string $enabled, string $environment, string $remote): void
    {
        config(['composition_result_view.enabled' => $enabled]);
        $this->app->instance('env', $environment);
        $this->app->bind(Reader::class, fn () => throw new RuntimeException('Reader must not be opened.'));
        $this->withServerVariables(['REMOTE_ADDR' => $remote])->get('/development/keirin/composition-results',
            ['Host' => 'localhost', 'X-Forwarded-For' => '127.0.0.1'])->assertNotFound()->assertHeader('Cache-Control', 'no-store, private');
    }

    public static function denied(): array
    {
        return [[false, 'local', '127.0.0.1'], [true, 'production', '127.0.0.1'], [true, 'staging', '127.0.0.1'],
            [true, 'local', '192.0.2.10'], [true, 'testing', '192.0.2.10'], ['true', 'local', '127.0.0.1'], [true, 'local', '']];
    }

    public function test_local_ipv6_loopback_is_allowed_without_sessions_or_an_application_key(): void
    {
        $this->bundle();
        $this->app->instance('env', 'local');
        $this->withServerVariables(['REMOTE_ADDR' => '::1'])->get('/development/keirin/composition-results')
            ->assertOk()->assertCookieMissing(config('session.cookie'));
    }

    #[DataProvider('statefulDrivers')]
    public function test_view_and_error_responses_never_resolve_a_stateful_session(string $driver): void
    {
        $this->bundle();
        config(['session.driver' => $driver]);
        $this->app->bind('session.store', fn () => throw new RuntimeException('Session must not be resolved.'));
        $this->app->bind('db', fn () => throw new RuntimeException('DB must not be resolved.'));
        $this->app->bind('redis', fn () => throw new RuntimeException('Redis must not be resolved.'));
        $this->localGet('/development/keirin/composition-results')->assertOk();
        $this->localGet('/development/keirin/composition-results/7000')->assertOk();
        config(['composition_result_view.enabled' => false]);
        $this->localGet('/development/keirin/composition-results')->assertNotFound();
        config(['composition_result_view.enabled' => true, 'composition_result_view.manifest.sha256' => str_repeat('0', 64)]);
        $this->localGet('/development/keirin/composition-results')->assertStatus(503);
    }

    public static function statefulDrivers(): array
    {
        return [['database'], ['redis']];
    }

    #[DataProvider('invalidRaces')]
    public function test_invalid_or_unselected_races_are_404(string $id): void
    {
        $this->bundle();
        $this->localGet('/development/keirin/composition-results/'.$id)->assertNotFound();
    }

    public static function invalidRaces(): array
    {
        return [['0'], ['01'], ['-1'], ['abc'], ['999999999999999999999999'], ['70000']];
    }

    #[DataProvider('broken')]
    public function test_missing_corrupt_or_wrong_pin_bundle_is_503_without_regeneration_or_internal_details(string $kind): void
    {
        $saved = $this->bundle();
        if ($kind === 'missing') {
            config(['composition_result_view.evaluation_id' => 'missing-01']);
        } elseif ($kind === 'wrong-pin') {
            config(['composition_result_view.manifest.sha256' => str_repeat('0', 64)]);
        } else {
            file_put_contents($saved['path'].'/summary.json', '{}');
        }
        config(['app.debug' => true]);
        $before = $this->inventory($this->fixture->output);
        $this->localGet('/development/keirin/composition-results')->assertStatus(503)->assertSee('保存結果を確認できません')
            ->assertDontSee('/home/shinya')->assertDontSee('/var/www')->assertDontSee('RuntimeException')->assertDontSee('stack');
        $this->assertSame($before, $this->inventory($this->fixture->output));
    }

    public static function broken(): array
    {
        return [['missing'], ['corrupt'], ['wrong-pin']];
    }

    public function test_saved_metadata_is_escaped_and_query_cannot_change_sources(): void
    {
        $saved = $this->bundle();
        $manifest = Files::json($saved['path'].'/manifest.json');
        $manifest['generated_at'] = '<script>alert("saved")</script>';
        file_put_contents($saved['path'].'/manifest.json', $this->jsonBytes($manifest));
        $pin = Files::identity($saved['path'].'/manifest.json');
        file_put_contents($saved['path'].'/COMPLETE.json', $this->jsonBytes($pin));
        config(['composition_result_view.manifest' => $pin]);
        $this->localGet('/development/keirin/composition-results?root=/etc&year=2026&manifest=bad')->assertOk()
            ->assertSee($manifest['generated_at'])->assertDontSee('<script>', false)->assertDontSee('/etc');
    }

    public function test_only_two_get_routes_exclude_stateful_middleware_and_home_is_unchanged(): void
    {
        $this->app->make(\Illuminate\Contracts\Http\Kernel::class);
        $router = $this->app['router'];
        foreach (['development.composition-results.index', 'development.composition-results.show'] as $name) {
            $route = $router->getRoutes()->getByName($name);
            $this->assertSame(['GET', 'HEAD'], $route->methods());
            $middleware = $router->gatherRouteMiddleware($route);
            $this->assertContains(LocalCompositionViewAccess::class, $middleware);
            $this->assertNotContains(StartSession::class, $middleware);
            $this->assertNotContains(EncryptCookies::class, $middleware);
        }
        $home = $router->getRoutes()->match(Request::create('/'));
        $this->assertInstanceOf(\Closure::class, $home->getAction('uses'));
        $this->assertContains(StartSession::class, $router->gatherRouteMiddleware($home));
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->post('/development/keirin/composition-results')->assertStatus(405);
    }

    public function test_view_files_do_not_change_existing_model_request_or_result_code_identity(): void
    {
        $before = [ModelContract::code(), RequestContract::code(), ResultContract::code()];
        $this->bundle();
        $this->localGet('/development/keirin/composition-results')->assertOk();
        $this->assertSame($before, [ModelContract::code(), RequestContract::code(), ResultContract::code()]);
    }

    private function bundle(string $kind = 'normal'): array
    {
        $f = $this->fixture = new CompositionResultFixture(7, 10);
        if ($kind !== 'normal') {
            foreach ($f->labelRows[0]['entries'] as &$entry) {
                if ($kind === 'missing-third' && $entry['bike'] === 3) {
                    $entry['rank'] = null;
                    $entry['status'] = 'DISQUALIFIED';
                } elseif ($kind === 'tie' && in_array($entry['bike'], [1, 2], true)) {
                    $entry['rank'] = 1;
                    $entry['status'] = 'TIED';
                }
            }
            unset($entry);
            $f->labels = $f->root.'/label-source/'.$kind.'.jsonl';
            JsonlArtifact::write($f->labels, $f->labelRows);
        }
        $created = $f->execute();
        $store = $this->readerStore = new class(app(Calculation::class), app(RequestStore::class), $f) extends Store
        {
            public int $reads = 0;

            public function __construct(Calculation $calculation, RequestStore $requests, private readonly CompositionResultFixture $fixture)
            {
                parent::__construct(CompositionResultFixture::publication($fixture->root), $calculation, $requests);
            }

            protected function allowedParent(): string
            {
                return $this->fixture->root;
            }

            public function verify(string $path, string $id): array
            {
                $this->reads++;

                return parent::verify($path, $id);
            }
        };
        $this->app->instance(Store::class, $store);
        config(['composition_result_view.root' => $f->output, 'composition_result_view.evaluation_id' => 'synthetic-01',
            'composition_result_view.manifest' => $created['manifest']]);

        return ['path' => $created['path'], ...$store->verify($created['path'], 'synthetic-01')];
    }

    private function localGet(string $uri): TestResponse
    {
        if ($this->readerStore !== null) {
            $this->readerStore->reads = 0;
        }

        return $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->get($uri);
    }

    private function jsonBytes(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)."\n";
    }

    private function inventory(string $path): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                $files[substr($file->getPathname(), strlen($path))] = Files::identity($file->getPathname());
            }
        }
        ksort($files);

        return $files;
    }
}
