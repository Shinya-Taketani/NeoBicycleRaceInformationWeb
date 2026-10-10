<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\Keirin\CompositionPredictionResultCommand;
use App\Domain\Keirin\Backtest\Calculators\Bt03e05MetricEvaluator;
use App\Domain\Keirin\Backtest\Experiments\C1CompositionRequest\Store as Requests;
use App\Domain\Keirin\Backtest\Experiments\C1CompositionResult\Contract;
use App\Domain\Keirin\Backtest\Experiments\C1CompositionResult\Service;
use App\Domain\Keirin\Backtest\Experiments\C1CompositionResult\Sources;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Publication;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact as Jsonl;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionResult\Matcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;
use Tests\Support\CompositionResultFixture as Fixture;

final class CompositionPredictionResultTest extends TestCase
{
    private ?Fixture $fixture = null;

    protected function setUp(): void
    {
        Fixture::environment();
    }

    protected function tearDown(): void
    {
        if ($this->fixture !== null) {
            $this->fixture->retire($this->status()->isSuccess() ? 'TEST_COMPLETE' : 'TEST_FAILURE');
        }
    }

    public function test_ten_requests_are_fixed_matched_shown_reused_and_independently_reproduced(): void
    {
        $f = $this->fixture = new Fixture(7, 10);
        $sourceBefore = $this->tree($f->requestRoot);
        $created = $f->execute();
        $this->assertSame('CREATED', $created['status']);
        $this->assertSame(10, $created['summary']['matched']);
        $before = $this->tree($created['path']);
        $this->assertSame('REUSED', $f->execute()['status']);
        $shown = $f->service()->show($f->output, 'synthetic-01');
        $this->assertSame($created['summary'], $shown['summary']);
        $this->assertSame($created['races'], $shown['races']);
        foreach (Jsonl::read($created['path'].'/joined.jsonl') as $row) {
            $this->assertGreaterThan(0, $row['context']['entries'][0]['raw']);
            $this->assertSame([null, 0, ...array_fill(0, 10, null)], $row['context']['entries'][0]['signals']);
        }
        $process = $this->worker($f, 'reproduce', offline: true);
        $process->mustRun();
        $reproduced = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('REPRODUCED', $reproduced['status']);
        $this->assertSame($created['summary'], $reproduced['summary']);
        $this->assertFalse($reproduced['original_sources_required']);
        $this->assertSame($before, $this->tree($created['path']));
        $this->assertSame($sourceBefore, $this->tree($f->requestRoot));
        $summary = app(Bt03e05MetricEvaluator::class)->emptySummary();
        foreach (Jsonl::read($created['path'].'/joined.jsonl') as $row) {
            app(Bt03e05MetricEvaluator::class)->add($summary,
                app(Bt03e05MetricEvaluator::class)->raceComparison($row['context'], $row['prediction']['decision']));
        }
        $this->assertSame($summary, $created['summary']['accumulator']);
        $this->assertSame(app(Bt03e05MetricEvaluator::class)->finish($summary), $created['summary']['evaluator']);
    }

    #[DataProvider('counts')]
    public function test_complete_fields_and_reversed_result_order_join_by_id_and_bike(int $count): void
    {
        $f = $this->fixture = new Fixture($count);
        $result = $f->execute();
        $joined = iterator_to_array(Jsonl::read($result['path'].'/joined.jsonl'))[0];
        $this->assertCount($count, $joined['context']['entries']);
        foreach ($joined['context']['entries'] as $entry) {
            $this->assertSame($entry['bike'], $entry['rank']);
        }
        $this->assertSame(3.0, $result['summary']['metrics']['POSITION_HIT_RATE_AT_3']['denominator']);
    }

    public static function counts(): array
    {
        return array_map(fn ($n) => [$n], range(5, 9));
    }

    #[DataProvider('abnormal')]
    public function test_ties_and_abnormal_results_keep_rank_sets_and_metric_specific_denominators(string $kind): void
    {
        $f = $this->fixture = new Fixture;
        $rows = $f->labelRows;
        if (str_starts_with($kind, 'tie')) {
            $rank = (int) substr($kind, 3);
            foreach ($rows[0]['entries'] as &$entry) {
                if ($entry['bike'] === $rank || $entry['bike'] === $rank + 1) {
                    $entry['rank'] = $rank;
                    $entry['status'] = 'TIED';
                }
            }
            unset($entry);
        } else {
            foreach ($rows[0]['entries'] as &$entry) {
                $entry['rank'] = null;
                $entry['status'] = $kind;
            }
            unset($entry);
        }
        $this->labels($f, $rows);
        $result = $f->execute();
        $metric = $result['summary']['metrics']['POSITION_HIT_RATE_AT_3'];
        $this->assertSame(0.0, $metric['denominator']);
        $this->assertNull($metric['rate']);
        $this->assertNull($metric['delta']);
        $this->assertSame(1, $metric['excluded_races']);
        $this->assertNull($result['summary']['primary_exact_ordered_top3']['rate']);
        if (str_starts_with($kind, 'tie')) {
            $this->assertCount(2, $result['races'][0]['actual_top3_sets'][(int) substr($kind, 3) - 1]);
        } else {
            $this->assertSame([null, null, null], $result['races'][0]['position_matches']);
        }
    }

    public static function abnormal(): array
    {
        return array_map(fn ($s) => [$s], ['tie1', 'tie2', 'tie3', 'DISQUALIFIED', 'DID_NOT_START', 'DID_NOT_FINISH', 'WITHDRAWN', 'CRASHED']);
    }

    #[DataProvider('badResults')]
    public function test_invalid_selected_or_late_annual_results_fail_without_publication(string $fault): void
    {
        $f = $this->fixture = new Fixture;
        $rows = $f->labelRows;
        switch ($fault) {
            case 'missing_race': $rows[0]['race_id'] = 99999;
                break;
            case 'missing_entry': array_pop($rows[0]['entries']);
                break;
            case 'extra_entry': $rows[0]['entries'][] = ['id' => 99999, 'bike' => 9, 'rank' => 8, 'status' => 'FINISHED'];
                break;
            case 'entry_id': $rows[0]['entries'][0]['id'] = 99999;
                break;
            case 'bike': $rows[0]['entries'][0]['bike'] = 9;
                break;
            case 'duplicate_entry': $rows[0]['entries'][0] = $rows[0]['entries'][1];
                break;
            case 'duplicate_race': $rows[] = $rows[0];
                break;
            case '2026_tail': $rows[] = ['year' => 2026, 'race_id' => 99999, 'entries' => []];
                break;
            case 'unknown_status': $rows[0]['entries'][0]['status'] = 'UNKNOWN';
                break;
            case 'rank_string': $rows[0]['entries'][0]['rank'] = '1';
                break;
            case 'rank_missing': unset($rows[0]['entries'][0]['rank']);
                break;
            case 'bad_tied': $rows[0]['entries'][0]['status'] = 'TIED';
                break;
        }
        $this->labels($f, $rows);
        $this->reject(fn () => $f->execute());
        $this->assertDirectoryDoesNotExist($f->output.'/evaluations/synthetic-01');
        $this->assertCount(1, glob($f->output.'/evaluations/*.inprogress-*/FAILED.json'));
        $this->assertFileExists($f->requestRoot.'/requests/'.$f->targets[0]['request_id'].'/manifest.json');
    }

    public static function badResults(): array
    {
        return array_map(fn ($s) => [$s], ['missing_race', 'missing_entry', 'extra_entry', 'entry_id', 'bike', 'duplicate_entry',
            'duplicate_race', '2026_tail', 'unknown_status', 'rank_string', 'rank_missing', 'bad_tied']);
    }

    #[DataProvider('badSelections')]
    public function test_invalid_selections_are_rejected_before_any_output(string $fault): void
    {
        $f = $this->fixture = new Fixture;
        $selection = ['result_year' => 2025, 'targets' => $f->targets];
        switch ($fault) {
            case 'empty': $selection['targets'] = [];
                break;
            case 'duplicate': $selection['targets'][] = $selection['targets'][0];
                break;
            case '2026': $selection['result_year'] = 2026;
                break;
            case 'wrong_race': $selection['targets'][0]['race_id'] = 999;
                break;
            case 'wrong_request': $selection['targets'][0]['request_id'] = '../request';
                break;
            case 'bad_seal': $selection['targets'][0]['manifest']['sha256'] = str_repeat('0', 64);
                break;
        }
        $f->selection = $f->root.'/bad-selection.json';
        Jsonl::json($f->selection, $selection);
        $this->reject(fn () => $f->execute());
        $this->assertDirectoryDoesNotExist($f->output);
    }

    public static function badSelections(): array
    {
        return array_map(fn ($s) => [$s], ['empty', 'duplicate', '2026', 'wrong_race', 'wrong_request', 'bad_seal']);
    }

    public function test_results_are_not_opened_until_all_predictions_are_sealed(): void
    {
        $f = $this->fixture = new Fixture(7, 10);
        $sources = new class(app(Requests::class), app(Matcher::class), $f->labels, Files::json($f->labels.'.manifest.json'), array_column($f->targets, 'race_id'), $f->output) extends Sources
        {
            public bool $checked = false;

            public function __construct(Requests $requests, Matcher $matcher, string $labels, array $seal, array $races, private string $output)
            {
                parent::__construct($requests, $matcher, $labels, $seal, $races);
            }

            public function results(array $source): \Generator
            {
                $stages = glob($this->output.'/evaluations/*.inprogress-*');
                if (count($stages) !== 1 || Files::json($stages[0].'/freeze.json')['request_count'] !== 10
                    || Files::json($stages[0].'/fixed.jsonl.manifest.json')['rows'] !== 10) {
                    throw new RuntimeException('Predictions were not fixed before results.');
                }
                $this->checked = true;
                yield from parent::results($source);
            }
        };
        $f->execute($f->service(sources: $sources));
        $this->assertTrue($sources->checked);
    }

    public function test_different_result_identity_conflicts_and_keeps_the_existing_bundle(): void
    {
        $f = $this->fixture = new Fixture;
        $created = $f->execute();
        $before = $this->tree($created['path']);
        $rows = $f->labelRows;
        $rows[0]['entries'] = array_reverse($rows[0]['entries']);
        $this->labels($f, $rows);
        $this->reject(fn () => $f->execute(), 'CONFLICT');
        $this->assertSame($before, $this->tree($created['path']));
        $this->assertSame('SAVED', $f->service()->show($f->output, 'synthetic-01')['status']);
        $this->assertSame('NOT_FOUND', $f->service()->show($f->output, 'missing-01')['status']);
    }

    public function test_same_id_is_locked_before_reuse_or_creation(): void
    {
        $f = $this->fixture = new Fixture;
        $created = $f->execute();
        $before = $this->tree($created['path']);
        $process = $this->worker($f, 'execute', hold: true);
        $process->start();
        $deadline = microtime(true) + 3;
        while (! file_exists($f->root.'/LOCK_READY') && microtime(true) < $deadline) {
            usleep(10000);
        }
        try {
            $this->assertFileExists($f->root.'/LOCK_READY');
            $this->reject(fn () => $f->execute(), 'locked');
        } finally {
            $process->wait();
        }
        $this->assertSame(0, $process->getExitCode());
        $this->assertSame($before, $this->tree($created['path']));
        $this->assertSame('REUSED', $f->execute()['status']);
    }

    public function test_resealed_summary_contradiction_is_rejected_by_saved_semantic_recalculation(): void
    {
        $f = $this->fixture = new Fixture;
        $created = $f->execute();
        $path = $created['path'];
        $summary = Files::json($path.'/summary.json');
        $summary['matched'] = 999;
        $this->overwriteSynthetic($path.'/summary.json', $summary);
        $manifest = Files::json($path.'/manifest.json');
        $manifest['files']['summary.json'] = Files::identity($path.'/summary.json');
        $this->overwriteSynthetic($path.'/manifest.json', $manifest);
        $this->overwriteSynthetic($path.'/COMPLETE.json', Files::identity($path.'/manifest.json'));
        $before = $this->tree($path);
        $this->reject(fn () => $f->service()->show($f->output, 'synthetic-01'), 'semantic');
        $this->assertSame($before, $this->tree($path));
    }

    public function test_written_expectations_reject_mutation_before_publication(): void
    {
        $f = $this->fixture = new Fixture;
        $publication = new class extends Publication
        {
            public function json(string $path, array $value): void
            {
                if (basename($path) === 'summary.json') {
                    $value['matched'] = 999;
                }
                parent::json($path, $value);
            }

            public function destination(string $path, array $sources = []): string
            {
                Requests::safe($path);
                if (! str_starts_with($path, '/home/shinya/Desktop/composition-result-temporary-') || file_exists($path)) {
                    throw new RuntimeException('Synthetic output refused.');
                }

                return $path;
            }
        };
        $this->reject(fn () => $f->execute($f->service($publication)), 'hash/size');
        $this->assertDirectoryDoesNotExist($f->output.'/evaluations/synthetic-01');
    }

    public function test_end_source_drift_rejects_publication_without_repair(): void
    {
        $f = $this->fixture = new Fixture;
        $sources = new class(app(Requests::class), app(Matcher::class), $f->labels, Files::json($f->labels.'.manifest.json'), [7000]) extends Sources
        {
            public function end(array $source): void
            {
                file_put_contents($this->labels, "\n", FILE_APPEND);
                parent::end($source);
            }
        };
        $this->reject(fn () => $f->execute($f->service(sources: $sources)), 'hash/size');
        $this->assertDirectoryDoesNotExist($f->output.'/evaluations/synthetic-01');
    }

    public function test_source_and_repository_output_overlap_is_refused_before_creation(): void
    {
        $f = $this->fixture = new Fixture;
        foreach ([$f->requestRoot, $f->labels, base_path(), '.'] as $root) {
            $this->reject(fn () => $f->service()->execute($root, 'synthetic-01', $f->requestRoot, $f->selection));
        }
        $this->assertDirectoryDoesNotExist($f->output);
    }

    public function test_independent_128m_process_streams_more_than_100mib_and_executes_matching(): void
    {
        $f = $this->fixture = new Fixture;
        $label = $f->labelRows[0];
        $f->labels = $f->root.'/label-source/large-labels.jsonl';
        $seal = Jsonl::write($f->labels, (function () use ($label): \Generator {
            yield $label;
            for ($i = 0; $i < 14000; $i++) {
                yield ['year' => 2025, 'race_id' => 100000 + $i, 'entries' => [], 'unused' => str_repeat('x', 8192)];
            }
        })());
        $this->assertGreaterThan(100 * 1024 * 1024, $seal['bytes']);
        $process = $this->worker($f, 'execute');
        $process->mustRun();
        $data = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('CREATED', $data['status']);
        $this->assertSame(1, $data['summary']['matched']);
        $this->assertSame(0, $process->getExitCode());
    }

    public function test_fixture_exception_and_normal_completion_retire_only_the_recorded_object(): void
    {
        $f = new Fixture;
        $recorded = $f->temporary->path();
        try {
            throw new RuntimeException('Synthetic failure.');
        } catch (RuntimeException) {
            $record = $f->retire('TEST_FAILURE');
        }
        $this->assertSame($recorded, $record['source']);
        $this->assertSame('MOVED', $record['state']);
        $this->assertFileExists($record['destination'].'/CREATED.json');
        $other = new Fixture;
        $record = $other->retire();
        $this->assertSame($other->temporary->path(), $record['source']);
        $this->assertFileExists($record['destination'].'/selection.json');
    }

    public function test_plan_never_resolves_sources_or_creates_output(): void
    {
        $app = app();
        $app->bind(Service::class,
            fn () => throw new RuntimeException('Plan must not resolve the service.'));
        $command = new CompositionPredictionResultCommand;
        $command->setLaravel($app);
        $console = new Application;
        $console->setAutoExit(false);
        $console->addCommand($command);
        $output = new BufferedOutput;
        $exit = $console->run(new ArrayInput([
            'command' => 'keirin:c1:composition-result', 'mode' => 'plan',
        ]), $output);
        $this->assertSame(0, $exit);
        $data = json_decode(trim($output->fetch()), true, flags: JSON_THROW_ON_ERROR);
        unset($data['peak_memory_bytes']);
        $this->assertSame(Contract::plan(), $data);
    }

    public function test_postcommit_exception_cannot_relabel_a_completed_result_as_failed(): void
    {
        $f = $this->fixture = new Fixture;
        $publication = new class extends Publication
        {
            public function destination(string $path, array $sources = []): string
            {
                Requests::safe($path);
                if (! str_starts_with($path, '/home/shinya/Desktop/composition-result-temporary-') || file_exists($path)) {
                    throw new RuntimeException('Synthetic output refused.');
                }

                return $path;
            }

            public function commit(string $stage, string $destination): void
            {
                parent::commit($stage, $destination);
                throw new RuntimeException('Synthetic postcommit logging failure.');
            }
        };
        $result = $f->execute($f->service($publication));
        $this->assertSame('CREATED', $result['status']);
        $this->assertStringContainsString('postcommit', $result['postcommit_warning']);
        $this->assertFileExists($result['path'].'/COMPLETE.json');
        $this->assertFileDoesNotExist($result['path'].'/FAILED.json');
        $this->assertSame('SAVED', $f->service()->show($f->output, 'synthetic-01')['status']);
    }

    public function test_saved_reads_reject_unfinished_or_corrupt_bundles_without_reexecution(): void
    {
        $f = $this->fixture = new Fixture;
        $created = $f->execute();
        file_put_contents($created['path'].'/summary.json', "\n", FILE_APPEND);
        $before = $this->tree($created['path']);
        $this->reject(fn () => $f->service()->show($f->output, 'synthetic-01'), 'hash/size');
        $this->reject(fn () => $f->execute(), 'hash/size');
        $this->assertSame($before, $this->tree($created['path']));
        mkdir($f->output.'/evaluations/unfinished-01', 0700);
        $this->reject(fn () => $f->service()->show($f->output, 'unfinished-01'));
    }

    private function labels(Fixture $f, array $rows): void
    {
        $f->labels = $f->root.'/label-source/labels-'.bin2hex(random_bytes(4)).'.jsonl';
        Jsonl::write($f->labels, $rows);
    }

    private function overwriteSynthetic(string $path, array $value): void
    {
        $this->assertStringStartsWith($this->fixture->root.'/', $path);
        file_put_contents($path, json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES)."\n");
    }

    private function worker(Fixture $f, string $mode, bool $offline = false, bool $hold = false): Process
    {
        $args = $f->root.'/worker-'.bin2hex(random_bytes(4)).'.json';
        Jsonl::json($args, ['root' => $f->root, 'labels' => $offline ? '/inaccessible/labels.jsonl' : $f->labels,
            'seal' => Files::json($f->labels.'.manifest.json'), 'races' => array_column($f->targets, 'race_id'),
            'output' => $f->output, 'request_root' => $offline ? '/inaccessible/requests' : $f->requestRoot,
            'selection' => $offline ? '/inaccessible/selection.json' : $f->selection, 'mode' => $mode, 'hold_lock' => $hold]);
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=128M', base_path('tests/Support/CompositionResultWorker.php'), $args]);
        $process->setTimeout(60);

        return $process;
    }

    private function reject(callable $call, ?string $message = null): void
    {
        try {
            $call();
        } catch (\Throwable $error) {
            if ($message !== null) {
                $this->assertStringContainsString($message, $error->getMessage());
            } else {
                $this->assertNotSame('', $error->getMessage());
            }

            return;
        }
        $this->fail('Invalid operation was accepted.');
    }

    private function tree(string $root): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                $files[substr($file->getPathname(), strlen($root) + 1)] = Files::identity($file->getPathname());
            }
        }
        ksort($files, SORT_STRING);

        return $files;
    }
}
