<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class Repackage
{
    public const SOURCE = Contract::ROOT.'/run-20261009-LE6Wit1O/result';

    public const MANIFEST = ['bytes' => 315523, 'sha256' => 'ec525fd12b3b784af394032252c160bfcca4e7e833f8b565455c52ec2a2acad3'];

    public const REVIEW_HEAD = '69cf3ca0bccefa2df15fb4100904d2ac2dd9eec4';

    private const C2_SHA = 'd1dbb706071a7dc25d4ea8fa0525ac685a8b68d6d09f3a43a980a70dc1334ea2';

    public function __construct(private readonly Package $packages, private readonly Publication $publication) {}

    public function run(string $source, string $output): array
    {
        [$expectedSource, $expectedManifest] = $this->sourcePin();
        if ($source !== $expectedSource || realpath($source) !== $source || str_contains($source, '.inprogress-')
            || file_exists($source.'/FAILED.json') || is_link($source.'/FAILED.json')) {
            throw new RuntimeException('Repackage accepts only the pinned successful legacy root.');
        }
        Files::verify($source.'/manifest.json', $expectedManifest);
        Files::same($expectedManifest, Files::json($source.'/COMPLETE.json'), 'legacy root COMPLETE');
        $manifest = Files::json($source.'/manifest.json');
        if (($manifest['status'] ?? null) !== 'FINAL_COMPOSITION_FIT_REPRODUCED_AWAITING_REVIEW') {
            throw new RuntimeException('Legacy root is not a successful fit.');
        }
        Files::same(Contract::plan(), $manifest['contract'] ?? [], 'legacy numerical contract');
        $read = [$source.'/manifest.json' => $expectedManifest, $source.'/COMPLETE.json' => Files::identity($source.'/COMPLETE.json')];
        $readChild = static function (string $name) use ($source, $manifest, &$read): array {
            $path = Package::safe($source, $name);
            Files::verify($path, $manifest['files'][$name] ?? []);
            $read[$path] = Files::identity($path);

            return Files::json($path);
        };
        $frozen = $readChild('frozen.json');
        Files::same($manifest['code'], $frozen['code'], 'legacy generation code evidence');
        $this->verifyCode($manifest['code']);
        $reproduction = $readChild('reproduction.json');
        $result = $readChild('result.json');
        if (($reproduction['identical'] ?? null) !== true || ($reproduction['semantic_file_count'] ?? null) !== 37
            || ($result['semantic_file_count'] ?? null) !== 37 || ($result['new_fit_paths'] ?? null) !== 4
            || ($result['candidate_attempts'] ?? null) !== 8 || ($result['c1_retraining_count'] ?? null) !== 0
            || ($result['status'] ?? null) !== $manifest['status']) {
            throw new RuntimeException('Missing independent successful fit/reproduction record.');
        }
        $parents = [];
        foreach (['run-01', 'run-02'] as $run) {
            $artifact = $readChild($run.'/package/artifact.json');
            Files::same(Contract::plan(), $artifact['contract'], 'legacy package contract');
            Files::same($manifest['code'], $artifact['generation_code'], 'legacy package generation code');
            Files::same(['artifact' => Files::identity($source.'/'.$run.'/package/artifact.json'), 'status' => $manifest['status']],
                $readChild($run.'/package/COMPLETE.json'), 'legacy package COMPLETE');
            foreach ($artifact['files'] as $name => $seal) {
                $path = $run.'/package/'.$name;
                $readChild($path);
                Files::verify($source.'/'.$path, $seal);
            }
            $model = $readChild($run.'/final/model.json');
            $layout = $readChild($run.'/final/layout.json');
            $selection = $readChild($run.'/selection.json');
            Files::same($model, Files::json($source.'/'.$run.'/package/c2/model.json'), 'legacy copied C2');
            Files::same($layout, Files::json($source.'/'.$run.'/package/c2/layout.json'), 'legacy copied layout');
            Files::same($selection, Files::json($source.'/'.$run.'/package/c2/selection.json'), 'legacy copied selection');
            $parents[$run] = ['files' => $artifact['files'], 'selection' => $selection, 'layout' => $layout];
        }
        Files::same($parents['run-01'], $parents['run-02'], 'both legacy runs model/layout/selection');
        $input = $source.'/verified-inputs/features-2025.jsonl';
        foreach (['', '.manifest.json', '.input.json'] as $suffix) {
            $path = $input.$suffix;
            Files::verify($path, $manifest['files']['verified-inputs/features-2025.jsonl'.$suffix] ?? []);
            $read[$path] = Files::identity($path);
        }
        $prediction = dirname($source).'/public-predictions-2025.jsonl';
        $predictionSeal = $reproduction['files']['composition-before-package.jsonl'] ?? [];
        Files::verify($prediction, $predictionSeal);
        $read[$prediction] = Files::identity($prediction);
        foreach (['.manifest.json', '.COMPLETE.json'] as $suffix) {
            $read[$prediction.$suffix] = Files::identity($prediction.$suffix);
        }
        $predictionManifest = Files::json($prediction.'.manifest.json');
        Files::verify($prediction, $predictionManifest);
        Files::same($predictionManifest, Files::json($prediction.'.COMPLETE.json')['predictions'] ?? [], 'legacy prediction COMPLETE');
        if (($predictionManifest['rows'] ?? null) !== (Files::json($input.'.manifest.json')['rows'] ?? null)) {
            throw new RuntimeException('Legacy input/reference prediction row count mismatch.');
        }
        $execution = dirname($source).'/log-execute/execution.json';
        $executionRecord = Files::json($execution);
        if (($executionRecord['commands'][0]['exit_code'] ?? null) !== 0 || ($executionRecord['mode'] ?? null) !== 'execute'
            || ($executionRecord['commands'][0]['command'][count($executionRecord['commands'][0]['command']) - 1] ?? null) !== '--output-dir='.$source) {
            throw new RuntimeException('Legacy independent execution did not succeed for this root.');
        }
        $read[$execution] = Files::identity($execution);
        $code = Contract::code();
        $output = $this->publication->destination($output, [$source, $prediction]);
        $lock = $this->publication->acquire($output);
        $stage = '';
        $completion = null;
        try {
            $stage = $this->publication->stage($output);
            foreach (['c1', 'c2'] as $directory) {
                Files::directory($stage.'/'.$directory);
            }
            $old = Files::json($source.'/run-01/package/artifact.json');
            foreach ($old['files'] as $name => $seal) {
                if (! copy(Package::safe($source.'/run-01/package', $name), $stage.'/'.$name)) {
                    throw new RuntimeException('Could not copy legacy model byte-exact.');
                }
                Files::verify($stage.'/'.$name, $seal);
            }
            $evidence = ['source_root' => $source, 'root_manifest' => $expectedManifest, 'legacy_contract' => Contract::VERSION,
                'legacy_package' => $read[$source.'/run-01/package/artifact.json'], 'legacy_generation_code' => $old['generation_code'],
                'legacy_reproduction' => $read[$source.'/reproduction.json'], 'legacy_execution' => $read[$execution],
                'historical_semantic_file_count' => 37, 'historical_reproduction_identical' => true,
                'parent_files' => $old['files'], 'source_start' => $read,
                'publication_version' => Contract::PUBLICATION_VERSION, 'runtime_code' => $code,
                'retraining_count' => 0, 'performance_evaluation' => 'NOT_PERFORMED_PUBLICATION_FIX_AND_TECHNICAL_REVALIDATION_ONLY'];
            $old['publication_version'] = Contract::PUBLICATION_VERSION;
            $old['publication_owner'] = '.';
            $old['publication_code'] = $code;
            $old['provenance']['repackage'] = $evidence;
            $this->publication->json($stage.'/artifact.json', $old);
            $this->packages->prepared($stage.'/artifact.json');
            foreach ($read as $path => $seal) {
                Files::verify($path, $seal);
            }
            $this->verifyCode($manifest['code']);
            Files::same($code, Contract::code(), 'repackage runtime code END');
            $this->publication->seal($stage, $output, $this->publicationKind(), ['artifact.json'], $evidence);
            foreach ($read as $path => $seal) {
                Files::verify($path, $seal);
            }
            Files::same($code, Contract::code(), 'repackage precommit code');
            $result = ['status' => 'REPACKAGED_WITHOUT_RETRAINING_AWAITING_REVIEW', 'artifact' => $output.'/artifact.json',
                'artifact_seal' => Files::identity($stage.'/artifact.json'), 'models' => $old['files'], 'source_start_end_identical' => true,
                'read_files' => $read, 'retraining_count' => 0, 'performance_evaluation' => $evidence['performance_evaluation'],
                'peak_memory_bytes' => memory_get_peak_usage(true)];
            $completion = Files::identity($stage.'/COMPLETE.json');
            $this->publication->commit($stage, $output);

            return $result;
        } catch (Throwable $e) {
            if ($this->publication->wasCommitted($stage, $output, $completion)) {
                return $result + ['postcommit_warning' => $e->getMessage()];
            }
            $this->publication->failed($stage, $e);
            throw $e;
        } finally {
            fclose($lock);
        }
    }

    public static function verifyEvidence(array $evidence): void
    {
        if (($evidence['source_root'] ?? null) !== self::SOURCE || ($evidence['root_manifest'] ?? null) !== self::MANIFEST
            || ($evidence['legacy_contract'] ?? null) !== Contract::VERSION || ($evidence['historical_semantic_file_count'] ?? null) !== 37
            || ($evidence['historical_reproduction_identical'] ?? null) !== true || ($evidence['retraining_count'] ?? null) !== 0
            || ($evidence['parent_files']['c1/model.json']['sha256'] ?? null) !== Contract::C1_SHA
            || ($evidence['parent_files']['c2/model.json']['sha256'] ?? null) !== self::C2_SHA) {
            throw new RuntimeException('Portable export has no pinned legacy success provenance.');
        }
        Files::same(Contract::code(), $evidence['runtime_code'] ?? [], 'portable publication runtime');
    }

    protected function sourcePin(): array
    {
        return [self::SOURCE, self::MANIFEST];
    }

    protected function publicationKind(): string
    {
        return 'REPACKAGE';
    }

    protected function verifyCode(array $code): void
    {
        $publicationFiles = ['Contract', 'Experiment', 'Package', 'Prediction', 'Standalone'];
        foreach ($code as $name => $seal) {
            $changed = str_starts_with($name, 'app/Domain/Keirin/Backtest/Experiments/C1Stat35CompositionFinal/')
                && in_array(pathinfo($name, PATHINFO_FILENAME), $publicationFiles, true);
            $changed = $changed || in_array($name, ['app/Console/Commands/Keirin/FinalC1Stat35CompositionCommand.php',
                'app/Console/Commands/Keirin/PredictC1Stat35CompositionCommand.php'], true);
            if ($changed) {
                $process = new Process(['git', 'show', self::REVIEW_HEAD.':'.$name], base_path());
                $process->mustRun();
                if (['bytes' => strlen($process->getOutput()), 'sha256' => hash('sha256', $process->getOutput())] !== $seal) {
                    throw new RuntimeException('Legacy publication code does not match the review commit: '.$name);
                }
            } else {
                Files::verify(base_path($name), $seal);
            }
        }
    }
}
