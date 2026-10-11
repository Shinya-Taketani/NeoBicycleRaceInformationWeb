<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\BFixedWinnerJointP23;

use App\Domain\Keirin\Backtest\Experiments\C1MarginalP23Decoder\Sources as SharedSources;
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

    public function execute(string $output, ?array $syntheticPaths = null): array
    {
        $parent = realpath(dirname($output));
        if ($parent === false || $output !== $parent.'/'.basename($output) || file_exists($output) || is_link($output)
            || in_array(basename($output), ['.', '..', ''], true)) {
            throw new RuntimeException('Output must be a new absolute dedicated directory.');
        }
        if ($syntheticPaths !== null && ! app()->environment('testing')) {
            throw new RuntimeException('Synthetic paths are testing-only.');
        }
        $source = $syntheticPaths === null ? $this->sources->open() : $this->sources->open(...$syntheticPaths);
        foreach (array_keys($source['seals'] + $source['outcome_seals']) as $path) {
            if ($path === $output || str_starts_with($path.'/', $output.'/')) {
                throw new RuntimeException('Source/output overlap.');
            }
        }
        $code = Contract::code();
        Files::directory($output);
        JsonlArtifact::json($output.'/frozen-contract.json', ['contract' => Contract::plan(), 'source' => $source, 'code' => $code]);
        try {
            $results = [];
            foreach (['run-01', 'run-02'] as $run) {
                echo json_encode(['phase' => 'INDEPENDENT_SAVED_SOURCE_REREAD', 'run' => $run])."\n";
                $results[$run] = $this->run($source, Files::directory($output.'/'.$run));
            }
            $a = self::semanticFiles($output.'/run-01');
            Files::same($a, self::semanticFiles($output.'/run-02'), 'two independent run semantics');
            JsonlArtifact::json($output.'/reproduction.json', ['identical' => true, 'files' => $a, 'semantic_file_count' => count($a), 'source_reread_runs' => 2]);
            $result = $results['run-01'];
            $exactDelta = array_sum(array_map(fn ($year) => $year['delta']['EXACT_ORDERED_TOP3_RATE'], $result['primary_exact_order']['C-B'])) / 2;
            $ci = $result['intervals']['C-B']['PRIMARY_EXACT_ORDERED_TOP3_RATE'];
            $result['completion'] = ['implementation_status' => 'IMPLEMENTED_TESTED', 'evaluation_status' => 'MEASURED_REPRODUCED',
                'joint_probability_invariant' => true, 'winner_preservation' => true,
                'primary_exact_order_delta_vs_B' => $exactDelta, 'primary_exact_order_95ci_vs_B' => $ci,
                'position_hit3_delta_vs_B' => array_sum(array_map(fn ($y) => $y['delta']['POSITION_HIT_RATE_AT_3'], $result['outer']['C-B'])) / 2,
                'existing_incremental_gate' => $result['incremental_gates']['C-B']['status'],
                'development_accuracy_evidence' => $exactDelta > 0.0 && $ci['ci_lower'] > 0.0 ? 'PRIMARY_EXACT_ORDER_IMPROVEMENT_DEVELOPMENT_ONLY' : 'IMPROVEMENT_NOT_CONFIRMED_NOT_ADOPTED',
                'formal_adoption' => false, 'historical_as_of_available' => false, '2026_access' => 'FORBIDDEN', 'training_count' => 0];
            JsonlArtifact::json($output.'/comparison.json', $result);
            $files = self::semanticFiles($output);
            foreach ($files as $name => $seal) {
                Files::verify($output.'/'.$name, $seal);
            }
            SharedSources::verify($source, true);
            Files::same($code, Contract::code(), 'code START/END/prepublication');
            JsonlArtifact::json($output.'/manifest.json', ['contract' => Contract::plan(), 'source' => $source, 'code' => $code,
                'source_start_end_unchanged' => true, 'code_start_end_unchanged' => true, 'files' => $files, 'completion' => $result['completion']]);
            JsonlArtifact::json($output.'/COMPLETE.json', Files::identity($output.'/manifest.json'));

            return $result['completion'] + ['manifest' => Files::identity($output.'/manifest.json')];
        } catch (Throwable $e) {
            JsonlArtifact::json($output.'/FAILED.json', ['evaluation_status' => 'NOT_EVALUATED', 'performance' => null,
                'gate' => null, 'exception' => $e::class, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function run(array $source, string $directory): array
    {
        $paths = $audits = $events = [];
        $seen = Reader::identities($directory.'/identities.sqlite');
        foreach ([2024, 2025] as $year) {
            $audit = [];
            $paths[$year] = $directory.'/decisions-'.$year.'.jsonl';
            JsonlArtifact::write($paths[$year], $this->reader->rows($source, $year, $seen, $audit));
            $audits[$year] = $audit;
            $events[] = ['event' => 'CANDIDATES_SEALED_VERIFIED', 'year' => $year, 'seal' => Files::identity($paths[$year])];
        }
        $seen->commit();
        $seen = null;
        foreach ($paths as $path) {
            Files::verify($path, Files::json($path.'.manifest.json'));
        }
        SharedSources::verify($source, false);
        $events[] = ['event' => 'BOTH_YEARS_SEALS_COHORT_INVARIANTS_VERIFIED'];
        foreach ([2024, 2025] as $year) {
            $events[] = ['event' => 'OUTCOME_RELEASE_AUTHORIZED', 'year' => $year];
        }
        JsonlArtifact::json($directory.'/access-order.json', $events);
        JsonlArtifact::json($directory.'/invariants.json', $audits);
        $result = $this->evaluation->evaluate($source, $paths, $directory);
        SharedSources::verify($source, true);

        return $result + ['invariants' => $audits];
    }

    public static function semanticFiles(string $directory): array
    {
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isLink() || ! $file->isFile()) {
                throw new RuntimeException('Unexpected generated artifact type.');
            }
            $files[substr($file->getPathname(), strlen($directory) + 1)] = Files::identity($file->getPathname());
        }
        ksort($files);

        return $files;
    }
}
