<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Keirin\Backtest\Calculators\Bt03e05MetricEvaluator;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionPipeline\ArtifactStore;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionPipeline\Contract as PredictionContract;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionPipeline\ModelIdentity;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionResult\Contract;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionResult\Matcher;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionResult\ResultService;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionResult\ResultStore;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionResult\Sources;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class TacticalPredictionResultTest extends TestCase
{
    private string $directory;

    private string $root;

    private array $targets = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/prediction-result-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
        mkdir($this->directory.'/source');
        $this->root = Files::directory($this->directory.'/output');
        config(['database.default' => 'disabled-result-test', 'tactical_prediction_pipeline.artifact_base' => $this->directory]);
        // Synthetic already-saved predictions: no optimizer, inference, or DB is involved in this fixture.
        $this->app->instance(ModelIdentity::class, new class extends ModelIdentity
        {
            public function __construct() {}

            public function validate(string $artifact): void
            {
                if (Files::json(dirname($artifact).'/model.json') !== ['synthetic' => 'frozen']) {
                    throw new RuntimeException('Synthetic model identity mismatch.');
                }
            }
        });
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    #[DataProvider('counts')]
    public function test_saved_predictions_match_direct_evaluator_with_offline_execute_reuse_and_reproduction(int $count): void
    {
        $first = $this->fixture(90, $count);
        $second = $this->fixture(10, $count);
        $labels = [$this->labels($second), $this->labels($first)];
        $this->saveInputs($labels);
        $before = $this->sourceSeals();
        $run = $this->execute();
        $this->assertSame('RESULT_LOCKED', $run['status']);
        $this->assertSame(2, $run['summary']['matched']);
        $evaluator = new Bt03e05MetricEvaluator;
        $summary = $evaluator->emptySummary();
        $actual = iterator_to_array(JsonlArtifact::read($run['path'].'/contributions.jsonl'));
        foreach ([$first, $second] as $i => $fixed) {
            $expected = $evaluator->raceComparison($this->labels($fixed), $fixed['prediction']['decision']);
            $this->assertSame($expected, $actual[$i]['comparison']);
            $evaluator->add($summary, $expected);
        }
        $this->assertSame([90, 10], array_column($actual, 'race_id'));
        $this->assertSame($summary, $run['summary']['accumulator']);
        $this->assertSame($evaluator->finish($summary), $run['summary']['evaluator']);
        $this->assertSame('REUSED', $this->execute()['status']);
        $this->assertSame('REPRODUCED', app(ResultService::class)->reproduce(PredictionContract::MODE, $this->root, 'test-01')['status']);
        $this->assertSame($before, $this->sourceSeals());
        $this->assertSame(Contract::plan(), app(ResultStore::class)->verify($run['path'])['contract']);
    }

    public static function counts(): array
    {
        return [[5], [6], [7], [8], [9]];
    }

    #[DataProvider('outcomes')]
    public function test_ties_and_abnormal_results_keep_existing_evaluator_rules(string $case): void
    {
        $fixed = $this->fixture();
        $label = $this->labels($fixed);
        if (str_starts_with($case, 'tie')) {
            $position = (int) substr($case, 3);
            foreach ([$position - 1, $position] as $index) {
                $label['entries'][$index]['rank'] = $position;
                $label['entries'][$index]['status'] = 'TIED';
            }
        } else {
            $label['entries'][0]['rank'] = null;
            $label['entries'][0]['status'] = $case;
        }
        $this->saveInputs([$label]);
        $run = $this->execute();
        $expected = (new Bt03e05MetricEvaluator)->raceComparison($label, $fixed['prediction']['decision']);
        $actual = iterator_to_array(JsonlArtifact::read($run['path'].'/contributions.jsonl'))[0];
        $this->assertSame($expected, $actual['comparison']);
        $this->assertSame('UNEVALUABLE_ZERO_DENOMINATOR', $run['summary']['metrics']['POSITION_HIT_RATE_AT_3']['status']);
        $this->assertNull($run['summary']['metrics']['POSITION_HIT_RATE_AT_3']['rate']);
    }

    public static function outcomes(): array
    {
        return array_map(fn ($s) => [$s], ['tie1', 'tie2', 'tie3', 'DISQUALIFIED', 'DID_NOT_FINISH', 'DID_NOT_START', 'WITHDRAWN', 'CRASHED']);
    }

    public function test_zero_denominators_and_primary_supporting_exact_order_are_distinct(): void
    {
        $fixed = $this->fixture();
        $label = $this->labels($fixed);
        $this->saveInputs([$label]);
        $run = $this->execute();
        $this->assertSame(1.0, $run['summary']['evaluator']['decoder_diagnostics']['PRIMARY_EXACT_ORDERED_TOP3_RATE']);
        $this->assertSame(0.0, $run['summary']['metrics']['EXACT_ORDERED_TOP3_RATE']['rate']);
        $this->assertSame(1.0, $run['summary']['metrics']['POSITION_HIT_RATE_AT_3']['rate']);
        foreach ($label['entries'] as &$entry) {
            $entry['rank'] = null;
            $entry['status'] = 'DID_NOT_START';
        }
        unset($entry);
        JsonlArtifact::write($this->directory.'/source/cancelled.jsonl', [$label]);
        $cancelled = $this->execute('cancelled', 'cancelled.jsonl');
        $this->assertNull($cancelled['summary']['metrics']['POSITION_1_ACCURACY']['rate']);
        $this->assertSame(0.0, $cancelled['summary']['evaluator']['candidate']['POSITION_1_ACCURACY']);
    }

    public function test_result_features_never_replace_frozen_input_and_entry_order_is_not_join_key(): void
    {
        $fixed = $this->fixture();
        $label = $this->labels($fixed);
        foreach ($label['entries'] as &$entry) {
            $entry['raw'] = -9999.0;
            $entry['anchor'] = 999.0;
            $entry['signals'] = $entry['history'] = ['not input'];
        }
        unset($entry);
        $label['entries'] = array_reverse($label['entries']);
        $this->saveInputs([$label]);
        $run = $this->execute();
        $joined = iterator_to_array(JsonlArtifact::read($run['path'].'/joined.jsonl'))[0];
        $this->assertSame($this->labels($fixed), $joined['context']);
        foreach (iterator_to_array(JsonlArtifact::read($run['path'].'/results.jsonl'))[0]['entries'] as $entry) {
            $this->assertSame(['id', 'bike', 'rank', 'status'], array_keys($entry));
        }
    }

    #[DataProvider('invalidLabels')]
    public function test_invalid_labels_fail_without_publication_and_preserve_failure_stage(string $case): void
    {
        $fixed = $this->fixture();
        $label = $this->labels($fixed);
        switch ($case) {
            case 'missing-race': $label['race_id'] = 999;
                break;
            case '2026': $label['year'] = 2026;
                break;
            case 'wrong-year': $label['year'] = 2024;
                break;
            case 'missing-entry': array_pop($label['entries']);
                break;
            case 'extra-entry': $label['entries'][] = ['id' => 9000, 'bike' => 8, 'rank' => 8, 'status' => 'FINISHED'];
                break;
            case 'wrong-id': $label['entries'][0]['id'] = 9000;
                break;
            case 'wrong-bike': $label['entries'][0]['bike'] = 8;
                break;
            case 'duplicate-id': $label['entries'][0]['id'] = $label['entries'][1]['id'];
                break;
            case 'duplicate-bike': $label['entries'][0]['bike'] = 2;
                break;
            case 'unknown-status': $label['entries'][0]['status'] = 'UNKNOWN';
                break;
            case 'null-status': $label['entries'][0]['status'] = null;
                break;
            case 'string-rank': $label['entries'][0]['rank'] = '1';
                break;
            case 'missing-rank': unset($label['entries'][0]['rank']);
                break;
            case 'null-finished': $label['entries'][0]['rank'] = null;
                break;
            case 'zero-rank': $label['entries'][0]['rank'] = 0;
                break;
            case 'outside-rank': $label['entries'][0]['rank'] = 10;
                break;
            case 'abnormal-with-rank': $label['entries'][0]['status'] = 'DISQUALIFIED';
                break;
        }
        $this->saveInputs($case === 'duplicate-race' ? [$label, $label] : [$label]);
        $before = $this->sourceSeals();
        $this->mustFail(fn () => $this->execute());
        $this->assertDirectoryDoesNotExist($this->root.'/evaluations/test-01');
        $failed = glob($this->root.'/.staging/*/failure.json');
        $this->assertCount(1, $failed);
        $seal = Files::identity($failed[0]);
        $this->mustFail(fn () => $this->execute('another-attempt'));
        Files::verify($failed[0], $seal);
        $this->assertSame($before, $this->sourceSeals());
    }

    public static function invalidLabels(): array
    {
        return array_map(fn ($s) => [$s], ['missing-race', '2026', 'wrong-year', 'missing-entry', 'extra-entry', 'wrong-id', 'wrong-bike',
            'duplicate-id', 'duplicate-bike', 'unknown-status', 'null-status', 'string-rank', 'missing-rank', 'null-finished',
            'zero-rank', 'outside-rank', 'abnormal-with-rank', 'duplicate-race']);
    }

    public function test_corrected_results_get_new_version_conflict_on_old_id_and_reproduce_without_sources(): void
    {
        $fixed = $this->fixture();
        $original = $this->labels($fixed);
        $this->saveInputs([$original]);
        $first = $this->execute();
        $manifest = Files::identity($first['path'].'/manifest.json');
        $prediction = Files::identity($this->targets[0]['bundle_path'].'/prediction.jsonl');
        [$original['entries'][0]['rank'], $original['entries'][1]['rank']] = [2, 1];
        JsonlArtifact::write($this->directory.'/source/corrected.jsonl', [$original]);
        $this->mustFail(fn () => $this->execute('test-01', 'corrected.jsonl'), 'CONFLICT');
        $corrected = $this->execute('corrected', 'corrected.jsonl');
        $this->assertNotSame($first['summary'], $corrected['summary']);
        Files::verify($first['path'].'/manifest.json', $manifest);
        Files::verify($this->targets[0]['bundle_path'].'/prediction.jsonl', $prediction);
        rename($this->directory.'/source', $this->directory.'/source-unavailable');
        $this->assertSame($first['summary'], app(ResultService::class)->reproduce(PredictionContract::MODE, $this->root, 'test-01')['summary']);
    }

    #[DataProvider('corruptions')]
    public function test_unlocked_and_same_length_drift_sources_are_rejected_before_results(string $file): void
    {
        $fixed = $this->fixture();
        $this->saveInputs([$this->labels($fixed)]);
        $path = $this->targets[0]['bundle_path'].'/'.$file;
        if ($file === 'LOCKED.json') {
            unlink($path);
        } else {
            $this->drift($path);
        }
        $this->mustFail(fn () => $this->execute());
        $this->assertDirectoryDoesNotExist($this->root.'/evaluations/test-01');
    }

    public static function corruptions(): array
    {
        return [['LOCKED.json'], ['input.jsonl'], ['prediction.jsonl'], ['model.json'], ['manifest.json']];
    }

    public function test_corrupt_existing_evaluation_is_not_rebuilt(): void
    {
        $fixed = $this->fixture();
        $this->saveInputs([$this->labels($fixed)]);
        $first = $this->execute();
        $this->drift($first['path'].'/summary.json');
        $seal = Files::identity($first['path'].'/summary.json');
        $this->mustFail(fn () => $this->execute());
        $this->mustFail(fn () => app(ResultService::class)->reproduce(PredictionContract::MODE, $this->root, 'test-01'));
        Files::verify($first['path'].'/summary.json', $seal);
        $this->assertCount(0, glob($this->root.'/.staging/*'));
    }

    #[DataProvider('endDrifts')]
    public function test_source_end_drift_and_midstream_failure_never_publish(string $case): void
    {
        $fixed = $this->fixture();
        $this->saveInputs([$this->labels($fixed)]);
        $path = $case === 'labels' ? $this->directory.'/source/labels.jsonl' : $this->targets[0]['bundle_path'].'/prediction.jsonl';
        $source = new class(app(ArtifactStore::class), app(ModelIdentity::class), app(Matcher::class), $case, $path) extends Sources
        {
            public function __construct(ArtifactStore $store, ModelIdentity $models, Matcher $matcher, private string $case, private string $path)
            {
                parent::__construct($store, $models, $matcher);
            }

            public function extract(array $source): \Generator
            {
                yield from parent::extract($source);
                if ($this->case === 'exception') {
                    throw new RuntimeException('Injected extraction interruption');
                }
                $text = file_get_contents($this->path);
                file_put_contents($this->path, substr_replace($text, ' ', 0, 1));
            }
        };
        $this->app->instance(Sources::class, $source);
        $store = \Mockery::mock(ResultStore::class, [app(ArtifactStore::class)])->makePartial();
        $store->shouldNotReceive('publish');
        $this->app->instance(ResultStore::class, $store);
        $this->mustFail(fn () => $this->execute());
        $this->assertDirectoryDoesNotExist($this->root.'/evaluations/test-01');
        $this->assertCount(1, glob($this->root.'/.staging/*/failure.json'));
    }

    public static function endDrifts(): array
    {
        return [['labels'], ['prediction'], ['exception']];
    }

    public function test_annual_labels_are_streamed_with_unselected_rows_and_bounded_memory(): void
    {
        $fixed = $this->fixture();
        $target = $this->labels($fixed);
        $this->saveSelection();
        JsonlArtifact::write($this->directory.'/source/labels.jsonl', (function () use ($target): \Generator {
            for ($id = 1000; $id < 26273; $id++) {
                $row = $target;
                $row['race_id'] = $id;
                $row['ignored_source_features'] = str_repeat('x', 6000);
                yield $row;
            }
            yield $target;
        })());
        $this->assertGreaterThan(128 * 1024 * 1024, filesize($this->directory.'/source/labels.jsonl'));
        $run = $this->execute();
        $this->assertSame(1, $run['summary']['matched']);
        $this->assertLessThan(128 * 1024 * 1024, memory_get_peak_usage(true));
    }

    public function test_plan_requires_no_database_or_files_and_command_reproduction_is_offline(): void
    {
        $before = $this->sourceSeals();
        $this->artisan('keirin:backtest:tactical-prediction-result', ['--plan' => true])->assertExitCode(0);
        $this->assertSame($before, $this->sourceSeals());
        $fixed = $this->fixture();
        $this->saveInputs([$this->labels($fixed)]);
        $this->artisan('keirin:backtest:tactical-prediction-result', ['--execute' => true, '--mode' => PredictionContract::MODE,
            '--evaluation-id' => 'command', '--requests' => $this->directory.'/source/selection.json',
            '--labels' => $this->directory.'/source/labels.jsonl', '--labels-manifest' => $this->directory.'/source/labels.jsonl.manifest.json',
            '--output-root' => $this->root])->assertExitCode(0);
        $this->artisan('keirin:backtest:tactical-prediction-result', ['--reproduce' => true, '--mode' => PredictionContract::MODE,
            '--evaluation-id' => 'command', '--output-root' => $this->root])->assertExitCode(0);
    }

    public function test_busy_lock_and_reference_overlap_produce_no_result_writes(): void
    {
        $fixed = $this->fixture();
        $this->saveInputs([$this->labels($fixed)]);
        app(ResultStore::class)->prepare($this->root, []);
        $handle = fopen($this->root.'/.locks/test-01.lock', 'c');
        flock($handle, LOCK_EX);
        try {
            $this->mustFail(fn () => $this->execute(), 'BUSY');
            $this->assertCount(0, glob($this->root.'/.staging/*'));
        } finally {
            fclose($handle);
        }
        $before = $this->sourceSeals();
        $this->root = $this->targets[0]['bundle_path'];
        $this->mustFail(fn () => $this->execute(), 'overlaps');
        $this->assertSame($before, $this->sourceSeals());
        $this->assertDirectoryDoesNotExist($this->root.'/evaluations');
    }

    #[DataProvider('invalidSelections')]
    public function test_selection_guards(string $case): void
    {
        $fixed = $this->fixture();
        $this->saveInputs([$this->labels($fixed)]);
        $file = $this->directory.'/source/selection.json';
        $selection = Files::json($file);
        match ($case) {
            '2026' => $selection['result_year'] = $selection['targets'][0]['year'] = 2026,
            'duplicate' => $selection['targets'][] = $selection['targets'][0],
            'wrong-request' => $selection['targets'][0]['request_id'] = 'another',
            'wrong-race' => $selection['targets'][0]['race_id'] = 999,
        };
        file_put_contents($file, Files::canonical($selection));
        $this->mustFail(fn () => $this->execute());
        $this->assertDirectoryDoesNotExist($this->root.'/evaluations');
    }

    public static function invalidSelections(): array
    {
        return [['2026'], ['duplicate'], ['wrong-request'], ['wrong-race']];
    }

    private function fixture(int $id = 90, int $count = 7): array
    {
        $entries = [];
        for ($bike = 1; $bike <= $count; $bike++) {
            $entries[] = ['id' => $id * 10 + $bike, 'bike' => $bike, 'raw' => 80.0, 'anchor' => 0.0,
                'anchor_status' => 'ZERO_VARIANCE', 'stat01_rank' => 1, 'signals' => array_fill(0, 12, null),
                'history' => [0, 0, 0, 0], 'history_status' => 'AVAILABLE'];
        }
        $input = ['year' => 2025, 'race_id' => $id, 'entries' => $entries];
        $prediction = ['probabilities' => $input, 'decision' => ['year' => 2025, 'race_id' => $id,
            'primary_position_1_bike' => 1, 'primary_position_2_bike' => 2, 'primary_position_3_bike' => 3,
            'map_ordered_top3' => [3, 2, 1], 'map_top3_set' => [1, 2, 3], 'top2_marginal_bikes' => [1, 2],
            'top3_marginal_bikes' => [1, 2, 3], 'expected_ndcg_top3' => [1, 2, 3],
            'winner_tie_count' => 1, 'second_third_tie_count' => 1, 'primary_decision_tied' => false, 'primary_technical_tiebreak_used' => false]];
        $path = Files::directory($this->directory.'/source/race-'.$id);
        $request = ['request_id' => 'race-'.$id, 'race_id' => $id, 'mode' => PredictionContract::MODE];
        foreach (['model' => ['synthetic' => 'frozen'], 'artifact' => ['synthetic' => true], 'audit' => [], 'source-end' => [], 'code' => []] as $name => $data) {
            JsonlArtifact::json($path.'/'.$name.'.json', $data);
        }
        $request['model'] = Files::identity($path.'/model.json');
        $request['artifact'] = Files::identity($path.'/artifact.json');
        JsonlArtifact::json($path.'/request.json', $request);
        JsonlArtifact::write($path.'/input.jsonl', [$input]);
        JsonlArtifact::write($path.'/prediction.jsonl', [$prediction]);
        $seals = [];
        foreach (['request.json', 'model.json', 'artifact.json', 'input.jsonl', 'input.jsonl.manifest.json',
            'prediction.jsonl', 'prediction.jsonl.manifest.json', 'audit.json', 'source-end.json', 'code.json'] as $name) {
            $seals[$name] = Files::identity($path.'/'.$name);
        }
        JsonlArtifact::json($path.'/manifest.json', ['status' => 'DEVELOPMENT_REPLAY_LOCKED', 'contract' => PredictionContract::plan(), 'request' => $request, 'files' => $seals]);
        JsonlArtifact::json($path.'/LOCKED.json', Files::identity($path.'/manifest.json'));
        $this->targets[] = ['year' => 2025, 'race_id' => $id, 'request_id' => $request['request_id'], 'bundle_path' => $path, 'manifest' => Files::identity($path.'/manifest.json')];

        return ['request_id' => $request['request_id'], 'input' => $input, 'prediction' => $prediction];
    }

    private function labels(array $fixed): array
    {
        $label = $fixed['input'];
        foreach ($label['entries'] as &$entry) {
            $entry['rank'] = $entry['bike'];
            $entry['status'] = 'FINISHED';
        }

        return $label;
    }

    private function saveInputs(array $labels): void
    {
        $this->saveSelection();
        JsonlArtifact::write($this->directory.'/source/labels.jsonl', $labels);
    }

    private function saveSelection(): void
    {
        JsonlArtifact::json($this->directory.'/source/selection.json', ['result_year' => 2025, 'targets' => $this->targets]);
    }

    private function execute(string $id = 'test-01', string $labels = 'labels.jsonl'): array
    {
        return app(ResultService::class)->execute(PredictionContract::MODE, $id, $this->root,
            $this->directory.'/source/selection.json', $this->directory.'/source/'.$labels, $this->directory.'/source/'.$labels.'.manifest.json');
    }

    private function mustFail(callable $work, string $message = ''): void
    {
        try {
            $work();
            $this->fail('Invalid operation accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    private function drift(string $path): void
    {
        $text = file_get_contents($path);
        file_put_contents($path, substr_replace($text, ' ', 0, 1));
        $this->assertSame(strlen($text), filesize($path));
    }

    private function sourceSeals(): array
    {
        $seals = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory.'/source', \FilesystemIterator::SKIP_DOTS)) as $file) {
            $seals[$file->getPathname()] = Files::identity($file->getPathname());
        }

        return $seals;
    }
}
