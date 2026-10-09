<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal;

use App\Domain\Keirin\Backtest\Calculators\Bt03e03OneSeSelector;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35P1Composition\Experiment as Inventory;
use App\Domain\Keirin\Backtest\Experiments\Stat35C1Comparison\ModelLoader as C2Loader;
use App\Domain\Keirin\Backtest\Experiments\Stat35C1Comparison\Sources as OriginalSources;
use App\Domain\Keirin\Backtest\Experiments\Stat35C1Comparison\Trainer;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact as Jsonl;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\ModelLoader as C1Loader;
use PDO;
use RuntimeException;
use Throwable;

final class Experiment
{
    public function __construct(private readonly Sources $sources, private readonly Reuse $reuse, private readonly PathTrainer $paths,
        private readonly Trainer $trainer, private readonly Bt03e03OneSeSelector $selector, private readonly C1Loader $c1,
        private readonly C2Loader $c2, private readonly Forward $forward, private readonly Package $packages, private readonly Standalone $standalone) {}

    public function execute(string $output, ?array $synthetic = null, ?callable $beforePublication = null): array
    {
        $source = $synthetic ?? $this->sources->open();
        if (($synthetic !== null || $beforePublication !== null) && (! app()->environment('testing') || ! str_starts_with($output, sys_get_temp_dir().'/'))) {
            throw new RuntimeException('Synthetic final-fit injection is testing-only.');
        }
        $parent = realpath(dirname($output));
        if ($parent === false || file_exists($output) || is_link($output) || str_starts_with($parent.'/', base_path().'/')) {
            throw new RuntimeException('Final-fit output must be new outside the repository.');
        }
        foreach ($source['seals'] as $path => $seal) {
            if (str_starts_with($path, $output.'/') || str_starts_with($parent.'/', dirname($path).'/')) {
                throw new RuntimeException('Final-fit source/output overlap.');
            }
        }
        $destination = $parent.'/'.basename($output);
        $root = realpath(Contract::ROOT);
        if ((! app()->environment('testing') || ! str_starts_with($destination, sys_get_temp_dir().'/'))
            && ($root === false || ! str_starts_with($destination, $root.'/'))) {
            throw new RuntimeException('Final-fit output outside the agreed persistent root.');
        }
        OriginalSources::verify($source);
        $code = Contract::code();
        $output = $destination.'.inprogress-'.bin2hex(random_bytes(8));
        Files::directory($output);
        Jsonl::json($output.'/frozen.json', ['source' => $source, 'code' => $code, 'contract' => Contract::plan()]);
        $spools = [];
        try {
            $common = Files::directory($output.'/verified-inputs');
            $data = new TrainingData;
            $counts = [];
            $db = new PDO('sqlite:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $db->exec('PRAGMA cache_size=-1024');
            $db->exec('CREATE TABLE seen(kind TEXT,id INTEGER,PRIMARY KEY(kind,id))');
            $insert = $db->prepare('INSERT INTO seen VALUES (?,?)');
            foreach ([2022, 2023, 2024, 2025] as $year) {
                $counts[$year] = ['races' => 0, 'entries' => 0];
                $rows = (function () use ($data, $source, $year, $insert, &$counts): \Generator {
                    foreach ($data->features($source, $year) as $race) {
                        $insert->execute(['race', $race['race_id']]);
                        $counts[$year]['races']++;
                        foreach ($race['entries'] as $entry) {
                            $insert->execute(['entry', $entry['id']]);
                            $counts[$year]['entries']++;
                        }
                        yield $race;
                    }
                })();
                if ($year >= 2024) {
                    Input::write($common.'/features-'.$year.'.jsonl', $rows);
                } else {
                    foreach ($rows as $_) {
                    }
                }
                if ($year < 2025) {
                    Jsonl::write($common.'/teacher-'.$year.'.jsonl', $data->labelled($source, $year));
                }
            }
            $training = array_map(static fn ($y) => $common.'/teacher-'.$y.'.jsonl', [2022, 2023, 2024]);
            $inputSeals = Inventory::semanticFiles($common);
            Jsonl::json($output.'/cohorts.json', $counts);
            $this->outer($source, $common, $output);
            foreach ([2023 => [2022], 2024 => [2022, 2023]] as $year => $years) {
                echo json_encode(['phase' => 'VERIFY_REUSED_C2_FOLD', 'year' => $year])."\n";
                $spools[$year] = $this->reuse->restore($source['reused_folds'][$year],
                    TrainingData::training(array_map(static fn ($y) => $common.'/teacher-'.$y.'.jsonl', $years)),
                    TrainingData::training([$common.'/teacher-'.$year.'.jsonl']), Files::directory($output.'/reuse-'.$year));
            }
            $k = array_values(array_intersect($spools[2023]->availableLambdaKeys(), $spools[2024]->availableLambdaKeys()));
            $prefix = PathTrainer::prefix($k);
            Jsonl::json($output.'/reused-eligibility.json', ['common_keys' => $k, 'OOF3_prefix' => $prefix]);
            $results = [];
            foreach (['run-01', 'run-02'] as $run) {
                echo json_encode(['phase' => 'INDEPENDENT_FINAL_C2_RUN', 'run' => $run])."\n";
                $dir = Files::directory($output.'/'.$run);
                $oof = Files::directory($dir.'/oof-3');
                $path = $this->paths->train(TrainingData::training($training), $k, $oof);
                $candidateSeals = [];
                foreach (['path.json', 'layout.json', 'training.jsonl'] as $name) {
                    $candidateSeals[$name] = Files::identity($oof.'/'.$name);
                }
                Jsonl::json($oof.'/candidate-seals.json', $candidateSeals);
                $runData = new TrainingData;
                $runData->release2025($oof);
                $teacherSeal = Jsonl::write($dir.'/teacher-2025.jsonl', $runData->labelled($source, 2025));
                $third = $this->reuse->losses(TrainingData::training([$dir.'/teacher-2025.jsonl']), $path, $oof);
                $selected = $this->selector->select($spools + [2025 => $third]);
                Jsonl::json($dir.'/selection.json', $selected);
                $final = Files::directory($dir.'/final');
                echo json_encode(['phase' => 'FINAL_SELECTED_PATH', 'run' => $run, 'lambda' => $selected['lambda']])."\n";
                $prediction = static function () use ($common): \Generator {
                    foreach (Input::read($common.'/features-2025.jsonl') as $race) {
                        yield Input::modelRace($race);
                    }
                };
                $fit = $this->trainer->refit(TrainingData::training([...$training, $dir.'/teacher-2025.jsonl']), $prediction, $selected['lambda'], $final);
                $loaded = $this->c2->load($final.'/model.json', Files::identity($final.'/model.json'));
                Files::same($fit['model'], $loaded->artifact, 'final C2 before/after model save');
                $after = Jsonl::write($final.'/loaded-predictions.jsonl', $this->c2->predictions($prediction, $loaded));
                Files::same($fit['predictions'], $after, 'final C2 predictions before/after save');
                $c1 = $this->c1->published($source['c1_artifact']);
                $compose = fn () => (function () use ($common, $c1, $loaded): \Generator {
                    foreach (Input::read($common.'/features-2025.jsonl') as $race) {
                        yield $this->forward->predict($race, $c1, $loaded);
                    }
                })();
                $before = Jsonl::write($dir.'/composition-before-package.jsonl', $compose());
                $artifact = $this->packages->stage($source['c1_artifact'], $final, $selected, $dir.'/package',
                    ['c1_model_sha256' => Files::identity(dirname($source['c1_artifact']).'/model.json')['sha256'],
                        'reference_manifest_sha256' => Contract::REFERENCE_SHA, 'training_years' => [2022, 2023, 2024, 2025],
                        'generation' => Contract::VERSION, 'source_seals' => array_values($source['seals'])]);
                $package = $this->packages->load($artifact, false);
                $after = Jsonl::write($dir.'/composition-loaded.jsonl', (function () use ($common, $package): \Generator {
                    foreach (Input::read($common.'/features-2025.jsonl') as $race) {
                        yield $this->forward->predict($race, $package['c1'], $package['c2']);
                    }
                })());
                Files::same($before, $after, 'composition package roundtrip');
                Files::directory($dir.'/standalone');
                $isolated = $this->standalone->verify($artifact, $common.'/features-2025.jsonl', $dir.'/standalone/predictions.jsonl');
                if (! rename($dir.'/standalone/predictions.jsonl.process.json', $output.'/'.$run.'-standalone-process.json')) {
                    throw new RuntimeException('Could not preserve isolated process audit.');
                }
                Files::same($before, $isolated['seal'], 'package/input-only separate process');
                Jsonl::json($output.'/'.$run.'-standalone-audit.json', $isolated);
                Jsonl::json($dir.'/teacher-access.json', $runData->events);
                $results[$run] = ['selection' => $selected, 'final_model' => Files::identity($final.'/model.json'),
                    'layout' => $loaded->artifact['layout'], 'diagnostics' => $loaded->fit->diagnostics,
                    'predictions' => $before, 'OOF3_fit_order' => $path['fit_order'],
                    'final_fit_order' => Files::json($final.'/refit-path.json')['fit_order']];
                $third->cleanup();
                Files::verify($dir.'/teacher-2025.jsonl', $teacherSeal);
                unset($path, $fit, $loaded, $package, $third);
                Files::same($code, Contract::code(), 'between independent runs code');
            }
            foreach ($spools as $spool) {
                $spool->cleanup();
            }
            Files::same($results['run-01'], $results['run-02'], 'independent final fits');
            $first = Inventory::semanticFiles($output.'/run-01');
            $second = Inventory::semanticFiles($output.'/run-02');
            Files::same($first, $second, 'independent enumerated semantic artifacts');
            Jsonl::json($output.'/reproduction.json', ['identical' => true, 'semantic_file_count' => count($first), 'files' => $first]);
            $generationSeals = Inventory::semanticFiles($output);
            if ($beforePublication !== null) {
                $beforePublication($output);
            }
            OriginalSources::verify($source);
            Files::same($code, Contract::code(), 'final-fit source/code END');
            foreach ($inputSeals as $name => $seal) {
                Files::verify($common.'/'.$name, $seal);
            }
            foreach ($generationSeals as $name => $seal) {
                Files::verify($output.'/'.$name, $seal);
            }
            // Recheck the generation-time seals, not freshly generated seals of potentially drifted content.
            foreach ($first as $name => $seal) {
                Files::verify($output.'/run-01/'.$name, $seal);
                Files::verify($output.'/run-02/'.$name, $seal);
            }
            foreach (['run-01', 'run-02'] as $run) {
                $this->packages->publish($output.'/'.$run.'/package/artifact.json');
            }
            $result = ['status' => 'FINAL_COMPOSITION_FIT_REPRODUCED_AWAITING_REVIEW', 'contract' => Contract::plan(),
                'cohorts' => $counts, 'result' => $results['run-01'], 'semantic_file_count' => count($first),
                'new_fit_paths' => 4, 'candidate_attempts' => 2 * (count($results['run-01']['OOF3_fit_order']) + count($results['run-01']['final_fit_order'])),
                'reused_folds' => [2023, 2024], 'c1_retraining_count' => 0,
                'package' => $destination.'/run-01/package/artifact.json', 'peak_memory_bytes' => memory_get_peak_usage(true)];
            Jsonl::json($output.'/result.json', $result);
            $files = Inventory::semanticFiles($output);
            OriginalSources::verify($source);
            Files::same($code, Contract::code(), 'prepublication execution code');
            foreach ($files as $name => $seal) {
                Files::verify($output.'/'.$name, $seal);
            }
            Jsonl::json($output.'/manifest.json', ['status' => $result['status'], 'contract' => Contract::plan(), 'source' => $source, 'code' => $code, 'files' => $files]);
            Jsonl::json($output.'/COMPLETE.json', Files::identity($output.'/manifest.json'));
            if (file_exists($destination) || is_link($destination) || ! rename($output, $destination)) {
                throw new RuntimeException('Could not atomically publish verified final-fit directory.');
            }

            return $result;
        } catch (Throwable $e) {
            Jsonl::json($output.'/FAILED.json', ['status' => 'FAILED_NOT_PUBLISHED', 'exception' => $e::class, 'error' => $e->getMessage(), 'performance' => null]);
            throw $e;
        }
    }

    private function outer(array $source, string $inputs, string $output): void
    {
        $counts = [];
        foreach ([2024, 2025] as $year) {
            $c1 = $this->c1->load($source['paths'][$year]['model'], $source['seals'][$source['paths'][$year]['model']]);
            $c2 = $this->c2->load($source['outer_c2'][$year], $source['seals'][$source['outer_c2'][$year]]);
            $saved = Jsonl::read($source['reference'].'/run-01/predictions-'.$year.'.jsonl');
            $saved->rewind();
            $counts[$year] = 0;
            foreach (Input::read($inputs.'/features-'.$year.'.jsonl') as $race) {
                if (! $saved->valid()) {
                    throw new RuntimeException('PR92 Outer reference incomplete.');
                }
                $actual = $this->forward->predict($race, $c1, $c2);
                Files::same($saved->current()['probabilities'], $actual['probabilities'], 'PR92 feature-derived composition math');
                $decision = $saved->current()['candidate'];
                foreach (['prediction_origin', 'utility_forward_controls_verified', 'calculation_version'] as $audit) {
                    unset($decision[$audit]);
                }
                $decoded = $actual['decision'];
                unset($decision['reconstruction_verified'], $decoded['reconstruction_verified']);
                Files::same($decision, $decoded, 'PR92 Primary and Supporting decoder');
                $counts[$year]++;
                $saved->next();
            }
            if ($saved->valid()) {
                throw new RuntimeException('PR92 Outer reference extra rows.');
            }
        }
        Jsonl::json($output.'/outer-verification.json', ['role' => 'OUTER_REPLAY_VERIFICATION', 'exact' => true, 'counts' => $counts, 'performance_evaluation' => 'NOT_PERFORMED']);
    }
}
