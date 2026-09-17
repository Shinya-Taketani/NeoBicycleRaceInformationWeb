<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Keirin\Backtest\DTO\EffectBinDto;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\HistoryAggregator;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Layout;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\SolverContract;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Contract;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\ModelLoader;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\PredictionService;
use App\Domain\Keirin\Backtest\Services\Bt03e03Contract;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class TacticalHistoryFinalModelLoaderTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/tactical-loader-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
        JsonlArtifact::json($this->directory.'/model.json', $this->model());
        JsonlArtifact::json($this->directory.'/artifact.json', ['contract' => Contract::plan(), 'model_file' => 'model.json',
            'model' => Files::identity($this->directory.'/model.json')]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') as $path) {
            unlink($path);
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    public function test_object_key_order_is_irrelevant_but_race_and_entry_order_are_preserved(): void
    {
        $races = [$this->race(93), $this->race(12), $this->race(51)];
        $races[0]['entries'][0]['signals'] = [2, null, 0, 3, 1, 5, 0, 2, 4, 0, 1, 0];
        $races[0]['entries'][0]['history'] = [3, 0, 2, 1];
        $races[0]['entries'] = array_reverse($races[0]['entries']);
        $expected = $this->predict('original', $races);
        foreach ($races as &$race) {
            foreach ($race['entries'] as &$entry) {
                $entry = array_reverse($entry, true);
            }
            unset($entry);
            $race = array_reverse($race, true);
        }
        unset($race);
        $this->assertSame($expected, $this->predict('permuted', $races));
        $this->assertSame(file_get_contents($this->directory.'/original-out.jsonl'), file_get_contents($this->directory.'/permuted-out.jsonl'));
        $rows = iterator_to_array(JsonlArtifact::read($this->directory.'/permuted-out.jsonl'));
        $this->assertSame([93, 12, 51], array_column(array_column($rows, 'probabilities'), 'race_id'));
        $this->assertSame([5, 4, 3, 2, 1], array_column($rows[0]['probabilities']['entries'], 'bike'));
    }

    #[DataProvider('unavailableStatuses')]
    public function test_known_unavailable_history_accepts_only_all_null_and_keeps_observed_zero_distinct(string $status): void
    {
        $race = $this->race();
        $zero = $this->predict('zero', [$race]);
        $race['entries'][0]['history_status'] = $status;
        $race['entries'][0]['history'] = [null, null, null, null];
        $missing = $this->predict('missing', [$race]);
        $this->assertSame(1, $missing['rows']);
        $this->assertNotSame($zero['sha256'], $missing['sha256']);
        $race['entries'][0]['history'][2] = 0;
        $this->assertRejected([$race], 'history status/counts');
    }

    public static function unavailableStatuses(): array
    {
        return array_map(fn ($s) => [$s], ['MISSING_TARGET_METADATA', 'LEFT_TRUNCATED', 'INVALID_HISTORY', 'PARTIAL_HISTORY', 'MISSING_METHOD_HISTORY', 'NO_HISTORY']);
    }

    #[DataProvider('invalidInputCases')]
    public function test_invalid_input_is_rejected_before_any_prediction_is_written(string $case): void
    {
        $races = [$this->race(30), $this->race(10)];
        $entry = &$races[1]['entries'][0];
        switch ($case) {
            case 'unknown-history': $entry['history_status'] = 'UNKNOWN';
                break;
            case 'available-null': $entry['history'][0] = null;
                break;
            case 'negative-history': $entry['history'][0] = -1;
                break;
            case 'unknown-anchor': $entry['anchor_status'] = 'UNKNOWN';
                break;
            case 'zero-with-value': $entry['anchor'] = 1.0;
                break;
            case 'available-zero-variance': $entry['anchor_status'] = 'AVAILABLE';
                break;
            case 'raw-anchor-disagreement': $entry['raw'] = 101.0;
                break;
            case 'id-zero': $entry['id'] = 0;
                break;
            case 'id-negative': $entry['id'] = -1;
                break;
            case 'id-string': $entry['id'] = '101';
                break;
            case 'id-null': $entry['id'] = null;
                break;
            case 'id-float': $entry['id'] = 101.0;
                break;
            case 'id-bool': $entry['id'] = true;
                break;
            case 'duplicate-entry': $entry['id'] = $races[1]['entries'][1]['id'];
                break;
            case 'duplicate-bike': $entry['bike'] = $races[1]['entries'][1]['bike'];
                break;
            case 'duplicate-race': $races[] = $races[0];
                break;
            case 'missing-entry-key': unset($entry['anchor_status']);
                break;
            case 'missing-race-key': unset($races[1]['year']);
                break;
            case 'extra-entry-key': $entry['actual'] = 1;
                break;
            case 'extra-race-key': $races[1]['result'] = [];
                break;
            case 'scalar-entry': $races[1]['entries'][0] = 'invalid';
                break;
            case '2026': $races[1]['year'] = 2026;
                break;
        }
        unset($entry);
        $this->assertRejected($races, 'Prediction');
    }

    public static function invalidInputCases(): array
    {
        return array_map(fn ($s) => [$s], ['unknown-history', 'available-null', 'negative-history', 'unknown-anchor', 'zero-with-value',
            'available-zero-variance', 'raw-anchor-disagreement', 'id-zero', 'id-negative', 'id-string', 'id-null', 'id-float', 'id-bool',
            'duplicate-entry', 'duplicate-bike', 'duplicate-race', 'missing-entry-key', 'missing-race-key', 'extra-entry-key', 'extra-race-key', 'scalar-entry', '2026']);
    }

    public function test_available_anchor_accepts_race_standardization_including_a_zero_at_the_mean(): void
    {
        $race = $this->race();
        foreach ($race['entries'] as $i => &$entry) {
            $entry['raw'] = 98.0 + $i;
            $entry['anchor'] = ($entry['raw'] - 100.0) / sqrt(2.0);
            $entry['anchor_status'] = 'AVAILABLE';
        }
        unset($entry);
        $this->assertSame(1, $this->predict('available', [$race])['rows']);
        $race['entries'][2]['anchor'] = 0.1;
        $this->assertRejected([$race], 'anchor');
    }

    public function test_nonadjacent_duplicate_after_many_unsorted_rows_is_rejected_with_bounded_memory(): void
    {
        $before = memory_get_usage(true);
        $races = (function (): \Generator {
            for ($id = 30000; $id >= 1; $id--) {
                yield $this->race($id);
            }
            yield $this->race(30000);
        })();
        $this->assertRejected($races, 'duplicate race');
        $this->assertLessThan(16 * 1024 * 1024, memory_get_usage(true) - $before);
    }

    #[DataProvider('invalidDiagnostics')]
    public function test_saved_diagnostics_must_agree_with_model_and_frozen_convergence(string $key, mixed $value): void
    {
        $model = $this->model();
        $model['optimizer_diagnostics']['POSITION_1'][$key] = $value;
        $this->expectException(RuntimeException::class);
        app(ModelLoader::class)->restore($model);
    }

    public static function invalidDiagnostics(): array
    {
        return [
            ['lambda', 0.1], ['position', 'POSITION_2'], ['iteration', 2], ['accepted_update_count', 2], ['max_iterations', 201],
            ['eligible_race_count', 2], ['excluded_race_count', 1], ['final_objective', 1.1], ['previous_objective', 2.0],
            ['previous_objective', NAN], ['relative_objective_change', 2e-10], ['relative_objective_change', -1.0],
            ['maximum_coefficient_change', 2e-7], ['maximum_coefficient_change', null], ['prox_gradient_mapping_max', 2e-7],
            ['stationarity_reference_step', 0.5], ['stationarity_reference_step', null], ['centering_residual_max', 1e-8],
            ['current_step', 0.0], ['current_step', 2.0], ['optimizer_attempt_count', 0], ['optimizer_attempt_count', 3],
        ];
    }

    public function test_existing_inclusive_convergence_thresholds_are_accepted_without_refitting(): void
    {
        $model = $this->model();
        foreach (Bt03e03Contract::POSITIONS as $position) {
            $model['optimizer_diagnostics'][$position]['maximum_coefficient_change'] = Bt03e03Contract::CONVERGENCE_TOLERANCE;
            $model['optimizer_diagnostics'][$position]['prox_gradient_mapping_max'] = Bt03e03Contract::CONVERGENCE_TOLERANCE;
        }
        $this->assertSame($model, app(ModelLoader::class)->restore($model)->artifact);
    }

    public function test_relative_objective_change_must_satisfy_tolerance_even_when_internally_consistent(): void
    {
        $model = $this->model();
        $diag = &$model['optimizer_diagnostics']['POSITION_1'];
        $diag['previous_objective'] = 1.0 + 5e-11;
        $diag['relative_objective_change'] = ($diag['previous_objective'] - 1.0) / $diag['previous_objective'];
        $this->assertSame($model, app(ModelLoader::class)->restore($model)->artifact);
        $diag['previous_objective'] = 1.0 + 2e-10;
        $diag['relative_objective_change'] = ($diag['previous_objective'] - 1.0) / $diag['previous_objective'];
        $this->expectException(RuntimeException::class);
        app(ModelLoader::class)->restore($model);
    }

    private function assertRejected(iterable $races, string $message): void
    {
        try {
            $this->predict('bad', $races);
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
            $this->assertFileDoesNotExist($this->directory.'/bad-out.jsonl');
            $this->assertSame(0, filesize($this->directory.'/bad-out.jsonl.partial'));

            return;
        }
        $this->fail('Invalid prediction input was accepted.');
    }

    private function predict(string $name, iterable $races): array
    {
        $input = $this->directory.'/'.$name.'.jsonl';
        JsonlArtifact::write($input, $races);

        return app(PredictionService::class)->run($this->directory.'/artifact.json', $input, $this->directory.'/'.$name.'-out.jsonl');
    }

    private function race(int $id = 10): array
    {
        $entries = [];
        foreach (range(1, 5) as $bike) {
            $entries[] = ['id' => $id * 10 + $bike, 'bike' => $bike, 'raw' => 100.0, 'stat01_rank' => 1,
                'anchor' => 0.0, 'anchor_status' => 'ZERO_VARIANCE', 'signals' => array_fill(0, 12, 0),
                'history' => [0, 0, 0, 0], 'history_status' => 'AVAILABLE'];
        }

        return ['year' => 2025, 'race_id' => $id, 'entries' => $entries];
    }

    private function model(): array
    {
        // Synthetic saved model: loader tests neither train nor select lambda.
        $bins = [];
        foreach (Contract::plan()['features'] as $feature) {
            $bins[$feature] = [new EffectBinDto(1, 'NUMERIC_RANGE', null, 0.0, null, 5),
                new EffectBinDto(2, 'NUMERIC_RANGE', 0.0, null, null, 5)];
        }
        $layout = new Layout($bins);
        $coefficients = array_fill(0, $layout->size(), 0.0);
        $coefficients[24] = 0.25;
        $coefficients[25] = -0.25;
        $diagnostics = [];
        foreach (Bt03e03Contract::POSITIONS as $position) {
            $diagnostics[$position] = ['optimizer_version' => SolverContract::OPTIMIZER_VERSION, 'status' => 'CONVERGED',
                'lambda' => 1.0, 'position' => $position, 'iteration' => 1, 'accepted_update_count' => 1, 'max_iterations' => 200,
                'optimizer_attempt_count' => 1, 'eligible_race_count' => 1, 'excluded_race_count' => 0,
                'final_objective' => 1.0, 'previous_objective' => 1.0, 'relative_objective_change' => 0.0, 'maximum_coefficient_change' => 0.0,
                'current_step' => 1.0, 'prox_gradient_mapping_max' => 0.0, 'stationarity_reference_step' => 1.0, 'centering_residual_max' => 0.0];
        }

        return ['experiment' => HistoryAggregator::VERSION, 'optimizer_version' => SolverContract::OPTIMIZER_VERSION,
            'model_version' => SolverContract::MODEL_VERSION, 'objective_reference_version' => Bt03e03Contract::OPTIMIZER_VERSION,
            'stat01_anchor_coefficient' => 1.0, 'lambda' => 1.0,
            'layout' => ['feature_count' => $layout->featureCount(), 'active_parameter_count_M' => $layout->size(),
                'active_group_count_G' => count($layout->groups()), 'numeric_edge_count' => count($layout->smoothEdges()),
                'groups' => $layout->groups(), 'support_weights' => $layout->supportWeights(), 'smooth_edges' => $layout->smoothEdges(), 'bins' => $layout->canonicalBins()],
            'position_coefficients' => array_fill_keys(Bt03e03Contract::POSITIONS, $coefficients),
            'weighted_center_means' => array_fill_keys(Bt03e03Contract::POSITIONS, $layout->weightedMeans($coefficients)),
            'objectives' => array_fill_keys(Bt03e03Contract::POSITIONS, 1.0), 'iterations' => array_fill_keys(Bt03e03Contract::POSITIONS, 1),
            'eligible_races' => array_fill_keys(Bt03e03Contract::POSITIONS, 1), 'excluded_races' => array_fill_keys(Bt03e03Contract::POSITIONS, 0),
            'optimizer_diagnostics' => $diagnostics];
    }
}
