<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Keirin\Backtest\Calculators\Bt03e03OneSeSelector;
use App\Domain\Keirin\Backtest\Calculators\EffectBinBuilder;
use App\Domain\Keirin\Backtest\DTO\EffectBinDto;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\HistoryAggregator;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Layout;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Trainer;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Contract;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\FinalFitService;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\ModelLoader;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\PredictionService;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\ReuseBundle;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\SourceCheck;
use App\Domain\Keirin\Backtest\Services\Bt03e03Contract;
use App\Domain\Keirin\Backtest\Support\Bt03e03ValidationLossSpool;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class TacticalHistoryFinalTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/tactical-final-test-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    public function test_real_services_reuse_two_folds_fit_third_and_final_twice_and_roundtrip_without_outcomes(): void
    {
        $parent = $this->parentBundle();
        $before = $this->inventory($parent);
        $source = $this->fakeSourceCheck();
        $result = app(FinalFitService::class)->run($parent, $this->directory.'/final');
        $this->assertSame(2, $source->calls);
        $this->assertSame('FINAL_FIT_REPRODUCED_AWAITING_REVIEW', $result['status']);
        $this->assertSame([2023, 2024], $result['reused_oof']);
        $this->assertCount(4, $result['new_fit_paths']);
        $this->assertSame($before, $this->inventory($parent));
        $this->assertSame('BYTE_EXACT', Files::json($this->directory.'/final/reproducibility.json')['status']);
        foreach (['run-01', 'run-02'] as $run) {
            $root = $this->directory.'/final/'.$run;
            $third = iterator_to_array(JsonlArtifact::read($root.'/oof-3/training.jsonl'));
            $this->assertSame([2022, 2023, 2024], array_values(array_unique(array_column($third, 'year'))));
            $this->assertCount(6, $third);
            $final = iterator_to_array(JsonlArtifact::read($root.'/final/training.jsonl'));
            $this->assertSame([2022, 2023, 2024, 2025], array_values(array_unique(array_column($final, 'year'))));
            $this->assertCount(8, $final);
            $model = Files::json($root.'/final/model.json');
            $this->assertSame(1.0, $model['lambda']);
            $this->assertSame(16, $model['layout']['feature_count']);
            foreach (Bt03e03Contract::POSITIONS as $position) {
                $this->assertSame('CONVERGED', $model['optimizer_diagnostics'][$position]['status']);
            }
            $this->assertSame(8, count(glob($root.'/oof-3/candidate-*.json')));
            $this->assertCount(8, Files::json($root.'/oof-3/path.json')['candidate_statuses']);
        }
        $artifact = $this->directory.'/final/run-01/final/artifact.json';
        $input = $parent.'/inputs-v2/inputs-2025.jsonl';
        $seal = app(PredictionService::class)->run($artifact, $input, $this->directory.'/predictions.jsonl');
        $this->assertSame(Files::json($this->directory.'/final/run-01/final/predictions.jsonl.manifest.json'), $seal);
        $this->artisan('keirin:backtest:tactical-history-predict', ['--artifact' => $artifact, '--input' => $input, '--output' => $this->directory.'/command.jsonl'])->assertSuccessful();
        $this->assertSame($seal, Files::json($this->directory.'/command.jsonl.manifest.json'));
        $this->assertSame(0, $result['2026_access']);
        $this->assertSame(0, $result['production_writes']);
        $this->assertSame('NOT_PERFORMED', $result['new_accuracy_evaluation']);
    }

    public function test_end_source_failure_withholds_artifact_publication_and_preserves_diagnostics(): void
    {
        $parent = $this->parentBundle();
        $this->fakeSourceCheck(true);
        try {
            app(FinalFitService::class)->run($parent, $this->directory.'/failed');
            $this->fail('End source drift accepted.');
        } catch (RuntimeException $e) {
            $this->assertSame('Synthetic end integrity drift', $e->getMessage());
        }
        $this->assertFileExists($this->directory.'/failed/run-01/final/model.json');
        $this->assertFileDoesNotExist($this->directory.'/failed/run-01/final/artifact.json');
        $this->assertFileDoesNotExist($this->directory.'/failed/result.json');
        $this->assertSame('FAILED_NOT_FROZEN', Files::json($this->directory.'/failed/state.json')['status']);
    }

    public function test_restored_losses_and_three_fold_selection_are_bit_exact_and_eligibility_is_intersection(): void
    {
        $inputs = $this->inputs($this->directory);
        $original = app(Trainer::class)->grid([$inputs[2022]], [$inputs[2023]], true, Files::directory($this->directory.'/old'));
        $restored = app(ReuseBundle::class)->restoreFold($this->directory.'/old', [$inputs[2022]], $inputs[2023], Files::directory($this->directory.'/restore'));
        $this->assertSame(iterator_to_array($original['losses']->records()), iterator_to_array($restored->records()));
        $this->assertSame($original['losses']->availableLambdaKeys(), $restored->availableLambdaKeys());
        $selector = new Bt03e03OneSeSelector;
        $this->assertSame($selector->select([2023 => $original['losses'], 2024 => $original['losses'], 2025 => $original['losses']]),
            $selector->select([2023 => $restored, 2024 => $restored, 2025 => $restored]));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('reused training dates/cohort/support');
        app(ReuseBundle::class)->restoreFold($this->directory.'/old', [$inputs[2023]], $inputs[2023], Files::directory($this->directory.'/wrong'));
    }

    #[DataProvider('badModelCases')]
    public function test_saved_model_rejects_malformed_contract_layout_and_coefficients(string $case): void
    {
        $model = $this->model();
        switch ($case) {
            case 'version': $model['optimizer_version'] = 'old';
                break;
            case 'anchor': $model['stat01_anchor_coefficient'] = 2.0;
                break;
            case 'lambda': $model['lambda'] = 0.2;
                break;
            case 'order': $model['layout']['bins'] = array_reverse($model['layout']['bins'], true);
                break;
            case 'support': $model['layout']['support_weights'][0] = 0.5;
                break;
            case 'bin': $model['layout']['bins']['STAT-07'][0]['index'] = 2;
                break;
            case 'size': array_pop($model['position_coefficients']['POSITION_1']);
                break;
            case 'nan': $model['position_coefficients']['POSITION_1'][0] = NAN;
                break;
            case 'center': $model['position_coefficients']['POSITION_1'][0] += 1.0;
                break;
            case 'convergence': $model['optimizer_diagnostics']['POSITION_1']['status'] = 'NUMERICALLY_NON_CONVERGED';
                break;
            case 'residual': $model['optimizer_diagnostics']['POSITION_1']['prox_gradient_mapping_max'] = 1.0;
                break;
        }
        $this->expectException(RuntimeException::class);
        app(ModelLoader::class)->restore($model);
    }

    public static function badModelCases(): array
    {
        return array_map(fn ($case) => [$case], ['version', 'anchor', 'lambda', 'order', 'support', 'bin', 'size', 'nan', 'center', 'convergence', 'residual']);
    }

    #[DataProvider('badInputCases')]
    public function test_prediction_rejects_outcomes_and_holdout_before_publishing(string $case): void
    {
        $this->model();
        $root = $this->directory.'/model';
        JsonlArtifact::json($root.'/artifact.json', ['contract' => Contract::plan(), 'model_file' => 'model.json', 'model' => Files::identity($root.'/model.json')]);
        $races = $this->races(2025);
        if ($case === '2026') {
            $races[1]['year'] = 2026;
        } elseif ($case === 'race_outcome') {
            $races[1]['actual'] = [1, 2, 3];
        } else {
            $races[1]['entries'][0][$case] = 1;
        }
        JsonlArtifact::write($this->directory.'/bad.jsonl', $races);
        try {
            app(PredictionService::class)->run($root.'/artifact.json', $this->directory.'/bad.jsonl', $this->directory.'/forbidden.jsonl');
            $this->fail('Outcome or holdout input accepted.');
        } catch (RuntimeException) {
            $this->assertFileDoesNotExist($this->directory.'/forbidden.jsonl');
            $this->assertSame(0, filesize($this->directory.'/forbidden.jsonl.partial'));
        }
    }

    public static function badInputCases(): array
    {
        return array_map(fn ($key) => [$key], ['2026', 'rank', 'status', 'label', 'actual', 'result', 'payout', 'race_outcome']);
    }

    public function test_same_length_model_byte_drift_is_rejected_before_restore(): void
    {
        $this->model();
        $path = $this->directory.'/model/model.json';
        $seal = Files::identity($path);
        $handle = fopen($path, 'r+b');
        fwrite($handle, '[');
        fclose($handle);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('hash/size mismatch');
        app(ModelLoader::class)->load($path, $seal);
    }

    public function test_loader_restores_numeric_bounds_categories_missing_and_inactive_history_bins(): void
    {
        $model = $this->model();
        $bins = [];
        foreach ($model['layout']['bins'] as $code => $rows) {
            $bins[$code] = array_map(fn ($row) => new EffectBinDto($row['index'], $row['kind'], $row['lower_bound'], $row['upper_bound'], $row['category_value'], $row['training_support']), $rows);
        }
        $bins['STAT-07'] = [new EffectBinDto(1, 'NUMERIC_RANGE', null, 2.0, null, 8), new EffectBinDto(2, 'NUMERIC_RANGE', 2.0, null, null, 2)];
        $bins[HistoryAggregator::FEATURES[0]] = [new EffectBinDto(1, 'NUMERIC_RANGE', null, 0.0, null, 10), new EffectBinDto(2, 'NUMERIC_RANGE', 0.0, null, null, 0)];
        $bins[HistoryAggregator::FEATURES[3]] = [];
        $layout = new Layout($bins);
        $model['layout'] = ['feature_count' => $layout->featureCount(), 'active_parameter_count_M' => $layout->size(), 'active_group_count_G' => count($layout->groups()),
            'numeric_edge_count' => count($layout->smoothEdges()), 'groups' => $layout->groups(), 'support_weights' => $layout->supportWeights(),
            'smooth_edges' => $layout->smoothEdges(), 'bins' => $layout->canonicalBins()];
        foreach (Bt03e03Contract::POSITIONS as $position) {
            $model['position_coefficients'][$position] = array_fill(0, $layout->size(), 0.0);
            $model['weighted_center_means'][$position] = $layout->weightedMeans($model['position_coefficients'][$position]);
        }
        $restored = app(ModelLoader::class)->restore($model)->layout;
        $builder = app(EffectBinBuilder::class);
        foreach ([null, 2.0, 2.1] as $value) {
            $values = array_fill(0, 16, 0);
            $values[0] = $value;
            $values[12] = 1;
            $actual = $restored->assign($values, $builder);
            $this->assertSame($layout->assign($values, $builder), $actual);
            $this->assertNull($actual[12]);
            $this->assertNull($actual[15]);
        }
        $model['layout']['bins']['STAT-07'][1]['lower_bound'] = 3.0;
        $this->expectException(RuntimeException::class);
        app(ModelLoader::class)->restore($model);
    }

    public function test_one_se_uses_only_three_fold_shared_candidates_and_does_not_fallback_when_none(): void
    {
        $one = Bt03e03ValidationLossSpool::lambdaKey(1.0);
        $tenth = Bt03e03ValidationLossSpool::lambdaKey(0.1);
        $spools = [];
        foreach ([2023, 2024, 2025] as $year) {
            $spools[$year] = new Bt03e03ValidationLossSpool($this->directory.'/'.$year.'.bin', $year === 2025 ? [$one] : [$tenth, $one]);
            $loss = [$one => array_fill_keys(Bt03e03Contract::POSITIONS, 1.0)];
            if ($year !== 2025) {
                $loss[$tenth] = array_fill_keys(Bt03e03Contract::POSITIONS, 0.1);
            }
            $spools[$year]->append($loss);
            $spools[$year]->seal();
        }
        $selection = (new Bt03e03OneSeSelector)->select($spools);
        $this->assertSame(1.0, $selection['lambda']);
        $this->assertSame([$one], $selection['eligible_lambda_keys']);
        $empty = new Bt03e03ValidationLossSpool($this->directory.'/empty.bin', []);
        $empty->append([]);
        $empty->seal();
        $this->expectException(RuntimeException::class);
        (new Bt03e03OneSeSelector)->select([2023 => $spools[2023], 2024 => $spools[2024], 2025 => $empty]);
    }

    public function test_parent_manifest_and_contract_drift_refuse_reuse(): void
    {
        $parent = $this->parentBundle();
        $evidence = app(ReuseBundle::class)->verify($parent);
        $handle = fopen($parent.'/run-01/C1-inner-A/validation-losses.jsonl', 'r+b');
        fwrite($handle, '[');
        fclose($handle);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('hash/size mismatch');
        app(ReuseBundle::class)->unchanged($evidence);
    }

    public function test_plan_is_read_only_and_paths_are_required_and_no_output_can_overwrite_parent(): void
    {
        DB::shouldReceive('connection')->never();
        $this->artisan('keirin:backtest:tactical-history-final', ['--plan' => true])->assertSuccessful();
        $this->artisan('keirin:backtest:tactical-history-final', ['--execute' => true])->assertFailed();
        $this->artisan('keirin:backtest:tactical-history-final', ['--plan' => true, '--execute' => true])->assertFailed();
        $this->expectException(RuntimeException::class);
        app(FinalFitService::class)->run($this->directory, $this->directory.'/child');
    }

    public function test_source_check_requires_read_only_postgresql_and_does_not_rollback_callers_transaction(): void
    {
        $db = DB::connection();
        $db->beginTransaction();
        try {
            app(SourceCheck::class)->verify($this->directory, $this->directory.'/source.json');
            $this->fail('Writable/test connection accepted.');
        } catch (RuntimeException) {
            $this->assertSame(1, $db->transactionLevel());
            $this->assertSame('FAILED', Files::json($this->directory.'/source.json')['status']);
        } finally {
            $db->rollBack();
        }
    }

    private function model(): array
    {
        $inputs = $this->inputs($this->directory);

        return app(Trainer::class)->refit([$inputs[2022]], $inputs[2025], true, 1.0, Files::directory($this->directory.'/model'))['model'];
    }

    private function inputs(string $root): array
    {
        $paths = [];
        foreach ([2022, 2023, 2024, 2025] as $year) {
            $paths[$year] = $root.'/inputs-'.$year.'.jsonl';
            JsonlArtifact::write($paths[$year], $this->races($year, $year <= 2023));
        }

        return $paths;
    }

    private function races(int $year, bool $labels = false): array
    {
        $races = [];
        foreach ([10, 20] as $id) {
            $entries = [];
            foreach (range(1, 5) as $bike) {
                $entry = ['id' => $year * 10000 + $id * 10 + $bike, 'bike' => $bike, 'raw' => 100.0, 'stat01_rank' => 1,
                    'anchor' => 0.0, 'anchor_status' => 'ZERO_VARIANCE', 'signals' => array_fill(0, 12, 0),
                    'history' => [0, 0, 0, 0], 'history_status' => 'AVAILABLE'];
                if ($labels) {
                    $entry['rank'] = $bike;
                    $entry['status'] = 'FINISHED';
                }
                $entries[] = $entry;
            }
            $races[] = ['year' => $year, 'race_id' => $year * 100 + $id, 'entries' => $entries];
        }

        return $races;
    }

    private function parentBundle(): string
    {
        $parent = Files::directory($this->directory.'/parent');
        $input = Files::directory($parent.'/inputs-v2');
        $paths = $this->inputs($input);
        $run = Files::directory($parent.'/run-01');
        $manifests = [];
        foreach ([2022, 2023, 2024, 2025] as $year) {
            $manifests[$year] = ['inputs' => Files::json($paths[$year].'.manifest.json'),
                'history' => JsonlArtifact::write($input.'/history-'.$year.'.jsonl', [])];
            if ($year >= 2024) {
                JsonlArtifact::write($run.'/labels-'.$year.'.jsonl', $this->races($year, true));
            }
        }
        JsonlArtifact::json($input.'/history-cache.sqlite', ['synthetic' => 'source checker substituted in this test']);
        JsonlArtifact::json($input.'/history-entry-index.json', []);
        $metadata = ['manifests' => $manifests];
        JsonlArtifact::json($input.'/manifest.json', $metadata);
        JsonlArtifact::json($parent.'/input-run-result.json', ['source_start' => ['synthetic' => true], 'inputs' => $metadata]);
        JsonlArtifact::json($parent.'/frozen-experiment-contract.json', Contract::parentSettings() + ['code_files' => ['app/Domain/Keirin/Backtest/Experiments/TacticalHistory/Optimizer.php' => hash_file('sha256', app_path('Domain/Keirin/Backtest/Experiments/TacticalHistory/Optimizer.php'))]]);
        foreach (['source-before-fit', 'source-after-run'] as $name) {
            JsonlArtifact::json($parent.'/'.$name.'.json', ['status' => 'VERIFIED_CURRENT_STATE_AGAINST_INPUT_SNAPSHOT', 'features' => ['synthetic' => true]]);
        }
        JsonlArtifact::json($parent.'/comparisons.json', ['status' => 'EVALUATED_AND_REPRODUCED',
            'incremental_gate' => ['status' => 'PASS_DEVELOPMENT_INCREMENTAL_EFFECT_ONLY'], 'stat01_gate' => ['status' => 'PASS / GO_TO_FREEZE']]);
        JsonlArtifact::json($parent.'/evaluation-reproducibility.json', ['status' => 'VERIFIED']);
        foreach (['run-01', 'run-02'] as $name) {
            JsonlArtifact::json($parent.'/'.$name.'-completion.json', ['synthetic' => true]);
        }
        $trainer = app(Trainer::class);
        foreach (['A' => [2022], 'B' => [2022, 2023]] as $letter => $years) {
            $validation = $letter === 'A' ? $paths[2023] : $run.'/labels-2024.jsonl';
            $fit = $trainer->grid(array_map(fn ($y) => $paths[$y], $years), [$validation], true, Files::directory($run.'/C1-inner-'.$letter));
            $fit['losses']->cleanup();
        }
        foreach ([2024, 2025] as $year) {
            $trainer->refit([$paths[2022], $paths[2023]], $paths[$year], true, 1.0, Files::directory($run.'/C1-fit-'.$year));
        }
        Files::directory($parent.'/run-02');
        foreach ($this->inventory($run) as $path => $seal) {
            $target = $parent.'/run-02/'.$path;
            if (! is_dir(dirname($target))) {
                mkdir(dirname($target), 0755, true);
            }
            copy($run.'/'.$path, $target);
        }
        JsonlArtifact::json($parent.'/reproducibility.json', ['status' => 'VERIFIED_TWO_REAL_FITS', 'run_01_files' => $this->inventory($run), 'run_02_files' => $this->inventory($parent.'/run-02')]);
        JsonlArtifact::json($parent.'/report-export-manifest.json', ['included' => $this->inventory($parent), 'omitted' => []]);

        return $parent;
    }

    private function inventory(string $root): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $files[substr($file->getPathname(), strlen($root) + 1)] = Files::identity($file->getPathname());
        }
        ksort($files, SORT_STRING);

        return $files;
    }

    private function fakeSourceCheck(bool $failAtEnd = false): SourceCheck
    {
        $fake = new class($failAtEnd) extends SourceCheck
        {
            public int $calls = 0;

            public function __construct(private bool $failAtEnd) {}

            public function verify(string $source, string $report): array
            {
                $this->calls++;
                if ($this->calls === 2 && $this->failAtEnd) {
                    throw new RuntimeException('Synthetic end integrity drift');
                }
                $result = ['status' => 'VERIFIED', 'synthetic' => true, 'features' => 'VERIFIED', 'history' => 'VERIFIED'];
                JsonlArtifact::json($report, $result);

                return $result;
            }
        };
        $this->app->instance(SourceCheck::class, $fake);

        return $fake;
    }
}
