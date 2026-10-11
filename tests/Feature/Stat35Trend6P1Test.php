<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Keirin\Backtest\Calculators\Bt03e05MetricEvaluator;
use App\Domain\Keirin\Backtest\Contracts\EffectBinBoundaryProvider;
use App\Domain\Keirin\Backtest\Experiments\Stat35Trend6P1\Contract;
use App\Domain\Keirin\Backtest\Experiments\Stat35Trend6P1\Dataset;
use App\Domain\Keirin\Backtest\Experiments\Stat35Trend6P1\Evaluation;
use App\Domain\Keirin\Backtest\Experiments\Stat35Trend6P1\Experiment;
use App\Domain\Keirin\Backtest\Experiments\Stat35Trend6P1\InputBuilder;
use App\Domain\Keirin\Backtest\Experiments\Stat35Trend6P1\MetricContributionSpool;
use App\Domain\Keirin\Backtest\Experiments\Stat35Trend6P1\ModelLoader;
use App\Domain\Keirin\Backtest\Experiments\Stat35Trend6P1\OneSeSelector;
use App\Domain\Keirin\Backtest\Experiments\Stat35Trend6P1\PairedBootstrap;
use App\Domain\Keirin\Backtest\Experiments\Stat35Trend6P1\RetainedBinBoundaryProvider;
use App\Domain\Keirin\Backtest\Experiments\Stat35Trend6P1\Sources;
use App\Domain\Keirin\Backtest\Experiments\Stat35Trend6P1\Trainer;
use App\Domain\Keirin\Backtest\Experiments\Stat35Trend6P1\Trend;
use App\Domain\Keirin\Backtest\Experiments\Stat35Trend6P1\ValidationLossSpool;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Statistics\AgariC1Input\Index;
use App\Domain\Keirin\Statistics\AgariPlayerHistory\Exact;
use App\Domain\Keirin\Statistics\AgariPlayerHistory\History;
use App\Domain\Keirin\Statistics\AgariRaceRelative\Artifacts;
use Brick\Math\BigRational;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\CompositionResultTemporaryDirectory;
use Tests\Support\Stat35C1ComparisonFixture;
use Tests\TestCase;

class Stat35Trend6P1Test extends TestCase
{
    private CompositionResultTemporaryDirectory $temporary;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporary = CompositionResultTemporaryDirectory::create();
        $this->app->instance(EffectBinBoundaryProvider::class, new RetainedBinBoundaryProvider($this->temporary->path()));
    }

    protected function tearDown(): void
    {
        $this->temporary->retire($this->temporary->path(), 'TEST_COMPLETE');
        parent::tearDown();
    }

    #[DataProvider('trends')]
    public function test_exact_six_slot_trend_and_unchanged_mean6(array $values, ?float $expected, ?string $reason): void
    {
        [$audit, $history] = self::audit($values);
        $r = Trend::calculate($audit, $history);
        $this->assertSame($expected, $r['stat35_trend6']);
        $this->assertSame($reason, $r['null_reason']);
        $this->assertSame($audit['float'], $r['stat35_mean6']);
        $this->assertSame($audit['window']['meetings'], $r['selected_meetings']);
    }

    public static function trends(): array
    {
        return [
            'positive' => [[9, 8, 7, 3, 2, 1], 0.6, null],
            'negative' => [[1, 2, 3, 7, 8, 9], -0.6, null],
            'zero observed' => [[5, 5, 5, 5, 5, 5], 0.0, null],
            'not six' => [[9, 8, 7, 3, 2], null, 'INSUFFICIENT_OBSERVED_MEETINGS'],
            'null consumes a slot' => [[9, 8, null, 3, 2, 1, 10], null, 'MISSING_MEETING_VALUES'],
        ];
    }

    public function test_target_and_future_meetings_do_not_enter_window_and_overlap_blocks(): void
    {
        [$a, $history] = self::audit([9, 8, 7, 3, 2, 1]);
        $extra = $history[0];
        $extra['meeting']['ends_on'] = '2024-01-01';
        $extra['meeting']['meeting_id'] = 99;
        $this->assertSame(0.6, Trend::calculate($a, [$extra, ...$history])['stat35_trend6']);
        $extra['meeting']['ends_on'] = '2023-01-01';
        $extra['meeting']['meeting_id'] = $a['context']['target']['meeting_id'];
        $this->assertSame(0.6, Trend::calculate($a, [$extra, ...$history])['stat35_trend6']);
        $history[1]['meeting']['ends_on'] = $history[0]['meeting']['starts_on'];
        $a = self::auditFor($history);
        $result = Trend::calculate($a, $history);
        $this->assertNull($result['stat35_trend6']);
        $this->assertSame('BLOCKED_CONTEXT_OR_ORDER', $result['null_reason']);
    }

    #[DataProvider('mismatchedEvidence')]
    public function test_unaccepted_history_identity_class_definition_fails_closed(string $field, mixed $value): void
    {
        [$a, $history] = self::audit([9, 8, 7, 3, 2, 1]);
        $history[0][$field] = $value;
        $this->expectExceptionMessage('INPUT02 observed window unchanged');
        Trend::calculate($a, $history);
    }

    public static function mismatchedEvidence(): array
    {
        return [['external_player_id', '000002'], ['race_class', 'S_CLASS'], ['measurement_definition_id', 'unknown'], ['source', 'other']];
    }

    public function test_eighteen_features_nulls_cohort_and_teacher_guards(): void
    {
        $source = $this->source();
        $dataset = new Dataset;
        $rows = iterator_to_array($dataset->prediction($source, 2024));
        $this->assertCount(5, $rows);
        $this->assertCount(5, $rows[0]['entries']);
        $this->assertSame(['STAT35_MEAN6', 'STAT35_TREND6'], array_slice(Contract::features(), 16));
        $this->assertCount(18, $rows[0]['entries'][0]['signals']);
        $this->assertSame(0.0, $rows[0]['entries'][0]['signals'][16]);
        $this->assertSame(0.0, $rows[0]['entries'][0]['signals'][17]);
        $this->assertNull($rows[0]['entries'][4]['signals'][17]);
        $this->assertArrayNotHasKey('rank', $rows[0]['entries'][0]);
        $this->expectExceptionMessage('before both prediction seals');
        iterator_to_array($dataset->labelled($source, 2024));
    }

    public function test_sealed_audit_history_reconstruction_preserves_every_entry_and_mean(): void
    {
        $source = $this->source();
        $source['history'] = Files::directory($this->temporary->path().'/history-input');
        [$a, $history] = self::audit([5, 5, 5, 5, 5, 5]);
        $meetings = [];
        foreach ([2022, 2023, 2024, 2025] as $year) {
            $audits = $means = [];
            foreach (JsonlArtifact::read($source['paths'][$year]['input']) as $race) {
                $entries = [];
                foreach ($race['entries'] as $entry) {
                    $h = $history;
                    foreach ($h as $i => &$row) {
                        $row['id'] = $year.'-'.$entry['id'].'-'.$i;
                        $row['external_player_id'] = sprintf('%06d', $entry['id'] % 1000000);
                        $row['meeting']['meeting_id'] = $year * 100 + $i;
                        $row['meeting']['starts_on'] = ($year - 1).substr($row['meeting']['starts_on'], 4);
                        $row['meeting']['ends_on'] = ($year - 1).substr($row['meeting']['ends_on'], 4);
                        $row['first_observed_date'] = $year.'-01-01';
                        $meetings[] = $row;
                    }
                    unset($row);
                    $a = self::auditFor($h);
                    $a['context']['target'] = ['race_id' => $race['race_id'], 'entry_id' => $entry['id'], 'bike' => $entry['bike'],
                        'race_date' => $year.'-01-01', 'meeting_id' => 100, 'meeting_start' => $year.'-01-01', 'meeting_end' => $year.'-01-03'];
                    $a['context']['evidence']['external_player_id'] = $h[0]['external_player_id'];
                    $a['window'] = History::calculate($h, $year.'-01-01', '2022-01-01')['windows'][6];
                    unset($a['window']['median'], $a['window']['population_variance']);
                    $audits[] = ['year' => $year, 'race_id' => $race['race_id'], 'entry_id' => $entry['id'], 'bike' => $entry['bike'], ...$a];
                    $entries[] = ['id' => $entry['id'], 'bike' => $entry['bike'], 'stat35_mean6' => 0.5];
                }
                $means[] = ['year' => $year, 'race_id' => $race['race_id'], 'entries' => $entries];
            }
            $path = $this->temporary->path().'/audit-'.$year.'.jsonl';
            Artifacts::write(dirname($path), basename($path), array_map(fn ($row) => Files::canonical($row)."\n", $audits));
            $source['paths'][$year]['audit'] = $path;
            $source['seals'][$path] = Files::identity($path);
            $path = $this->temporary->path().'/means-'.$year.'.jsonl';
            Artifacts::write(dirname($path), basename($path), array_map(fn ($row) => Files::canonical($row)."\n", $means));
            $source['paths'][$year]['sidecar'] = $path;
            $source['seals'][$path] = Files::identity($path);
        }
        $path = $source['history'].'/player-meetings.jsonl';
        Artifacts::write(dirname($path), basename($path), array_map(fn ($row) => Files::canonical($row)."\n", $meetings));
        $this->assertFileDoesNotExist($path.'.manifest.json');
        $source['seals'][$path] = Files::identity($path);
        $directory = Files::directory($this->temporary->path().'/prepared');
        $prepared = (new InputBuilder)->build($source, $directory);
        foreach ([2022, 2023, 2024, 2025] as $year) {
            $rows = iterator_to_array((new Dataset)->prediction($prepared, $year));
            $this->assertCount(5, $rows);
            $this->assertCount(5, $rows[0]['entries']);
            $this->assertSame([0.5, 0.0], array_slice($rows[0]['entries'][0]['signals'], 16));
        }
    }

    public function test_frozen_selection_and_bootstrap_keep_their_spools_after_destruction(): void
    {
        $validation = $metrics = $seals = [];
        foreach ([2024, 2025] as $year) {
            $path = $this->temporary->path().'/validation-'.$year.'.bin';
            $validation[$year] = new ValidationLossSpool($path, [ValidationLossSpool::lambdaKey(1.0), ValidationLossSpool::lambdaKey(0.1)]);
            foreach (range(1, 3) as $_) {
                $validation[$year]->append([ValidationLossSpool::lambdaKey(1.0) => array_fill_keys(['POSITION_1', 'POSITION_2', 'POSITION_3'], 1.5),
                    ValidationLossSpool::lambdaKey(0.1) => array_fill_keys(['POSITION_1', 'POSITION_2', 'POSITION_3'], 1.0)]);
            }
            $validation[$year]->seal();
            $seals[$path] = Files::identity($path);
            $path = $this->temporary->path().'/metrics-'.$year.'.bin';
            $metrics[$year] = new MetricContributionSpool($path);
            $row = ['candidate' => [], 'baseline' => []];
            foreach (Bt03e05MetricEvaluator::METRIC_CODES as $code) {
                $row['candidate'][$code] = ['numerator' => $year === 2024 ? 0.25 : 0.75, 'denominator' => 1.0];
                $row['baseline'][$code] = ['numerator' => 0.0, 'denominator' => 1.0];
            }
            foreach (range(1, 3) as $_) {
                $metrics[$year]->append($row);
            }
            $metrics[$year]->seal();
            $seals[$path] = Files::identity($path);
        }
        $selection = $this->app->make(OneSeSelector::class)->select($validation);
        $this->assertSame(0.1, $selection['lambda']);
        $this->assertSame(1.0, $selection['one_se_threshold']);
        $bootstrap = $this->app->make(PairedBootstrap::class);
        $intervals = $bootstrap->evaluate($metrics);
        $this->assertSame($intervals, $bootstrap->evaluate($metrics));
        foreach ($intervals as $interval) {
            $this->assertSame(['ci_lower' => 0.5, 'ci_upper' => 0.5], $interval);
        }
        unset($validation, $metrics);
        foreach ($seals as $path => $seal) {
            $this->assertSame($seal, Files::identity($path));
        }
    }

    public function test_outer_composition_accepts_inputs_sealed_only_by_the_parent_manifest(): void
    {
        $source = $this->source();
        foreach ([2022, 2023, 2024, 2025] as $year) {
            $path = $this->temporary->path().'/parent-only-c1-'.$year.'.jsonl';
            Artifacts::write(dirname($path), basename($path), (function () use ($source, $year): \Generator {
                foreach (JsonlArtifact::read($source['paths'][$year]['input']) as $row) {
                    yield Files::canonical($row)."\n";
                }
            })());
            $this->assertFileDoesNotExist($path.'.manifest.json');
            $source['paths'][$year]['input'] = $path;
            $source['seals'][$path] = Files::identity($path);
        }
        $directory = Files::directory($this->temporary->path().'/parent-only-outer');
        $result = $this->app->make(Experiment::class)->run($source, $directory);
        foreach ([2024, 2025] as $year) {
            $this->assertSame(5, $result['changes']['C-B'][$year]['races']);
            $this->assertFileExists($directory.'/predictions-'.$year.'.jsonl');
        }
    }

    #[DataProvider('sourceTampering')]
    public function test_pinned_source_parent_keys_and_manifest_seals(string $tamper): void
    {
        $bundle = Stat35C1ComparisonFixture::make($this->temporary->path().'/sealed');
        $history = Files::directory($this->temporary->path().'/history');
        JsonlArtifact::write($history.'/player-meetings.jsonl', []);
        JsonlArtifact::json($history.'/manifest.json', ['files' => ['player-meetings.jsonl' => Files::identity($history.'/player-meetings.jsonl')]]);
        JsonlArtifact::json($history.'/COMPLETE.json', Files::identity($history.'/manifest.json'));
        $input = Files::json($bundle['input'].'/manifest.json');
        $input['source']['history'] = $history;
        foreach (['manifest.json', 'COMPLETE.json', 'player-meetings.jsonl'] as $name) {
            $input['source']['seals'][$history.'/'.$name] = Files::identity($history.'/'.$name);
        }
        foreach ([2022, 2023, 2024, 2025] as $year) {
            $path = $bundle['input'].'/audit-'.$year.'.jsonl';
            JsonlArtifact::write($path, []);
            $input['files'][basename($path)] = Files::identity($path);
        }
        file_put_contents($bundle['input'].'/manifest.json', Files::canonical($input)."\n");
        file_put_contents($bundle['input'].'/COMPLETE.json', Files::canonical(Files::identity($bundle['input'].'/manifest.json'))."\n");
        $previous = new \App\Domain\Keirin\Backtest\Experiments\Stat35C1Comparison\Sources(
            hash_file('sha256', $bundle['input'].'/manifest.json'), hash_file('sha256', $bundle['baseline'].'/report-export-manifest.json'),
            hash_file('sha256', $bundle['baseline'].'/frozen-experiment-contract.json'));
        $source = $previous->open($bundle['input'], $bundle['baseline']);
        $composition = Files::directory($this->temporary->path().'/saved-B');
        Files::directory($composition.'/run-01');
        $manifest = ['contract' => \App\Domain\Keirin\Backtest\Experiments\C1Stat35P1Composition\Contract::plan(),
            'code' => ['synthetic_fixture' => true], 'files' => [], 'source' => ['paths' => [], 'seals' => [], 'outcome_seals' => []]];
        foreach ([2024, 2025] as $year) {
            $path = $composition.'/run-01/predictions-'.$year.'.jsonl';
            JsonlArtifact::write($path, []);
            $manifest['files']['run-01/'.basename($path)] = Files::identity($path);
            foreach (['input' => 'input', 'baseline' => 'prediction', 'teacher' => 'labels', 'model' => 'model'] as $old => $key) {
                $p = $source['paths'][$year][$old];
                $manifest['source']['paths'][$year][$key] = $p;
                $manifest['source'][$old === 'teacher' ? 'outcome_seals' : 'seals'][$p] = $source['seals'][$p];
            }
        }
        if ($tamper === 'parent') {
            $p = $source['paths'][2024]['model'];
            $manifest['source']['seals'][$p]['sha256'] = str_repeat('0', 64);
        }
        JsonlArtifact::json($composition.'/manifest.json', $manifest);
        JsonlArtifact::json($composition.'/COMPLETE.json', Files::identity($composition.'/manifest.json'));
        $sources = new Sources($previous, hash_file('sha256', $composition.'/manifest.json'));
        if ($tamper === 'manifest') {
            file_put_contents($composition.'/manifest.json', 'x', FILE_APPEND);
        } elseif ($tamper === 'audit') {
            file_put_contents($bundle['input'].'/audit-2023.jsonl', 'x', FILE_APPEND);
        }
        if ($tamper !== 'none') {
            $this->expectException(\RuntimeException::class);
        }
        $opened = $sources->open($bundle['input'], $bundle['baseline'], $composition);
        $this->assertSame($source['seals'][$source['paths'][2024]['model']], $opened['seals'][$opened['paths'][2024]['model']]);
        $this->assertSame($history, $opened['history']);
    }

    public static function sourceTampering(): array
    {
        return [['none'], ['parent'], ['manifest'], ['audit']];
    }

    #[DataProvider('badInput')]
    public function test_prediction_rejects_alignment_result_and_2026(string $kind): void
    {
        $source = $this->source();
        $path = $source['paths'][2024][$kind === 'bike' ? 'trend' : 'input'];
        $rows = iterator_to_array(JsonlArtifact::read($path));
        match ($kind) {
            'rank' => $rows[0]['entries'][0]['rank'] = 1,
            'bike' => $rows[0]['entries'][0]['bike'] = 9,
            'entry' => $rows[0]['entries'][0]['id']++,
            'year' => $rows[0]['year'] = 2026,
            'duplicate' => $rows[1] = $rows[0],
        };
        file_put_contents($path, implode('', array_map(fn ($r) => Files::canonical($r)."\n", $rows)));
        $source['seals'][$path] = Files::identity($path);
        $this->expectException(\Throwable::class);
        iterator_to_array((new Dataset)->prediction($source, 2024));
    }

    public static function badInput(): array
    {
        return array_map(fn ($k) => [$k], ['rank', 'bike', 'entry', 'year', 'duplicate']);
    }

    public function test_full_training_reload_composition_and_temporal_release_reproduce(): void
    {
        $source = $this->source();
        $runs = [];
        foreach (['first', 'second'] as $name) {
            $directory = Files::directory($this->temporary->path().'/'.$name);
            $runs[] = app(Experiment::class)->run($source, $directory);
        }
        $this->assertSame($runs[0], $runs[1]);
        foreach ([2024, 2025] as $year) {
            foreach (['model.json', 'predictions.jsonl', 'selection.json'] as $name) {
                $this->assertSame(Files::identity($this->temporary->path().'/first/C3-fit-'.$year.'/'.$name),
                    Files::identity($this->temporary->path().'/second/C3-fit-'.$year.'/'.$name));
            }
            $modelPath = $this->temporary->path().'/first/C3-fit-'.$year.'/model.json';
            $model = app(ModelLoader::class)->load($modelPath, Files::identity($modelPath));
            $this->assertSame(18, $model->layout->featureCount());
            $this->assertSame(Contract::MODEL_VERSION, $model->artifact['model_version']);
        }
        $events = Files::json($this->temporary->path().'/first/teacher-access.json');
        $released = [];
        foreach ($events as $event) {
            if ($event['event'] === 'OUTER_TEACHER_RELEASED') {
                $released[] = $event['year'];
            } elseif ($event['year'] >= 2024) {
                $this->assertContains($event['year'], $released);
            }
        }
        $this->assertSame([2024, 2025], $released);
        $this->assertArrayHasKey('PRIMARY-C-B', $runs[0]['outer']);
    }

    public function test_model_and_code_drift_are_detectable(): void
    {
        $source = $this->source();
        $dataset = new Dataset;
        $directory = Files::directory($this->temporary->path().'/model');
        app(Trainer::class)->refit(fn () => $dataset->labelled($source, 2022), fn () => $dataset->prediction($source, 2024), 1.0, $directory);
        $path = $directory.'/model.json';
        $seal = Files::identity($path);
        $code = Contract::code();
        $this->assertArrayHasKey('app/Domain/Keirin/Backtest/Experiments/Stat35Trend6P1/Trend.php', $code);
        $modified = $code;
        $modified['app/Domain/Keirin/Backtest/Experiments/Stat35Trend6P1/Trend.php']['sha256'] = str_repeat('0', 64);
        try {
            Files::same($code, $modified, 'code START/END');
            $this->fail('Changed code accepted.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('code START/END', $e->getMessage());
        }
        file_put_contents($path, 'x', FILE_APPEND);
        $this->expectExceptionMessage('hash/size mismatch');
        app(ModelLoader::class)->load($path, $seal);
    }

    #[DataProvider('gateBoundaries')]
    public function test_frozen_gate_boundaries_are_not_relaxed(float $ni, float $hit, float $year, bool $pass): void
    {
        $metrics = ['WINNER_HIT_AT_1', 'POSITION_2_ACCURACY', 'POSITION_3_ACCURACY', 'POSITION_HIT_RATE_AT_3'];
        $outer = [2024 => ['delta' => array_fill_keys($metrics, $year)], 2025 => ['delta' => array_fill_keys($metrics, $year)]];
        $ci = array_fill_keys($metrics, ['ci_lower' => $ni]);
        $ci['POSITION_HIT_RATE_AT_3']['ci_lower'] = $hit;
        $gates = app(Evaluation::class)->gates(['outer' => array_fill_keys(['C-B', 'C-A', 'B-A'], $outer),
            'intervals' => array_fill_keys(['C-B', 'C-A', 'B-A'], $ci)], true);
        $this->assertSame($pass ? 'PASS_DEVELOPMENT_INCREMENTAL_EFFECT_ONLY' : 'NOT_PASSED', $gates['C-B']['status']);
        $this->assertSame($gates['C-B'], $gates['C-A']);
        $this->assertSame($gates['C-B'], $gates['B-A']);
    }

    public static function gateBoundaries(): array
    {
        return [[-0.0015, 0.01, 0.0, false], [-0.0015 + 1e-12, 0.01, 0.0, true],
            [0.0, 0.0, 0.0, false], [0.0, 1e-12, 0.0, true], [0.0, 0.01, -1e-12, false]];
    }

    public function test_changed_outer_outcomes_cannot_affect_unreleased_training_or_predictions(): void
    {
        $source = $this->source();
        $directories = [];
        foreach ([null, 2024, 2025] as $i => $changed) {
            $s = $source;
            if ($changed !== null) {
                $path = $this->temporary->path().'/changed-'.$changed.'.jsonl';
                JsonlArtifact::write($path, (function () use ($source, $changed) {
                    foreach (JsonlArtifact::read($source['paths'][$changed]['teacher']) as $row) {
                        foreach ($row['entries'] as &$entry) {
                            $entry['rank'] = $entry['bike'];
                        }
                        unset($entry);
                        yield $row;
                    }
                })());
                $s['paths'][$changed]['teacher'] = $path;
                $s['seals'][$path] = Files::identity($path);
            }
            $directories[$i] = Files::directory($this->temporary->path().'/temporal-'.$i);
            app(Experiment::class)->run($s, $directories[$i]);
        }
        foreach (['model.json', 'predictions.jsonl', 'selection.json'] as $name) {
            foreach ([1, 2] as $i) {
                $this->assertSame(Files::identity($directories[0].'/C3-fit-2024/'.$name), Files::identity($directories[$i].'/C3-fit-2024/'.$name));
            }
            $this->assertSame(Files::identity($directories[0].'/C3-fit-2025/'.$name), Files::identity($directories[2].'/C3-fit-2025/'.$name));
        }
        foreach ([1, 2] as $i) {
            $this->assertSame(Files::identity($directories[0].'/predictions-2024.jsonl'), Files::identity($directories[$i].'/predictions-2024.jsonl'));
        }
        $this->assertSame(Files::identity($directories[0].'/predictions-2025.jsonl'), Files::identity($directories[2].'/predictions-2025.jsonl'));
        $this->assertNotSame(Files::identity($directories[0].'/C3-fit-2025/model.json'), Files::identity($directories[1].'/C3-fit-2025/model.json'));
    }

    public function test_independent_128m_stream_over_one_hundred_mib(): void
    {
        $p = new Process([PHP_BINARY, '-d', 'memory_limit=128M', base_path('tests/Support/stat35-trend6-memory.php'), $this->temporary->path()], base_path());
        $p->setTimeout(120);
        $p->run();
        $this->assertSame(0, $p->getExitCode(), $p->getErrorOutput());
        $r = Files::json($this->temporary->path().'/measurement.json');
        $this->assertSame('128M', $r['limit']);
        $this->assertNotSame(getmypid(), $r['pid']);
        $this->assertSame(50000, $r['races']);
        $this->assertSame(250000, $r['entries']);
        $this->assertGreaterThan(100 * 1024 * 1024, $r['input_bytes']);
        $this->assertLessThan(128 * 1024 * 1024, $r['peak']);
    }

    private function source(): array
    {
        $bundle = Stat35C1ComparisonFixture::make($this->temporary->path().'/fixture');
        $source = $bundle['source'];
        foreach ([2022, 2023, 2024, 2025] as $year) {
            $path = $this->temporary->path().'/trend-'.$year.'.jsonl';
            JsonlArtifact::write($path, (function () use ($source, $year) {
                foreach (JsonlArtifact::read($source['paths'][$year]['sidecar']) as $race) {
                    foreach ($race['entries'] as &$entry) {
                        $entry['stat35_trend6'] = $entry['bike'] === 5 ? null : 0.0;
                    }
                    unset($entry);
                    yield $race;
                }
            })());
            $source['paths'][$year]['trend'] = $path;
            $source['seals'][$path] = Files::identity($path);
            if ($year >= 2024) {
                $path = $this->temporary->path().'/composition-'.$year.'.jsonl';
                JsonlArtifact::write($path, (function () use ($source, $year) {
                    foreach (JsonlArtifact::read($source['paths'][$year]['baseline']) as $row) {
                        yield ['year' => $year, 'race_id' => $row['probabilities']['race_id'],
                            'cohort' => array_map(fn ($e) => [$e['id'], $e['bike']], $row['probabilities']['entries']), 'candidate' => $row['decision']];
                    }
                })());
                $source['paths'][$year]['composition'] = $path;
                $source['seals'][$path] = Files::identity($path);
            }
        }

        return $source;
    }

    private static function audit(array $values): array
    {
        $history = [];
        foreach ($values as $i => $v) {
            $history[] = ['id' => (string) $i, 'source' => 'keirin_jp', 'external_player_id' => '000001', 'race_class' => 'A1_A2',
                'measurement_definition_id' => 'keirin-jp-final-back-half-lap-v1', 'history_context_eligible' => true,
                'meeting' => ['meeting_id' => $i + 1, 'starts_on' => '2023-'.sprintf('%02d', 12 - $i).'-01', 'ends_on' => '2023-'.sprintf('%02d', 12 - $i).'-03'],
                'meeting_percentile_mean' => $v === null ? null : Exact::value(BigRational::ofFraction($v, 10)),
                'context_flags' => [], 'adopted_races' => 1, 'exclusion_reasons' => []];
        }

        return [self::auditFor($history), $history];
    }

    private static function auditFor(array $history): array
    {
        $window = History::calculate($history, '2024-01-01', '2022-01-01')['windows'][6];
        unset($window['median'], $window['population_variance']);

        return ['context' => ['target' => ['meeting_start' => '2024-01-01', 'meeting_id' => 100], 'reasons' => [],
            'evidence' => ['external_player_id' => '000001'], 'class' => 'A1_A2'], 'window' => $window, 'float' => Index::number($window['mean'])];
    }
}
