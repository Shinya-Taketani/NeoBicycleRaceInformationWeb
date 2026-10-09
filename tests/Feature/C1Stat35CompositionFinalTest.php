<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Keirin\Backtest\Calculators\Bt03e03OneSeSelector;
use App\Domain\Keirin\Backtest\DTO\Bt03e03FitResultDto;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Contract;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Experiment;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Forward;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Input;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Package;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\PathTrainer;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Prediction;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Publication;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Reuse;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Standalone;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\TrainingData;
use App\Domain\Keirin\Backtest\Experiments\Stat35C1Comparison\LoadedModel as C2Model;
use App\Domain\Keirin\Backtest\Experiments\Stat35C1Comparison\ModelLoader as C2Loader;
use App\Domain\Keirin\Backtest\Experiments\Stat35C1Comparison\Optimizer;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact as Jsonl;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\LoadedModel as C1Model;
use App\Domain\Keirin\Backtest\Services\Bt03e03Contract as Numeric;
use App\Domain\Keirin\Backtest\Support\Bt03e03ValidationLossSpool as Spool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\C1Stat35CompositionFinalFixture as Fixture;
use Tests\TestCase;
use Throwable;

class C1Stat35CompositionFinalTest extends TestCase
{
    private static ?array $source = null;

    private static string $shared;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        DB::swap(new class
        {
            public function __call(string $name, array $args): never
            {
                throw new RuntimeException('Synthetic composition must not access application DB.');
            }
        });
        Http::fake(static fn () => throw new RuntimeException('Synthetic composition must not access HTTP.'));
        $this->directory = Files::directory(sys_get_temp_dir().'/composition-final-test-'.bin2hex(random_bytes(8)));
        if (self::$source === null) {
            self::$shared = sys_get_temp_dir().'/composition-final-fixture-'.bin2hex(random_bytes(8));
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

    public function test_real_services_reuse_two_folds_fit_twice_and_publish_portable_feature_only_package(): void
    {
        $result = $this->execute($this->directory.'/result');
        $this->assertSame('FINAL_COMPOSITION_FIT_REPRODUCED_AWAITING_REVIEW', $result['status']);
        $this->assertSame(4, $result['new_fit_paths']);
        $this->assertSame(0, $result['c1_retraining_count']);
        $this->assertSame([2023, 2024], $result['reused_folds']);
        $this->assertSame('NOT_PERFORMED_FINAL_FIT_AND_TECHNICAL_REPRODUCTION_ONLY', $result['contract']['performance_evaluation']);
        foreach ($result['cohorts'] as $counts) {
            $this->assertSame(['races' => 5, 'entries' => 25], $counts);
        }
        $root = $this->directory.'/result';
        $this->assertSame([2024 => 5, 2025 => 5], Files::json($root.'/outer-verification.json')['counts']);
        $this->assertTrue(Files::json($root.'/reproduction.json')['identical']);
        $this->assertGreaterThan(20, $result['semantic_file_count']);
        foreach (['run-01', 'run-02'] as $run) {
            $dir = $root.'/'.$run;
            $this->assertSame([2022, 2023, 2024], array_values(array_unique(array_column(iterator_to_array(Jsonl::read($dir.'/oof-3/training.jsonl')), 'year'))));
            $this->assertSame([2022, 2023, 2024, 2025], array_values(array_unique(array_column(iterator_to_array(Jsonl::read($dir.'/final/training.jsonl')), 'year'))));
            $this->assertCount(8, Files::json($dir.'/oof-3/path.json')['candidate_statuses']);
            $this->assertSame('OOF3_CANDIDATES_SEALED_2025_VALIDATION_RELEASE', Files::json($dir.'/teacher-access.json')[0]['event']);
            $this->assertSame(Files::identity(dirname(self::$source['c1_artifact']).'/model.json'), Files::identity($dir.'/package/c1/model.json'));
            $this->assertSame(0, Files::json($root.'/'.$run.'-standalone-audit.json')['exit_code']);
        }
        foreach (self::$source['seals'] as $path => $seal) {
            Files::verify($path, $seal);
        }
        $input = $root.'/verified-inputs/features-2025.jsonl';
        $artifact = $result['package'];
        $before = Files::json($root.'/run-01/composition-loaded.jsonl.manifest.json');
        $prediction = app(Prediction::class)->run($artifact, $input, $this->directory.'/public.jsonl');
        $this->assertSame($before, $prediction['predictions']);
        $this->artisan('keirin:c1:stat35-composition-predict', ['--artifact' => $artifact, '--input' => $input, '--output-dir' => $this->directory.'/cli'])->assertSuccessful();
        $this->assertSame($before, Files::json($this->directory.'/cli/predictions.jsonl.manifest.json'));
        $this->assertFalse($result['contract']['use_restrictions']['formal_adoption']);
    }

    public function test_short_path_matches_full_path_and_keeps_stronger_intermediate_warm_steps(): void
    {
        $parent = self::$source['reused_folds'][2023];
        $full = Files::json($parent.'/path.json');
        $key = Spool::lambdaKey(0.1);
        $loaded = app(C2Loader::class)->restore($full['models'][1]);
        $short = app(PathTrainer::class)->fit(fn () => Jsonl::read($parent.'/training.jsonl'), $loaded->layout, [$key]);
        $this->assertSame([1.0, 0.1], $short['fit_order']);
        foreach ([Spool::lambdaKey(1.0), $key] as $k) {
            $this->assertSame($full['candidate_statuses'][$k], $short['candidate_statuses'][$k]);
            $this->assertSame($full['models'][$k]['position_coefficients'], $short['fits'][$k]->coefficients);
        }
        foreach (Numeric::LAMBDA_GRID as $lambda) {
            if ($lambda < 0.1) {
                $this->assertSame(Contract::OMITTED, $short['candidate_statuses'][Spool::lambdaKey($lambda)]['status']);
            }
        }
        $this->assertSame([1.0, 0.1, 0.01], PathTrainer::prefix([Spool::lambdaKey(0.01)]));
        $this->expectException(RuntimeException::class);
        app(PathTrainer::class)->fit(static fn () => throw new RuntimeException('corrupt input is not non-convergence'), $loaded->layout, [$key]);
    }

    public function test_saved_losses_are_reconstructed_bit_exact_without_fitting_and_wrong_training_is_rejected(): void
    {
        $parent = self::$source['reused_folds'][2023];
        $training = TrainingData::training([self::$source['paths'][2022]['c2_teacher']]);
        $validation = TrainingData::training([self::$source['paths'][2023]['c2_teacher']]);
        $restored = app(Reuse::class)->restore($parent, $training, $validation, Files::directory($this->directory.'/reused'));
        $this->assertSame(iterator_to_array(Jsonl::read($parent.'/validation-losses.jsonl')),
            iterator_to_array(Jsonl::read($this->directory.'/reused/validation-losses.jsonl')));
        $this->assertSame(5, $restored->raceCount());
        $restored->cleanup();
        $this->expectException(RuntimeException::class);
        app(Reuse::class)->restore($parent, $validation, $validation, Files::directory($this->directory.'/wrong-fold'));
    }

    public function test_parent_manifest_sealed_feature_files_do_not_require_invented_per_file_sidecars(): void
    {
        $source = self::$source;
        foreach (['input', 'sidecar'] as $kind) {
            $old = $source['paths'][2024][$kind];
            $path = $this->directory.'/'.$kind.'.jsonl';
            copy($old, $path);
            $source['paths'][2024][$kind] = $path;
            $source['seals'][$path] = Files::identity($path);
            $this->assertFileDoesNotExist($path.'.manifest.json');
        }
        $rows = iterator_to_array((new TrainingData)->features($source, 2024));
        $this->assertCount(5, $rows);
        $this->assertNull($rows[0]['entries'][4]['stat35_mean6']);
        $this->assertSame(0.0, $rows[0]['entries'][0]['stat35_mean6']);
    }

    #[DataProvider('badReuseCases')]
    public function test_reused_fold_rejects_version_candidate_order_and_loss_drift(string $case): void
    {
        $parent = self::$source['reused_folds'][2023];
        $copy = Files::directory($this->directory.'/C2-inner-A');
        foreach (glob($parent.'/*') as $file) {
            copy($file, $copy.'/'.basename($file));
        }
        $path = Files::json($copy.'/path.json');
        if ($case === 'version') {
            $path['models'][1]['model_version'] = 'wrong';
        } elseif ($case === 'order') {
            $path['fit_order'] = array_reverse($path['fit_order']);
        } elseif ($case === 'status') {
            $path['candidate_statuses'][1]['status'] = 'NUMERICALLY_NON_CONVERGED';
        } else {
            $losses = iterator_to_array(Jsonl::read($copy.'/validation-losses.jsonl'));
            $losses[0]['losses'][1]['POSITION_1'] += 0.5;
            unlink($copy.'/validation-losses.jsonl');
            unlink($copy.'/validation-losses.jsonl.manifest.json');
            Jsonl::write($copy.'/validation-losses.jsonl', $losses);
        }
        unlink($copy.'/path.json');
        Jsonl::json($copy.'/path.json', $path);
        $this->expectException(RuntimeException::class);
        app(Reuse::class)->restore($copy, TrainingData::training([self::$source['paths'][2022]['c2_teacher']]),
            TrainingData::training([self::$source['paths'][2023]['c2_teacher']]), Files::directory($this->directory.'/restore'));
    }

    public static function badReuseCases(): array
    {
        return [['version'], ['order'], ['status'], ['loss']];
    }

    public function test_three_fold_selection_uses_only_common_candidates_and_empty_set_is_fail_closed(): void
    {
        $one = Spool::lambdaKey(1.0);
        $tenth = Spool::lambdaKey(0.1);
        $spools = [];
        foreach ([2023, 2024, 2025] as $year) {
            $keys = $year === 2025 ? [$one] : [$tenth, $one];
            $spool = new Spool($this->directory.'/'.$year.'.bin', $keys);
            $spool->append(array_fill_keys($keys, array_fill_keys(Numeric::POSITIONS, 1.0)));
            $spool->seal();
            $spools[$year] = $spool;
        }
        $selection = app(Bt03e03OneSeSelector::class)->select($spools);
        $this->assertSame(1.0, $selection['lambda']);
        foreach ($spools as $spool) {
            $spool->cleanup();
        }
        $this->expectException(RuntimeException::class);
        PathTrainer::prefix([]);
    }

    public function test_selected_nonconverged_refit_stops_without_fallback_or_relaxing_constants(): void
    {
        $parent = self::$source['reused_folds'][2023];
        $loaded = app(C2Loader::class)->restore(Files::json($parent.'/path.json')['models'][1]);
        $rows = Jsonl::read($parent.'/training.jsonl');
        $rows->rewind();
        $race = $rows->current();
        foreach ($race['entries'] as $i => &$entry) {
            $entry['rank'] = $i + 1;
            $entry['status'] = 'FINISHED';
        }
        unset($entry);
        $this->assertSame(200, Numeric::MAX_ITERATIONS);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('fallback was forbidden');
        app(Optimizer::class)->fitSelectedViaPath(static fn () => [$race], $loaded->layout, 0.0);
    }

    public function test_teacher_2025_cannot_open_before_candidate_seals_and_does_not_change_oof_training(): void
    {
        $data = new TrainingData;
        try {
            iterator_to_array($data->labelled(self::$source, 2025));
            $this->fail('2025 teacher opened early.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('before OOF3', $e->getMessage());
        }
        $source = self::$source;
        foreach (['teacher', 'c2_teacher'] as $kind) {
            $rows = iterator_to_array(Jsonl::read($source['paths'][2025][$kind]));
            foreach ($rows as &$row) {
                foreach ($row['entries'] as &$entry) {
                    $entry['rank'] = $entry['bike'];
                }
                unset($entry);
            }
            unset($row);
            $path = $this->directory.'/changed-2025-'.$kind.'.jsonl';
            Jsonl::write($path, $rows);
            $source['paths'][2025][$kind] = $path;
            $source['seals'][$path] = Files::identity($path);
        }
        $training = static function () use ($source): \Generator {
            foreach ([2022, 2023, 2024] as $year) {
                yield from (new TrainingData)->labelled($source, $year);
            }
        };
        ob_start();
        try {
            $a = app(PathTrainer::class)->train($training, [Spool::lambdaKey(1.0)], Files::directory($this->directory.'/a'));
            $b = app(PathTrainer::class)->train($training, [Spool::lambdaKey(1.0)], Files::directory($this->directory.'/b'));
        } finally {
            ob_end_clean();
        }
        $this->assertSame($a['fits'][1]->coefficients, $b['fits'][1]->coefficients);
        $this->assertSame(Files::identity($this->directory.'/a/layout.json'), Files::identity($this->directory.'/b/layout.json'));
        foreach (['a', 'b'] as $name) {
            $seals = [];
            foreach (['path.json', 'layout.json', 'training.jsonl'] as $file) {
                $seals[$file] = Files::identity($this->directory.'/'.$name.'/'.$file);
            }
            Jsonl::json($this->directory.'/'.$name.'/candidate-seals.json', $seals);
        }
        $originalData = new TrainingData;
        $changedData = new TrainingData;
        $originalData->release2025($this->directory.'/a');
        $changedData->release2025($this->directory.'/b');
        $this->assertNotSame(iterator_to_array($originalData->labelled(self::$source, 2025)), iterator_to_array($changedData->labelled($source, 2025)));
    }

    #[DataProvider('driftCases')]
    public function test_end_drift_retains_evidence_but_never_publishes_package(string $case): void
    {
        $target = $this->directory.'/failed';
        $sourcePath = self::$source['paths'][2024]['input'];
        $old = file_get_contents($sourcePath);
        try {
            $this->execute($target, function ($stage) use ($case, $sourcePath): void {
                $path = $case === 'source' ? $sourcePath : $stage.'/verified-inputs/features-2025.jsonl';
                $h = fopen($path, 'r+b');
                fwrite($h, '[');
                fclose($h);
            });
            $this->fail('End drift accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('hash/size mismatch', $e->getMessage());
            $this->assertDirectoryDoesNotExist($target);
            $stages = glob($target.'.inprogress-*');
            $this->assertCount(1, $stages);
            $this->assertSame('FAILED_NOT_PUBLISHED', Files::json($stages[0].'/FAILED.json')['status']);
            $this->assertFileExists($stages[0].'/run-01/final/model.json');
            $this->assertFileDoesNotExist($stages[0].'/run-01/package/COMPLETE.json');
        } finally {
            file_put_contents($sourcePath, $old);
        }
    }

    public static function driftCases(): array
    {
        return [['source'], ['generated']];
    }

    #[DataProvider('badInputs')]
    public function test_bad_feature_inputs_never_publish(string $case): void
    {
        $artifact = $this->package();
        $races = [Fixture::feature(90), Fixture::feature(12)];
        switch ($case) {
            case '2026': $races[1]['year'] = 2026;
                break;
            case '2022': $races[1]['year'] = 2022;
                break;
            case 'duplicate-race': $races[] = $races[0];
                break;
            case 'duplicate-entry': $races[1]['entries'][0]['id'] = $races[0]['entries'][0]['id'];
                break;
            case 'signal-count': $races[1]['entries'][0]['signals'][] = 0;
                break;
            case 'unknown-history': $races[1]['entries'][0]['history_status'] = 'UNKNOWN';
                break;
            case 'history-null': $races[1]['entries'][0]['history'][0] = null;
                break;
            case 'mean-string': $races[1]['entries'][0]['stat35_mean6'] = '1.0';
                break;
            case 'anchor': $races[1]['entries'][0]['anchor'] = 1.0;
                break;
            default: $races[1]['entries'][0][$case] = 1;
                break;
        }
        $input = $this->directory.'/bad.jsonl';
        Input::write($input, $races);
        try {
            app(Prediction::class)->run($artifact, $input, $this->directory.'/bad-output.jsonl');
            $this->fail('Bad public input accepted.');
        } catch (Throwable) {
            $this->assertDirectoryDoesNotExist($this->directory.'/bad-output.jsonl');
            $this->assertFileDoesNotExist($this->directory.'/bad-output.jsonl/COMPLETE.json');
        }
    }

    public static function badInputs(): array
    {
        return array_map(static fn ($s) => [$s], ['2026', '2022', 'duplicate-race', 'duplicate-entry', 'signal-count', 'unknown-history',
            'history-null', 'mean-string', 'anchor', 'rank', 'status', 'labels', 'actual', 'result', 'payout', 'utilities', 'bins', 'coefficients']);
    }

    #[DataProvider('badPackages')]
    public function test_package_rejects_wrong_role_version_path_hash_and_symlink(string $case): void
    {
        $artifact = $this->package();
        $data = Files::json($artifact);
        switch ($case) {
            case 'version': $data['contract']['version'] = 'wrong';
                break;
            case 'role': $data['parents']['C1']['model'] = 'c2/model.json';
                break;
            case 'traversal': $data['parents']['C2']['layout'] = '../../layout.json';
                break;
            case 'missing': unlink(dirname($artifact).'/c2/model.json');
                break;
            case 'hash': file_put_contents(dirname($artifact).'/c2/model.json', '{}');
                break;
            case 'symlink': rename(dirname($artifact).'/c2/layout.json', $this->directory.'/layout.json');
                symlink($this->directory.'/layout.json', dirname($artifact).'/c2/layout.json');
                break;
            case 'selection': $data['provenance']['training_years'] = [2022, 2023];
                break;
        }
        unlink($artifact);
        unlink(dirname($artifact).'/COMPLETE.json');
        Jsonl::json($artifact, $data);
        Jsonl::json(dirname($artifact).'/COMPLETE.json', ['artifact' => Files::identity($artifact), 'status' => 'FINAL_COMPOSITION_FIT_REPRODUCED_AWAITING_REVIEW']);
        $this->expectException(RuntimeException::class);
        app(Package::class)->load($artifact);
    }

    public static function badPackages(): array
    {
        return array_map(static fn ($s) => [$s], ['version', 'role', 'traversal', 'missing', 'hash', 'symlink', 'selection']);
    }

    public function test_different_layouts_and_unused_position_coefficients_do_not_leak_into_composition(): void
    {
        $models = app(Package::class)->load($this->package());
        $c1 = $models['c1'];
        $c2 = $models['c2'];
        $this->assertSame(16, $c1->layout->featureCount());
        $this->assertSame(17, $c2->layout->featureCount());
        $this->assertNotSame($c1->layout->size(), $c2->layout->size());
        $this->assertSame(0.1, $c1->fit->lambda);
        $this->assertSame(1.0, $c2->fit->lambda);
        $changed = static function ($model, array $positions): Bt03e03FitResultDto {
            $fit = $model->fit;
            $coefficients = $fit->coefficients;
            foreach ($positions as $position) {
                $coefficients[$position] = array_fill(0, count($coefficients[$position]), 12345.0);
            }

            return new Bt03e03FitResultDto($fit->lambda, $coefficients, $fit->objectives, $fit->iterations, $fit->eligibleRaceCounts, $fit->excludedRaceCounts, $fit->diagnostics);
        };
        foreach ([5, 7, 9] as $count) {
            $race = Fixture::feature(53, count: $count);
            if ($count === 5) {
                $race['entries'][4]['bike'] = 8;
            }
            $expected = app(Forward::class)->predict($race, $c1, $c2);
            $actual = app(Forward::class)->predict($race, new C1Model($c1->layout, $changed($c1, ['POSITION_1']), $c1->artifact),
                new C2Model($c2->layout, $changed($c2, ['POSITION_2', 'POSITION_3']), $c2->artifact));
            $this->assertSame($expected, $actual);
            foreach ($actual['probabilities']['probability_invariants'] as $sum) {
                $this->assertEqualsWithDelta(1.0, $sum, Numeric::PROBABILITY_TOLERANCE);
            }
        }
        $race = Fixture::feature(42);
        $race['entries'][0]['stat35_mean6'] = 0;
        $race['entries'][1]['stat35_mean6'] = null;
        Input::validate($race);
        $this->assertSame(0, $race['entries'][0]['stat35_mean6']);
        $this->assertNull($race['entries'][1]['stat35_mean6']);
    }

    public function test_feature_forward_ties_and_extreme_utilities_keep_valid_deterministic_math(): void
    {
        $models = app(Package::class)->load($this->package());
        $race = Fixture::feature(38, count: 9);
        foreach ($race['entries'] as &$entry) {
            $entry['signals'] = array_fill(0, 12, 0);
            $entry['history'] = array_fill(0, 4, 0);
            $entry['history_status'] = 'AVAILABLE';
            $entry['stat35_mean6'] = 0.0;
        }
        unset($entry);
        $a = app(Forward::class)->predict($race, $models['c1'], $models['c2']);
        $b = app(Forward::class)->predict($race, $models['c1'], $models['c2']);
        $this->assertSame($a, $b);
        $this->assertGreaterThan(1, $a['decision']['winner_tie_count']);
        $c2 = $models['c2'];
        $coefficients = $c2->fit->coefficients;
        $v = array_fill(0, $c2->layout->size(), 0.0);
        $v[0] = 1000.0;
        $v[1] = -1000.0;
        $coefficients['POSITION_1'] = $c2->layout->project($v);
        $fit = $c2->fit;
        $extreme = new Bt03e03FitResultDto($fit->lambda, $coefficients, $fit->objectives, $fit->iterations, $fit->eligibleRaceCounts, $fit->excludedRaceCounts, $fit->diagnostics);
        $actual = app(Forward::class)->predict(Fixture::feature(39, count: 7), $models['c1'], new C2Model($c2->layout, $extreme, $c2->artifact));
        foreach ($actual['probabilities']['probability_invariants'] as $sum) {
            $this->assertEqualsWithDelta(1.0, $sum, Numeric::PROBABILITY_TOLERANCE);
        }
    }

    public function test_moved_package_and_unsorted_outcome_free_input_predict_in_a_separate_128m_process(): void
    {
        $artifact = $this->package();
        $moved = $this->directory.'/moved';
        rename(dirname($artifact), $moved);
        $input = $this->directory.'/features.jsonl';
        Input::write($input, [Fixture::feature(100), Fixture::feature(2), Fixture::feature(67)]);
        $isolated = app(Standalone::class)->verify($moved.'/artifact.json', $input, $this->directory.'/isolated.jsonl', public: true);
        $this->assertSame(0, $isolated['exit_code']);
        $this->assertSame('128M', $isolated['memory_limit']);
        $this->assertSame(3, $isolated['seal']['rows']);
        $this->assertNotSame(getmypid(), $isolated['pid']);
        $this->assertSame([100, 2, 67], array_column(array_column(iterator_to_array(Jsonl::read($this->directory.'/isolated.jsonl')), 'probabilities'), 'race_id'));
    }

    public function test_more_than_100_mib_feature_input_is_predicted_under_an_actual_isolated_128m_limit(): void
    {
        $artifact = $this->package();
        $script = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
App\Console\Commands\Keirin\FinalC1Stat35CompositionCommand::denyExternalAccess();
$input = $argv[2].'/large.jsonl';
$seal = App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Input::write($input, (function () {
    for ($id = 100000; $id > 0; $id--) {
        yield Tests\Support\C1Stat35CompositionFinalFixture::feature($id);
    }
})());
$result = $app->make(App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Prediction::class)->run($argv[1], $input, $argv[2].'/large-predictions.jsonl');
echo json_encode(['input'=>$seal,'result'=>$result,'limit'=>ini_get('memory_limit')], JSON_THROW_ON_ERROR);
PHP;
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=128M', '-r', $script, $artifact, $this->directory], base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'CACHE_STORE' => 'array']);
        $process->setTimeout(600);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $data = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('128M', $data['limit']);
        $this->assertGreaterThan(100 * 1024 * 1024, $data['input']['bytes']);
        $this->assertSame(100000, $data['result']['predictions']['rows']);
        $this->assertLessThanOrEqual(128 * 1024 * 1024, $data['result']['peak_memory_bytes']);
    }

    private function package(): string
    {
        $c2 = dirname(self::$source['outer_c2'][2024]);
        $destination = $this->directory.'/package';
        $stage = $destination.'.inprogress-'.bin2hex(random_bytes(8));
        app(Package::class)->stage(self::$source['c1_artifact'], $c2, ['lambda' => 1.0], $stage, Fixture::provenance(self::$source['c1_artifact']));
        app(Publication::class)->seal($stage, $destination, 'SYNTHETIC_EXPORT', ['artifact.json']);
        app(Publication::class)->commit($stage, $destination);

        return $destination.'/artifact.json';
    }

    private function execute(string $target, ?callable $hook = null): array
    {
        ob_start();
        try {
            return app(Experiment::class)->execute($target, self::$source, $hook);
        } finally {
            ob_end_clean();
        }
    }

    private static function remove(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() && ! $file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($path);
    }
}
