<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal;

use App\Domain\Keirin\Backtest\Calculators\Bt03e03OneSeSelector;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Trainer;
use RuntimeException;
use Throwable;

final class FinalFitService
{
    public function __construct(private readonly ReuseBundle $reuse, private readonly SourceCheck $sourceCheck,
        private readonly Trainer $trainer, private readonly Bt03e03OneSeSelector $selection, private readonly ModelLoader $loader) {}

    public function run(string $source, string $output): array
    {
        $source = realpath($source) ?: throw new RuntimeException('Missing parent artifact directory.');
        $parent = realpath(dirname($output)) ?: throw new RuntimeException('Output parent must already exist.');
        $output = $parent.'/'.basename($output);
        if ($output === $source || str_starts_with($output, $source.'/') || str_starts_with($output, base_path().'/')) {
            throw new RuntimeException('Output must be outside source artifacts and the repository.');
        }
        Files::directory($output);
        $state = ['status' => 'RUNNING', 'started_at' => gmdate('c'), 'pid' => getmypid(), 'steps' => []];
        $event = function (string $step) use (&$state, $output): void {
            $state['steps'][] = ['step' => $step, 'at' => gmdate('c')];
            JsonlArtifact::json($output.'/state-next.json', $state);
            if (! rename($output.'/state-next.json', $output.'/state.json')) {
                throw new RuntimeException('Could not publish final-fit progress.');
            }
            echo json_encode(['step' => $step])."\n";
        };
        try {
            $event('VERIFY_PARENT_START');
            $bundle = $this->reuse->verify($source);
            JsonlArtifact::json($output.'/parent-start.json', $bundle);
            $code = $this->codeIdentity();
            JsonlArtifact::json($output.'/execution-contract.json', ['contract' => Contract::plan(), 'code' => $code,
                'runtime' => ['php' => PHP_VERSION, 'memory_limit' => ini_get('memory_limit'), 'precision' => ini_get('precision'), 'serialize_precision' => ini_get('serialize_precision')],
                'source_manifest' => Files::identity($source.'/report-export-manifest.json'),
                'git_head' => trim((string) shell_exec('git rev-parse HEAD')), 'git_diff_sha256' => hash('sha256', (string) shell_exec('git diff --binary'))]);
            $this->sourceCheck->verify($source, $output.'/source-start.json');
            $event('VERIFY_OLD_OUTER_PREDICTIONS');
            $outer = [];
            foreach ([2024, 2025] as $year) {
                $dir = Files::directory($output.'/outer-'.$year);
                $old = $source.'/run-01/C1-fit-'.$year;
                $model = $this->loader->load($old.'/model.json', $bundle['files']['run-01/C1-fit-'.$year.'/model.json']);
                $seal = JsonlArtifact::write($dir.'/predictions.jsonl', $this->loader->predictions($source.'/inputs-v2/inputs-'.$year.'.jsonl', $model));
                Files::same(Files::json($old.'/predictions.jsonl.manifest.json'), $seal, 'old outer predictions from saved model');
                $outer[$year] = $seal;
            }
            JsonlArtifact::json($output.'/outer-load-verification.json', ['status' => 'BYTE_EXACT_NO_RETRAINING_NO_REEVALUATION', 'years' => $outer]);
            $training = [$source.'/inputs-v2/inputs-2022.jsonl', $source.'/inputs-v2/inputs-2023.jsonl', $source.'/run-01/labels-2024.jsonl', $source.'/run-01/labels-2025.jsonl'];
            $event('RESTORE_OOF_1_AND_2_WITHOUT_FIT');
            $losses = [
                2023 => $this->reuse->restoreFold($source.'/run-01/C1-inner-A', [$training[0]], $training[1], Files::directory($output.'/reuse-oof-1')),
                2024 => $this->reuse->restoreFold($source.'/run-01/C1-inner-B', array_slice($training, 0, 2), $training[2], Files::directory($output.'/reuse-oof-2')),
            ];
            foreach (['run-01', 'run-02'] as $run) {
                $event($run.'_OOF3_FULL_PATH');
                $this->assertCode($code);
                $dir = Files::directory($output.'/'.$run);
                $third = $this->trainer->grid(array_slice($training, 0, 3), [$training[3]], true, Files::directory($dir.'/oof-3'));
                $selected = $this->selection->select($losses + [2025 => $third['losses']]);
                JsonlArtifact::json($dir.'/selection.json', $selected);
                $event($run.'_FINAL_REFIT_LAMBDA_'.sprintf('%.17g', $selected['lambda']));
                $final = Files::directory($dir.'/final');
                $fit = $this->trainer->refit($training, $source.'/inputs-v2/inputs-2025.jsonl', true, $selected['lambda'], $final);
                $loaded = $this->loader->load($final.'/model.json', Files::identity($final.'/model.json'));
                Files::same($fit['model'], $loaded->artifact, 'final model save/load');
                $predictions = JsonlArtifact::write($final.'/loaded-predictions.jsonl', $this->loader->predictions($source.'/inputs-v2/inputs-2025.jsonl', $loaded));
                Files::same($fit['predictions'], $predictions, 'final predictions before/after save');
                $packages[$run] = ['contract' => Contract::plan(), 'model_file' => 'model.json',
                    'model' => Files::identity($final.'/model.json'), 'prediction_roundtrip' => $predictions,
                    'prediction_purpose' => 'TECHNICAL_ROUNDTRIP_ONLY_NOT_OUT_OF_SAMPLE_ACCURACY'];
                $third['losses']->cleanup();
                unset($third);
                $this->assertCode($code);
            }
            foreach ($losses as $spool) {
                $spool->cleanup();
            }
            $event('VERIFY_REPRODUCIBILITY_AND_END_INTEGRITY');
            $first = $this->inventory($output.'/run-01');
            $second = $this->inventory($output.'/run-02');
            Files::same($first, $second, 'independent OOF3/final-fit reproducibility');
            JsonlArtifact::json($output.'/reproducibility.json', ['status' => 'BYTE_EXACT', 'files' => $first, 'run_02_files' => $second]);
            $this->sourceCheck->verify($source, $output.'/source-end.json');
            $this->reuse->unchanged($bundle);
            $this->assertCode($code);
            foreach ($packages as $run => $package) {
                JsonlArtifact::json($output.'/'.$run.'/final/artifact.json', $package);
                $this->loader->published($output.'/'.$run.'/final/artifact.json');
            }
            $selected = Files::json($output.'/run-01/selection.json');
            $model = Files::json($output.'/run-01/final/model.json');
            $result = ['status' => 'FINAL_FIT_REPRODUCED_AWAITING_REVIEW', 'contract' => Contract::plan(),
                'selection' => $selected, 'training_cohorts' => $bundle['cohorts'], 'positions' => $model['optimizer_diagnostics'],
                'eligible_races' => $model['eligible_races'], 'excluded_races' => $model['excluded_races'],
                'model' => Files::identity($output.'/run-01/final/model.json'), 'semantic_files' => $first,
                'reused_oof' => [2023, 2024], 'new_fit_paths' => ['run-01/oof-3', 'run-01/final', 'run-02/oof-3', 'run-02/final'],
                'parent_gate_reference' => $bundle['gates'], 'start_end_integrity' => 'VERIFIED',
                'code' => $code, '2026_access' => 0, 'production_writes' => 0, 'old_artifacts_overwritten' => 0,
                'new_accuracy_evaluation' => 'NOT_PERFORMED', 'peak_bytes' => memory_get_peak_usage(true)];
            JsonlArtifact::json($output.'/result.json', $result);
            $state['status'] = $result['status'];
            $event('COMPLETE_AWAITING_REVIEW');

            return $result;
        } catch (Throwable $e) {
            $state['status'] = 'FAILED_NOT_FROZEN';
            $state['error_class'] = $e::class;
            $state['error'] = $e->getMessage();
            $event('FAILED');
            throw $e;
        }
    }

    public function codeIdentity(): array
    {
        $files = [];
        foreach (['app', 'config', 'bootstrap'] as $dir) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir), \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->getExtension() === 'php' && ! str_contains($file->getPathname(), '/bootstrap/cache/')) {
                    $files[substr($file->getPathname(), strlen(base_path()) + 1)] = Files::identity($file->getPathname());
                }
            }
        }
        foreach (['composer.lock', 'vendor/composer/installed.php'] as $path) {
            $files[$path] = Files::identity(base_path($path));
        }
        ksort($files, SORT_STRING);

        return $files;
    }

    private function assertCode(array $expected): void
    {
        Files::same($expected, $this->codeIdentity(), 'execution code START/END');
    }

    private function inventory(string $root): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (str_ends_with($file->getFilename(), '.partial') || str_ends_with($file->getFilename(), '.bin')) {
                throw new RuntimeException('Unsealed fit artifact remained.');
            }
            $files[substr($file->getPathname(), strlen($root) + 1)] = Files::identity($file->getPathname());
        }
        ksort($files, SORT_STRING);

        return $files;
    }
}
