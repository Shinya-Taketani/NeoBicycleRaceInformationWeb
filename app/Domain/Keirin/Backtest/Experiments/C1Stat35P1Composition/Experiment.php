<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\C1Stat35P1Composition;

use App\Domain\Keirin\Backtest\Experiments\C1MarginalP23Decoder\Sources as BaselineSources;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

final class Experiment
{
    public function __construct(private readonly Sources $sources, private readonly Reader $reader, private readonly Evaluation $evaluation) {}

    public function execute(string $input, string $baseline, string $compare, string $output): array
    {
        $parent = realpath(dirname($output));
        if ($parent === false || ! str_starts_with($output, '/') || file_exists($output) || is_link($output)) {
            throw new RuntimeException('Output must be new with an existing absolute parent.');
        }
        foreach ([$input, $baseline, $compare] as $path) {
            $path = realpath($path);
            if ($path === false || $parent === $path || str_starts_with($parent.'/', $path.'/') || str_starts_with($path.'/', $output.'/')) {
                throw new RuntimeException('Output and source overlap.');
            }
        }
        $source = $this->sources->open($input, $baseline, $compare);
        $code = Contract::code();
        Files::directory($output);
        JsonlArtifact::json($output.'/frozen-contract.json', ['contract' => Contract::plan(), 'source' => $source, 'code' => $code]);
        try {
            $results = [];
            foreach (['run-01', 'run-02'] as $run) {
                echo json_encode(['phase' => 'INDEPENDENT_UTILITY_FORWARD_EVALUATION', 'run' => $run])."\n";
                $results[$run] = $this->run($source, Files::directory($output.'/'.$run));
            }
            $first = self::semanticFiles($output.'/run-01');
            $second = self::semanticFiles($output.'/run-02');
            Files::same($first, $second, 'independent utility forward/evaluation reproduction');
            JsonlArtifact::json($output.'/reproduction.json', ['identical' => true, 'files' => $first, 'semantic_file_count' => count($first),
                'independent_source_reread_runs' => 2, 'training_count' => 0]);
            BaselineSources::verify($source, true);
            Files::same($code, Contract::code(), 'code START/END');
            $result = $results['run-01'] + $this->evaluation->gates($results['run-01'], true);
            $result['status'] = 'COMPLETED_DEVELOPMENT_COMPARISON_AWAITING_REVIEW';
            $result['training_count'] = 0;
            $result['use_restrictions'] = Contract::plan()['use_restrictions'];
            JsonlArtifact::json($output.'/comparison.json', $result);
            $files = self::semanticFiles($output);
            foreach ($files as $name => $seal) {
                Files::verify($output.'/'.$name, $seal);
            }
            // Publication is possible only after source, code, generated files and reproduction agree.
            BaselineSources::verify($source, true);
            Files::same($code, Contract::code(), 'prepublication code');
            JsonlArtifact::json($output.'/manifest.json', ['contract' => Contract::plan(), 'status' => $result['status'],
                'source_start_end_unchanged' => true, 'code_start_end_unchanged' => true, 'source' => $source, 'code' => $code, 'files' => $files]);
            JsonlArtifact::json($output.'/COMPLETE.json', Files::identity($output.'/manifest.json'));

            return ['status' => $result['status'], 'incremental_gate' => $result['incremental_gate'], 'stat01_gate' => $result['stat01_gate'],
                'training_count' => 0, 'manifest' => Files::identity($output.'/manifest.json')];
        } catch (Throwable $e) {
            JsonlArtifact::json($output.'/FAILED.json', ['status' => 'NOT_EVALUATED', 'performance' => null, 'gate' => null,
                'exception' => $e::class, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function run(array $source, string $directory): array
    {
        $paths = $audit = $events = [];
        $seen = Reader::identities();
        try {
            foreach ([2024, 2025] as $year) {
                $prediction = $directory.'/predictions-'.$year.'.jsonl';
                $yearAudit = [];
                JsonlArtifact::write($prediction, $this->reader->rows($source, $year, $seen, $yearAudit));
                $paths[$year] = $directory.'/decisions-'.$year.'.jsonl';
                JsonlArtifact::write($paths[$year], (function () use ($prediction) {
                    foreach (JsonlArtifact::read($prediction) as $row) {
                        unset($row['probabilities']);
                        yield $row;
                    }
                })());
                $audit[$year] = $yearAudit;
                $events[] = ['event' => 'FORWARD_CONTROLS_COMPOSITION_SEALED_VERIFIED', 'year' => $year,
                    'prediction_seal' => Files::identity($prediction), 'decision_seal' => Files::identity($paths[$year])];
            }
        } finally {
            $seen->rollBack();
        }
        foreach ([2024, 2025] as $year) {
            foreach ([$paths[$year], $directory.'/predictions-'.$year.'.jsonl'] as $path) {
                Files::verify($path, Files::json($path.'.manifest.json'));
            }
        }
        BaselineSources::verify($source, false);
        $events[] = ['event' => 'BOTH_YEARS_SEALS_CONTROLS_INVARIANTS_VERIFIED'];
        foreach ([2024, 2025] as $year) {
            $events[] = ['event' => 'OUTCOME_RELEASE_AUTHORIZED', 'year' => $year];
        }
        JsonlArtifact::json($directory.'/access-order.json', $events);
        JsonlArtifact::json($directory.'/invariants.json', $audit);
        $result = $this->evaluation->evaluate($source, $paths, $directory);
        BaselineSources::verify($source, true);

        return $result + ['invariants' => $audit];
    }

    public static function semanticFiles(string $directory): array
    {
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (! $file->isFile() || ! in_array($file->getExtension(), ['json', 'jsonl'], true)) {
                continue;
            }
            $files[substr($file->getPathname(), strlen($directory) + 1)] = Files::identity($file->getPathname());
        }
        ksort($files);

        return $files;
    }
}
