<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\Keirin\FinalC1Stat35CompositionCommand;
use App\Domain\Keirin\Backtest\Calculators\Bt03e03OneSeSelector;
use App\Domain\Keirin\Backtest\Calculators\Bt03e06WinnerConditionedDecoder;
use App\Domain\Keirin\Backtest\Experiments\C1CompositionRequest\Contract;
use App\Domain\Keirin\Backtest\Experiments\C1CompositionRequest\PredictionVerifier;
use App\Domain\Keirin\Backtest\Experiments\C1CompositionRequest\Predictor;
use App\Domain\Keirin\Backtest\Experiments\C1CompositionRequest\Service;
use App\Domain\Keirin\Backtest\Experiments\C1CompositionRequest\Sources;
use App\Domain\Keirin\Backtest\Experiments\C1CompositionRequest\Store;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Contract as Model;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Forward;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Input;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Package;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Publication;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Repackage;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35P1Composition\ProbabilityCalculator;
use App\Domain\Keirin\Backtest\Experiments\Stat35C1Comparison\Optimizer;
use App\Domain\Keirin\Backtest\Experiments\Stat35C1Comparison\Trainer;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact as Jsonl;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\C1Stat35CompositionFinalFixture as Models;
use Tests\Support\C1Stat35RepackageFixture as Fixture;
use Tests\TestCase;
use Throwable;

class CompositionPredictionRequestTest extends TestCase
{
    private static string $shared;

    private static ?array $bundle = null;

    private string $root;

    private string $store;

    private Sources $sources;

    protected function setUp(): void
    {
        parent::setUp();
        FinalC1Stat35CompositionCommand::denyExternalAccess();
        if (self::$bundle === null) {
            self::$shared = Files::directory('/tmp/composition-request-models-'.bin2hex(random_bytes(8)));
            ob_start();
            try {
                $models = Models::make(self::$shared.'/models');
                Files::directory(self::$shared.'/fixture');
                $pin = Fixture::make(self::$shared.'/fixture', $models);
                $result = Fixture::repackage($pin, new Publication)->run($pin->source, self::$shared.'/package');
                self::$bundle = ['pin' => $pin, 'artifact' => $result['artifact'], 'code' => Model::code()];
            } finally {
                ob_end_clean();
            }
        }
        Fixture::bind(self::$bundle['pin']);
        $this->root = Files::directory('/tmp/composition-request-test-'.bin2hex(random_bytes(8)));
        $this->store = $this->root.'/request-store-01-fixture';
        Files::directory($this->root.'/sources');
        $input = $this->root.'/sources/annual.jsonl';
        Input::write($input, [Models::feature(100), Models::feature(2)]);
        $this->sources = $this->source($input);
        $this->app->instance(Sources::class, $this->sources);
    }

    protected function tearDown(): void
    {
        self::remove($this->root);
        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$bundle !== null) {
            self::remove(self::$shared);
            self::$bundle = null;
        }
        parent::tearDownAfterClass();
    }

    public function test_create_show_reuse_and_reproduce_preserve_forward_primary_supporting_and_code(): void
    {
        foreach ([Trainer::class,
            Optimizer::class,
            Bt03e03OneSeSelector::class,
            Repackage::class] as $class) {
            $this->app->bind($class, static fn () => throw new RuntimeException('Forbidden training / repackage dependency.'));
        }
        $service = app(Service::class);
        $created = $service->create($this->store, 'one', '2025', '100');
        $this->assertSame('CREATED', $created['status']);
        $model = $this->sources->load(app(Package::class));
        $expected = app(Forward::class)->predict(Models::feature(100), $model['c1'], $model['c2']);
        $saved = (new Store)->read($this->store, 'one');
        $this->assertSame($expected, $saved['prediction']);
        $this->assertSame($expected['decision'], $created['decision']);
        $this->assertSame($expected['probabilities']['entries'][0]['position_2_probability'], $created['marginals'][0]['p2']);
        $this->assertNull($created['input_as_of']);
        $before = $this->tree($created['path']);
        $this->assertSame('REPRODUCED', $service->reproduce($this->store, 'one')['status']);
        $this->assertSame(self::$bundle['code'], Model::code());
        $this->assertSame($before, $this->tree($created['path']));
        foreach (['', '.manifest.json', '.input.json'] as $suffix) {
            unlink($this->sources->input.$suffix);
        }
        foreach ([Predictor::class, Package::class, Forward::class] as $class) {
            $this->app->bind($class, static fn () => throw new RuntimeException('Inference dependency forbidden for saved reads.'));
        }
        // Read-only operations must not even resolve the package/predictor dependency.
        $this->artisan('keirin:c1:composition-request', ['mode' => 'show', '--store-root' => $this->store, '--request-id' => 'one', '--json' => true])->assertSuccessful();
        $this->artisan('keirin:c1:composition-request', ['mode' => 'create', '--store-root' => $this->store, '--request-id' => 'one', '--year' => '2025', '--race-id' => '100', '--json' => true])->assertSuccessful();
        $this->assertSame($before, $this->tree($created['path']));
    }

    public function test_conflict_not_found_and_unpublished_stage_never_create_or_overwrite(): void
    {
        $service = app(Service::class);
        $this->assertSame('NOT_FOUND', $service->show($this->store, 'missing')['status']);
        $this->assertDirectoryDoesNotExist($this->store);
        $created = $service->create($this->store, 'one', '2025', '100');
        $before = $this->tree($created['path']);
        $this->reject(fn () => $service->create($this->store, 'one', '2025', '2'), 'CONFLICT');
        $this->assertSame($before, $this->tree($created['path']));
        mkdir($this->store.'/requests/stage.inprogress-test');
        $this->assertSame('NOT_FOUND', $service->show($this->store, 'stage')['status']);
        unlink($created['path'].'/COMPLETE.json');
        $this->reject(fn () => $service->create($this->store, 'one', '2025', '100'), 'inventory');
        $this->assertFileDoesNotExist($created['path'].'/COMPLETE.json');
    }

    #[DataProvider('validFields')]
    public function test_extract_preserves_whole_nonmonotone_input_and_null_zero_missing_bikes(int $count): void
    {
        $race = Models::feature(7, 2025, $count);
        if ($count < 9) {
            $race['entries'][$count - 1]['bike'] = 9;
        }
        $race['entries'][0]['stat35_mean6'] = 0;
        $race['entries'] = array_reverse($race['entries']);
        $path = $this->root.'/sources/different.jsonl';
        Input::write($path, [Models::feature(100), $race, Models::feature(2)]);
        $source = $this->source($path);
        $this->assertSame($race, $source->extract(2025, 7));
        $this->app->instance(Sources::class, $source);
        $created = app(Service::class)->create($this->store, 'whole', '2025', '7');
        $this->assertCount($count, $created['marginals']);
        $this->assertSame($race, (new Store)->read($this->store, 'whole')['input']);
    }

    public static function validFields(): array
    {
        return [[5], [6], [7], [8], [9]];
    }

    #[DataProvider('badTail')]
    public function test_target_found_does_not_hide_invalid_or_duplicate_tail(string $fault): void
    {
        $tail = Models::feature(2);
        if ($fault === 'duplicate') {
            $tail = Models::feature(100);
        } elseif ($fault === 'entry-duplicate') {
            $tail['entries'][0]['id'] = 1001;
        } elseif ($fault === '2026') {
            $tail['year'] = 2026;
        } else {
            $tail['entries'][0][$fault] = 1;
        }
        $path = $this->root.'/sources/bad-tail.jsonl';
        Input::write($path, [Models::feature(100), $tail]);
        $this->app->instance(Sources::class, $this->source($path));
        $this->reject(fn () => app(Service::class)->create($this->store, 'one', '2025', '100'));
        $this->assertDirectoryDoesNotExist($this->store.'/requests/one');
        $this->assertCount(1, glob($this->store.'/requests/one.inprogress-*/FAILED.json'));
    }

    public static function badTail(): array
    {
        return [['duplicate'], ['entry-duplicate'], ['2026'], ['rank'], ['status'], ['actual'], ['unknown']];
    }

    #[DataProvider('invalidRequests')]
    public function test_invalid_identifiers_and_forbidden_year_fail_before_source_or_writes(string $id, string $year, string $race): void
    {
        unlink($this->sources->input);
        $this->reject(fn () => app(Service::class)->create($this->store, $id, $year, $race));
        $this->assertDirectoryDoesNotExist($this->store);
    }

    public static function invalidRequests(): array
    {
        return [['../x', '2025', '100'], ['', '2025', '100'], ['x/y', '2025', '100'], [str_repeat('a', 97), '2025', '100'],
            ["a\0b", '2025', '100'], ['x', '2026', '100'], ['x', '2025xxx', '100'], ['x', '2025', '1x'], ['x', '2025', '0']];
    }

    #[DataProvider('tamperedFiles')]
    public function test_saved_body_sidecar_manifest_and_complete_drift_are_rejected_without_repair(string $name): void
    {
        $created = app(Service::class)->create($this->store, 'one', '2025', '100');
        file_put_contents($created['path'].'/'.$name, ' ', FILE_APPEND);
        $before = $this->tree($created['path']);
        $this->reject(fn () => app(Service::class)->show($this->store, 'one'));
        $this->reject(fn () => app(Service::class)->create($this->store, 'one', '2025', '100'));
        $this->assertSame($before, $this->tree($created['path']));
    }

    public static function tamperedFiles(): array
    {
        return array_map(static fn ($name) => [$name], [...Contract::FILES, 'manifest.json', 'COMPLETE.json']);
    }

    #[DataProvider('resealedSemanticFaults')]
    public function test_resealed_prediction_semantics_are_rejected_by_show_and_reuse_without_repair(string $fault, array $field = [], mixed $value = null): void
    {
        if ($fault === 'tie' || $fault === 'tie-pair') {
            $this->utilityPredictor('tie');
        } elseif ($fault === 'swap' || $fault === 'primary') {
            $this->utilityPredictor('conditioned');
        }
        $created = app(Service::class)->create($this->store, 'one', '2025', '100');
        $original = $this->tree($created['path']);
        $copy = $this->root.'/request-store-01-copy';
        (new Store)->prepare($copy);
        Files::directory($copy.'/requests/one');
        foreach (array_keys($original) as $name) {
            copy($created['path'].'/'.$name, $copy.'/requests/one/'.$name);
        }
        $path = $copy.'/requests/one';
        $prediction = iterator_to_array(Jsonl::read($path.'/prediction.jsonl'))[0];
        if ($fault === 'zero' || str_starts_with($fault, 'sum-')) {
            foreach ($prediction['probabilities']['entries'] as &$entry) {
                foreach ([1, 2, 3] as $position) {
                    if ($fault === 'zero' || $fault === 'sum-'.$position) {
                        $entry['position_'.$position.'_probability'] *= $fault === 'zero' ? 0.0 : 0.5;
                    }
                }
            }
            unset($entry);
            $prediction['probabilities']['probability_invariants'] = array_fill_keys(array_keys($prediction['probabilities']['probability_invariants']), 1.0);
        } elseif ($fault === 'swap') {
            foreach ([1, 2, 3] as $position) {
                $key = 'position_'.$position.'_probability';
                [$prediction['probabilities']['entries'][0][$key], $prediction['probabilities']['entries'][1][$key]]
                    = [$prediction['probabilities']['entries'][1][$key], $prediction['probabilities']['entries'][0][$key]];
            }
        } elseif ($fault === 'primary' || $fault === 'tie') {
            [$prediction['decision']['primary_position_1_bike'], $prediction['decision']['primary_position_2_bike']]
                = [$prediction['decision']['primary_position_2_bike'], $prediction['decision']['primary_position_1_bike']];
        } elseif ($fault === 'pair' || $fault === 'tie-pair') {
            [$prediction['decision']['primary_position_2_bike'], $prediction['decision']['primary_position_3_bike']]
                = [$prediction['decision']['primary_position_3_bike'], $prediction['decision']['primary_position_2_bike']];
        } else {
            $slot = &$prediction;
            $last = array_pop($field);
            foreach ($field as $key) {
                $slot = &$slot[$key];
            }
            if ($fault === 'missing') {
                unset($slot[$last]);
            } else {
                $slot[$last] = $fault === 'nonfinite' ? '__PR94_NONFINITE__' : $value;
            }
            unset($slot);
        }
        if ($fault === 'zero' || str_starts_with($fault, 'sum-') || $fault === 'swap') {
            foreach ($prediction['probabilities']['entries'] as &$entry) {
                $entry['top2_probability'] = $entry['position_1_probability'] + $entry['position_2_probability'];
                $entry['top3_probability'] = $entry['top2_probability'] + $entry['position_3_probability'];
            }
            unset($entry);
        }
        $this->reseal($path, $prediction, $fault === 'nonfinite' ? $value : null);
        $before = $this->tree($path);
        foreach ([Package::class, Predictor::class, Forward::class] as $class) {
            $this->app->bind($class, static fn () => throw new RuntimeException('Unexpected inference dependency.'));
        }
        $this->reject(fn () => app(Service::class)->show($copy, 'one'), 'Saved prediction');
        $this->reject(fn () => app(Service::class)->create($copy, 'one', '2025', '100'), 'Saved prediction');
        $this->reject(fn () => app(Service::class)->reproduce($copy, 'one'), 'Saved prediction');
        foreach (['show', 'create'] as $mode) {
            $args = ['mode' => $mode, '--store-root' => $copy, '--request-id' => 'one', '--json' => true];
            if ($mode === 'create') {
                $args += ['--year' => '2025', '--race-id' => '100'];
            }
            $this->artisan('keirin:c1:composition-request', $args)->expectsOutputToContain('Saved prediction')->assertFailed();
        }
        $this->assertSame($before, $this->tree($path));
        $this->assertSame($original, $this->tree($created['path']));
    }

    public static function resealedSemanticFaults(): array
    {
        $cases = [];
        foreach (['zero', 'sum-1', 'sum-2', 'sum-3', 'swap', 'primary', 'pair', 'tie', 'tie-pair'] as $fault) {
            $cases[$fault] = [$fault];
        }
        foreach ([['probabilities'], ['decision'], ['probabilities', 'entries', 0, 'utilities'],
            ['probabilities', 'entries', 0, 'utilities', 'POSITION_2'], ['probabilities', 'entries', 0, 'position_1_probability'],
            ['decision', 'winner_p1']] as $i => $path) {
            $cases['missing-'.$i] = ['missing', $path];
        }
        foreach ([[], ['probabilities'], ['decision'], ['probabilities', 'entries', 0],
            ['probabilities', 'entries', 0, 'utilities'], ['decision', 'decoder_tie_diagnostics']] as $i => $path) {
            $cases['unknown-'.$i] = ['set', [...$path, 'unknown'], 1];
        }
        foreach (['0.25', false, null, []] as $i => $value) {
            $cases['utility-type-'.$i] = ['set', ['probabilities', 'entries', 0, 'utilities', 'POSITION_1'], $value];
            $cases['marginal-type-'.$i] = ['set', ['probabilities', 'entries', 0, 'position_1_probability'], $value];
        }
        foreach (['1e999', '-1e999'] as $i => $literal) {
            $cases['nonfinite-utility-'.$i] = ['nonfinite', ['probabilities', 'entries', 0, 'utilities', 'POSITION_1'], $literal];
        }
        foreach (['position_1_log_probability' => -777.0, 'position_2_log_probability' => -777.0,
            'position_3_log_probability' => -777.0, 'top2_probability' => 0.0, 'top3_probability' => 0.0,
            'predicted_position' => 99, 'is_map_top3' => 'false', 'map_ordered_top3' => [9, 8, 7],
            'map_ordered_probability' => 0.0, 'map_top3_set' => [7, 8, 9], 'map_top3_set_probability' => 0.0,
            'map_tie_diagnostics' => []] as $key => $value) {
            $cases['entry-'.$key] = ['set', ['probabilities', 'entries', 0, $key], $value];
        }
        foreach (['map_ordered_top3' => [9, 8, 7], 'map_ordered_probability' => 0.0,
            'map_top3_set' => [7, 8, 9], 'map_top3_set_probability' => 0.0, 'map_tie_diagnostics' => [],
            'probability_invariants' => []] as $key => $value) {
            $cases['probabilities-'.$key] = ['set', ['probabilities', $key], $value];
        }
        foreach (['winner_p1' => 0.0, 'selected_q2_given_winner' => 0.0, 'selected_q3_given_winner' => 0.0,
            'primary_second_third_objective_score' => 0.0, 'q2_given_winner' => [], 'q3_given_winner' => [],
            'q2_distribution_semantic_sha256' => str_repeat('0', 64),
            'q3_winner_conditioned_marginal_semantic_sha256' => str_repeat('0', 64),
            'top2_marginal_bikes' => [9, 8], 'top3_marginal_bikes' => [9, 8, 7], 'expected_ndcg_top3' => [9, 8, 7],
            'winner_tie_count' => 99, 'second_third_tie_count' => 99, 'primary_decision_tied' => 'false',
            'primary_technical_tiebreak_used' => 'false', 'reconstruction_verified' => false,
            'decoder_tie_diagnostics' => []] as $key => $value) {
            $cases['decision-'.$key] = ['set', ['decision', $key], $value];
        }

        return $cases;
    }

    private function reseal(string $path, array $prediction, ?string $numericLiteral = null): void
    {
        unlink($path.'/prediction.jsonl');
        unlink($path.'/prediction.jsonl.manifest.json');
        Jsonl::write($path.'/prediction.jsonl', [$prediction]);
        if ($numericLiteral !== null) {
            // JSON permits an exponent that decodes to INF; its seals must still be valid.
            $body = str_replace('"__PR94_NONFINITE__"', $numericLiteral, file_get_contents($path.'/prediction.jsonl'), $replacements);
            $this->assertSame(1, $replacements);
            file_put_contents($path.'/prediction.jsonl', $body);
            unlink($path.'/prediction.jsonl.manifest.json');
            Jsonl::json($path.'/prediction.jsonl.manifest.json', ['rows' => 1, ...Files::identity($path.'/prediction.jsonl')]);
        }
        $manifest = Files::json($path.'/manifest.json');
        foreach (['prediction.jsonl', 'prediction.jsonl.manifest.json'] as $name) {
            $manifest['files'][$name] = Files::identity($path.'/'.$name);
        }
        unlink($path.'/manifest.json');
        unlink($path.'/COMPLETE.json');
        Jsonl::json($path.'/manifest.json', $manifest);
        Jsonl::json($path.'/COMPLETE.json', Files::identity($path.'/manifest.json'));
        foreach ($manifest['files'] as $name => $seal) {
            Files::verify($path.'/'.$name, $seal);
        }
        Files::verify($path.'/manifest.json', Files::json($path.'/COMPLETE.json'));
    }

    #[DataProvider('validSavedUtilities')]
    public function test_saved_utility_verification_keeps_exact_ties_micro_differences_underflow_and_conditioned_p3(string $kind): void
    {
        $this->utilityPredictor($kind);
        $created = app(Service::class)->create($this->store, 'one', '2025', '100');
        $saved = (new Store)->read($this->store, 'one');
        if ($kind === 'tie') {
            $this->assertSame(5, $saved['prediction']['decision']['winner_tie_count']);
        } elseif ($kind === 'micro') {
            $this->assertSame(1, $saved['prediction']['decision']['winner_tie_count']);
        } elseif ($kind === 'extreme') {
            $this->assertContains(0.0, array_column($saved['prediction']['probabilities']['entries'], 'position_1_probability'));
        } else {
            $entries = $saved['prediction']['probabilities']['entries'];
            $maximum = max(array_column($entries, 'position_3_probability'));
            $argmax = array_values(array_filter($entries, static fn (array $e): bool => $e['position_3_probability'] === $maximum))[0]['bike'];
            $this->assertNotSame($argmax, $saved['prediction']['decision']['primary_position_3_bike']);
        }
        foreach ([Package::class, Predictor::class, Forward::class] as $class) {
            $this->app->bind($class, static fn () => throw new RuntimeException('Unexpected inference dependency.'));
        }
        unlink($this->sources->input);
        $before = $this->tree($created['path']);
        $this->assertSame('SAVED', app(Service::class)->show($this->store, 'one')['status']);
        $this->assertSame('REUSED', app(Service::class)->create($this->store, 'one', '2025', '100')['status']);
        $this->assertSame($before, $this->tree($created['path']));
    }

    public static function validSavedUtilities(): array
    {
        return [['tie'], ['micro'], ['extreme'], ['conditioned']];
    }

    public function test_nonfinite_saved_utilities_are_rejected_without_casting(): void
    {
        app(Service::class)->create($this->store, 'one', '2025', '100');
        $prediction = (new Store)->read($this->store, 'one')['prediction'];
        foreach ([INF, -INF, NAN] as $value) {
            $prediction['probabilities']['entries'][0]['utilities']['POSITION_1'] = $value;
            $this->reject(fn () => app(PredictionVerifier::class)->verify($prediction), 'Non-finite');
        }
    }

    public function test_invalid_predictor_output_is_rejected_before_publication_and_preserves_failed_stage(): void
    {
        $this->app->instance(Predictor::class, new class(app(Forward::class)) extends Predictor
        {
            public function predict(array $race, array $model): array
            {
                $prediction = parent::predict($race, $model);
                $prediction['decision']['winner_p1'] = 0.0;

                return $prediction;
            }
        });
        $this->reject(fn () => app(Service::class)->create($this->store, 'one', '2025', '100'), 'Saved prediction');
        $this->assertDirectoryDoesNotExist($this->store.'/requests/one');
        $files = glob($this->store.'/requests/one.inprogress-*/FAILED.json');
        $this->assertCount(1, $files);
        $this->assertStringContainsString('Saved prediction', file_get_contents($files[0]));
    }

    private function utilityPredictor(string $kind): void
    {
        $this->app->instance(Predictor::class, new class(app(Forward::class), $kind) extends Predictor
        {
            public function __construct(Forward $forward, private string $kind)
            {
                parent::__construct($forward);
            }

            public function predict(array $race, array $model): array
            {
                $projected = ['year' => $race['year'], 'race_id' => $race['race_id'], 'entries' => []];
                foreach ($race['entries'] as $i => $entry) {
                    $values = match ($this->kind) {
                        'tie' => [0.0, 0.0, 0.0],
                        'micro' => [$i * 1e-14, -$i * 1e-14, $i * 1e-14],
                        'extreme' => [[1000.0, -1000.0, 800.0, -800.0, 0.0][$i], -$i * 200.0, $i * 200.0],
                        'conditioned' => [[0.7, 0.3, 0.0, -0.5, -1.0][$i], [1.5, 1.2, 0.7, -0.2, -0.7][$i], [1.0, 0.75, 0.5, 0.25, 0.0][$i]],
                    };
                    $projected['entries'][] = ['id' => $entry['id'], 'bike' => $entry['bike'], 'raw' => $entry['raw'],
                        'stat01_rank' => $entry['stat01_rank'], 'anchor' => $entry['anchor'],
                        'utilities' => array_combine(['POSITION_1', 'POSITION_2', 'POSITION_3'], $values)];
                }
                $probabilities = app(ProbabilityCalculator::class)->predict($projected);

                return ['probabilities' => $probabilities, 'decision' => app(Bt03e06WinnerConditionedDecoder::class)->decode($probabilities)];
            }
        });
    }

    public function test_resealed_wrong_entry_mapping_is_not_accepted(): void
    {
        $created = app(Service::class)->create($this->store, 'one', '2025', '100');
        $path = $created['path'];
        $prediction = iterator_to_array(Jsonl::read($path.'/prediction.jsonl'))[0];
        $prediction['probabilities']['entries'][0]['id']++;
        unlink($path.'/prediction.jsonl');
        unlink($path.'/prediction.jsonl.manifest.json');
        Jsonl::write($path.'/prediction.jsonl', [$prediction]);
        $manifest = Files::json($path.'/manifest.json');
        foreach (['prediction.jsonl', 'prediction.jsonl.manifest.json'] as $name) {
            $manifest['files'][$name] = Files::identity($path.'/'.$name);
        }
        unlink($path.'/manifest.json');
        unlink($path.'/COMPLETE.json');
        Jsonl::json($path.'/manifest.json', $manifest);
        Jsonl::json($path.'/COMPLETE.json', Files::identity($path.'/manifest.json'));
        $this->reject(fn () => app(Service::class)->show($this->store, 'one'), 'entrants');
    }

    #[DataProvider('publicationFaults')]
    public function test_expected_write_seals_end_drift_and_postcommit_exceptions_are_not_blessed(string $fault): void
    {
        $publication = new class($fault) extends Publication
        {
            public function __construct(private string $fault) {}

            public function json(string $path, array $value): void
            {
                parent::json($path, $value);
                if (basename($path) === 'runtime.json') {
                    if ($this->fault === 'write') {
                        throw new RuntimeException('Injected write failure.');
                    }
                    if ($this->fault === 'bytes') {
                        file_put_contents($path, ' ', FILE_APPEND);
                    }
                }
            }

            public function commit(string $stage, string $destination): void
            {
                if ($this->fault === 'commit') {
                    throw new RuntimeException('Injected commit failure.');
                }
                parent::commit($stage, $destination);
                if ($this->fault === 'postcommit') {
                    throw new RuntimeException('Injected after commit.');
                }
            }
        };
        $this->app->instance(Publication::class, $publication);
        if ($fault === 'end') {
            $this->app->instance(Sources::class, new class($this->sources->artifact, $this->sources->input, $this->sources->artifactSeal, $this->sources->receiptSeal, $this->sources->inputSeal) extends Sources
            {
                public function end(array $seals): void
                {
                    file_put_contents($this->input, ' ', FILE_APPEND);
                    parent::end($seals);
                }
            });
        }
        if ($fault === 'postcommit') {
            $result = app(Service::class)->create($this->store, 'one', '2025', '100');
            $this->assertSame('CREATED', $result['status']);
            $this->assertStringContainsString('after commit', $result['postcommit_warning']);
            $this->assertFileDoesNotExist($result['path'].'/FAILED.json');
        } else {
            $this->reject(fn () => app(Service::class)->create($this->store, 'one', '2025', '100'));
            $this->assertDirectoryDoesNotExist($this->store.'/requests/one');
            $this->assertCount(1, glob($this->store.'/requests/one.inprogress-*/FAILED.json'));
        }
    }

    public static function publicationFaults(): array
    {
        return [['write'], ['bytes'], ['end'], ['commit'], ['postcommit']];
    }

    public function test_reproduction_with_wrong_output_or_package_identity_fails_and_preserves_request(): void
    {
        $created = app(Service::class)->create($this->store, 'one', '2025', '100');
        $before = $this->tree($created['path']);
        $this->app->instance(Predictor::class, new class(app(Forward::class)) extends Predictor
        {
            public function predict(array $race, array $model): array
            {
                $result = parent::predict($race, $model);
                $result['decision']['winner_p1'] += 0.001;

                return $result;
            }
        });
        $this->reject(fn () => app(Service::class)->reproduce($this->store, 'one'), 'reproduction');
        $this->reject(fn () => app(Service::class)->reproduce($this->store, 'one', $created['path'].'/request.json'));
        $this->assertSame($before, $this->tree($created['path']));
    }

    public function test_saved_reads_and_reproduction_run_with_annual_and_old_source_inaccessible(): void
    {
        $created = app(Service::class)->create($this->store, 'one', '2025', '100');
        $before = $this->tree($created['path']);
        $script = <<<'PHP'
require 'vendor/autoload.php'; $app=require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
App\Console\Commands\Keirin\FinalC1Stat35CompositionCommand::denyExternalAccess();
Tests\Support\C1Stat35RepackageFixture::bind(unserialize(base64_decode($argv[1])));
$app->instance(App\Domain\Keirin\Backtest\Experiments\C1CompositionRequest\Sources::class,unserialize(base64_decode($argv[2])));
$service=app(App\Domain\Keirin\Backtest\Experiments\C1CompositionRequest\Service::class);
$result= $argv[4]==='reproduce' ? $service->reproduce($argv[3],'one') :
[$service->show($argv[3],'one')['status'],$service->create($argv[3],'one','2025','100')['status']];
echo json_encode($result,JSON_THROW_ON_ERROR);
PHP;
        foreach (['saved', 'reproduce'] as $mode) {
            $allow = base_path().PATH_SEPARATOR.$this->store;
            if ($mode === 'reproduce') {
                $allow .= PATH_SEPARATOR.dirname($this->sources->artifact);
            }
            $p = new Process([PHP_BINARY, '-d', 'memory_limit=128M', '-d', 'open_basedir='.$allow, '-r', $script,
                base64_encode(serialize(self::$bundle['pin'])), base64_encode(serialize($this->sources)), $this->store, $mode],
                base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'CACHE_STORE' => 'array']);
            $p->run();
            $this->assertSame(0, $p->getExitCode(), $p->getErrorOutput());
            $result = json_decode($p->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($mode === 'saved' ? ['SAVED', 'REUSED'] : 'REPRODUCED', $mode === 'saved' ? $result : $result['status']);
        }
        $this->assertSame($before, $this->tree($created['path']));
    }

    public function test_declared_model_or_input_change_is_conflict_without_source_access(): void
    {
        $created = app(Service::class)->create($this->store, 'one', '2025', '100');
        $before = $this->tree($created['path']);
        foreach (['artifact', 'input'] as $kind) {
            $bad = ['bytes' => 1, 'sha256' => str_repeat('0', 64)];
            $this->app->instance(Sources::class, new Sources('/missing/artifact.json', '/missing/input.jsonl',
                $kind === 'artifact' ? $bad : $this->sources->artifactSeal, $this->sources->receiptSeal,
                $kind === 'input' ? $bad : $this->sources->inputSeal));
            $this->reject(fn () => app(Service::class)->create($this->store, 'one', '2025', '100'), 'CONFLICT');
        }
        $this->assertSame($before, $this->tree($created['path']));
    }

    public function test_absent_target_and_annual_body_drift_never_publish(): void
    {
        $this->reject(fn () => app(Service::class)->create($this->store, 'missing', '2025', '99'), 'NOT_FOUND');
        file_put_contents($this->sources->input, ' ', FILE_APPEND);
        $this->reject(fn () => app(Service::class)->create($this->store, 'drift', '2025', '100'));
        $this->assertDirectoryDoesNotExist($this->store.'/requests/missing');
        $this->assertDirectoryDoesNotExist($this->store.'/requests/drift');
    }

    public function test_plan_is_source_free_and_2024_is_allowed_only_for_internal_synthetic_sources(): void
    {
        $this->app->bind(PredictionVerifier::class, static fn () => throw new RuntimeException('Plan must not resolve verifier.'));
        $this->artisan('keirin:c1:composition-request', ['mode' => 'plan'])->assertSuccessful();
        $this->app->bind(PredictionVerifier::class);
        $path = $this->root.'/sources/year-2024.jsonl';
        Input::write($path, [Models::feature(5, 2024)]);
        $this->app->instance(Sources::class, $this->source($path));
        $this->assertSame('CREATED', app(Service::class)->create($this->store, 'synthetic', '2024', '5')['status']);
        $this->reject(fn () => (new Sources)->year(2024), 'Forbidden');
    }

    public function test_source_store_overlap_symlinks_and_repository_are_refused_before_directory_creation(): void
    {
        foreach ([base_path('request-store-01-no'), dirname($this->sources->artifact),
            dirname($this->sources->artifact).'/request-store-01-no', $this->root.'/../request-store-01-no',
            $this->root.'/request-store-01-no/child'] as $path) {
            $this->reject(fn () => app(Service::class)->create($path, 'one', '2025', '100'));
        }
        symlink($this->root, $this->root.'/request-store-01-link');
        $this->reject(fn () => app(Service::class)->create($this->root.'/request-store-01-link', 'one', '2025', '100'), 'Symlink');
        $this->assertDirectoryDoesNotExist(base_path('request-store-01-no'));
    }

    public function test_independent_128m_process_streams_over_100mib_input_and_runs_actual_create(): void
    {
        $input = $this->root.'/sources/large.jsonl';
        $seal = Input::write($input, (function (): \Generator {
            for ($i = 1; $i <= 100000; $i++) {
                yield Models::feature($i);
            }
        })());
        $this->assertGreaterThan(100 * 1024 * 1024, $seal['bytes']);
        $source = $this->source($input);
        $child = $this->child($source, 'normal');
        $child->setTimeout(180);
        $child->run();
        $this->assertSame(0, $child->getExitCode(), $child->getErrorOutput());
        $result = json_decode($child->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('128M', $result['memory_limit']);
        $this->assertSame('CREATED', $result['status']);
        $this->assertLessThanOrEqual(128 * 1024 * 1024, $result['peak']);
    }

    public function test_same_id_process_conflict_has_one_atomic_winner_and_retry_reuses_unchanged_bytes(): void
    {
        (new Store)->prepare($this->store);
        $first = $this->child($this->sources, 'pause');
        $first->start();
        try {
            $ready = $this->store.'/ready';
            $deadline = microtime(true) + 20;
            while (! is_file($ready) && $first->isRunning() && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertFileExists($ready, $first->getErrorOutput());
            $this->assertSame('NOT_FOUND', app(Service::class)->show($this->store, 'one')['status']);
            $second = $this->child($this->sources, 'normal');
            $second->run();
            $this->assertSame(1, $second->getExitCode());
            $this->assertStringContainsString('locked', $second->getErrorOutput());
            file_put_contents($this->store.'/release', 'yes');
            $first->wait();
            $this->assertSame(0, $first->getExitCode(), $first->getErrorOutput());
            $before = $this->tree($this->store.'/requests/one');
            $retry = $this->child($this->sources, 'normal');
            $retry->run();
            $this->assertSame(0, $retry->getExitCode(), $retry->getErrorOutput());
            $this->assertSame('REUSED', json_decode($retry->getOutput(), true)['status']);
            $this->assertSame($before, $this->tree($this->store.'/requests/one'));
        } finally {
            if ($first->isRunning()) {
                $first->stop(0);
            }
        }
    }

    private function source(string $input): Sources
    {
        $artifact = self::$bundle['artifact'];

        return new Sources($artifact, $input, Files::identity($artifact), Files::identity(dirname($artifact).'/RELEASE_COMMITTED.json'), Files::identity($input), [2024, 2025]);
    }

    private function child(Sources $source, string $mode): Process
    {
        $script = <<<'PHP'
require 'vendor/autoload.php'; $app=require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
App\Console\Commands\Keirin\FinalC1Stat35CompositionCommand::denyExternalAccess();
Tests\Support\C1Stat35RepackageFixture::bind(unserialize(base64_decode($argv[1])));
$app->instance(App\Domain\Keirin\Backtest\Experiments\C1CompositionRequest\Sources::class,unserialize(base64_decode($argv[2])));
if ($argv[4]==='pause') {
    $app->instance(App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Publication::class,new class extends App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Publication {
        public function commit(string $stage,string $destination):void {
            $root=dirname(dirname($destination)); file_put_contents($root.'/ready','ready');
            while(!is_file($root.'/release')) {usleep(10000);} parent::commit($stage,$destination);
        }
    });
}
try {$r=app(App\Domain\Keirin\Backtest\Experiments\C1CompositionRequest\Service::class)->create($argv[3],'one','2025','100');
echo json_encode(['status'=>$r['status'],'memory_limit'=>ini_get('memory_limit'),'peak'=>memory_get_peak_usage(true)],JSON_THROW_ON_ERROR);}
catch(Throwable $e){fwrite(STDERR,$e->getMessage());exit(1);}
PHP;

        return new Process([PHP_BINARY, '-d', 'memory_limit=128M', '-r', $script, base64_encode(serialize(self::$bundle['pin'])),
            base64_encode(serialize($source)), $this->store, $mode], base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'CACHE_STORE' => 'array']);
    }

    private function reject(callable $operation, ?string $message = null): void
    {
        try {
            $operation();
        } catch (Throwable $e) {
            $this->assertNotInstanceOf(AssertionFailedError::class, $e);
            if ($message !== null) {
                $this->assertStringContainsString($message, $e->getMessage());
            }

            return;
        }
        $this->fail('Invalid operation accepted.');
    }

    private function tree(string $path): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                $files[substr($file->getPathname(), strlen($path) + 1)] = Files::identity($file->getPathname());
            }
        }

        return $files;
    }

    private static function remove(string $root): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() && ! $file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($root);
    }
}
