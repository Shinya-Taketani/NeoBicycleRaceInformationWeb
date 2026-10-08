<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\C1P23NondecreasingMarginal;

use App\Domain\Keirin\Backtest\Experiments\C1MarginalP23Decoder\Experiment as SharedExperiment;
use App\Domain\Keirin\Backtest\Experiments\C1MarginalP23Decoder\Reader;
use App\Domain\Keirin\Backtest\Experiments\C1MarginalP23Decoder\Sources;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use Generator;
use RuntimeException;
use Throwable;

final class Experiment
{
    public function __construct(private readonly Sources $sources, private readonly Reader $reader,
        private readonly Decoder $decoder, private readonly Evaluation $evaluation) {}

    public function execute(string $input, string $baseline, string $output): array
    {
        $parent = realpath(dirname($output));
        if ($parent === false || ! str_starts_with($output, '/') || file_exists($output) || is_link($output)) {
            throw new RuntimeException('Output must be new with an existing absolute parent.');
        }
        foreach ([$input, $baseline] as $path) {
            $path = realpath($path);
            if ($path === false || $parent === $path || str_starts_with($parent.'/', $path.'/') || str_starts_with($path.'/', $output.'/')) {
                throw new RuntimeException('Output and source overlap.');
            }
        }
        $source = $this->sources->open($input, $baseline);
        $code = Contract::code();
        Files::directory($output);
        JsonlArtifact::json($output.'/frozen-contract.json', ['contract' => Contract::plan(), 'source' => $source, 'code' => $code]);
        try {
            $results = [];
            foreach (['run-01', 'run-02'] as $run) {
                echo json_encode(['phase' => 'INDEPENDENT_DECODE_EVALUATION', 'run' => $run])."\n";
                $results[$run] = $this->run($source, Files::directory($output.'/'.$run));
            }
            $first = SharedExperiment::semanticFiles($output.'/run-01');
            $second = SharedExperiment::semanticFiles($output.'/run-02');
            Files::same($first, $second, 'independent source-reread reproduction');
            JsonlArtifact::json($output.'/reproduction.json', ['identical' => true, 'files' => $first,
                'semantic_file_count' => count($first), 'independent_source_reread_runs' => 2, 'training_count' => 0]);
            Sources::verify($source, true);
            Files::same($code, Contract::code(), 'code START/END');
            $result = $results['run-01'] + $this->evaluation->gates($results['run-01'], true);
            $result['status'] = 'COMPLETED_DEVELOPMENT_COMPARISON_AWAITING_REVIEW';
            $result['training_count'] = 0;
            $result['use_restrictions'] = Contract::plan()['use_restrictions'];
            JsonlArtifact::json($output.'/comparison.json', $result);
            $files = SharedExperiment::semanticFiles($output);
            foreach ($files as $name => $seal) {
                Files::verify($output.'/'.$name, $seal);
            }
            Sources::verify($source, true);
            Files::same($code, Contract::code(), 'prepublication code');
            JsonlArtifact::json($output.'/manifest.json', ['contract' => Contract::plan(), 'status' => $result['status'],
                'source_start_end_unchanged' => true, 'code_start_end_unchanged' => true,
                'source' => $source, 'code' => $code, 'files' => $files]);
            JsonlArtifact::json($output.'/COMPLETE.json', Files::identity($output.'/manifest.json'));

            return ['status' => $result['status'], 'incremental_gate' => $result['incremental_gate'],
                'stat01_gate' => $result['stat01_gate'], 'training_count' => 0, 'manifest' => Files::identity($output.'/manifest.json')];
        } catch (Throwable $e) {
            JsonlArtifact::json($output.'/FAILED.json', ['status' => 'NOT_EVALUATED', 'performance' => null,
                'gate' => null, 'exception' => $e::class, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function run(array $source, string $directory): array
    {
        $paths = $audit = $events = [];
        foreach ([2024, 2025] as $year) {
            $paths[$year] = $directory.'/decisions-'.$year.'.jsonl';
            $yearAudit = [];
            JsonlArtifact::write($paths[$year], $this->decisions($source, $year, $directory.'/seen-'.$year.'.sqlite', $yearAudit));
            unlink($directory.'/seen-'.$year.'.sqlite');
            if ($yearAudit['P1_changes'] !== 0 || $yearAudit['P2_probability_decreases'] !== 0 || $yearAudit['P3_probability_decreases'] !== 0) {
                throw new RuntimeException('Fixed P1/component nondecrease invariant failed before outcome release.');
            }
            $audit[$year] = $yearAudit;
            $events[] = ['event' => 'DECISION_SEALED_P1_COMPONENTS_VERIFIED', 'year' => $year, 'seal' => Files::identity($paths[$year])];
        }
        foreach ($paths as $path) {
            Files::verify($path, Files::json($path.'.manifest.json'));
        }
        $events[] = ['event' => 'BOTH_YEARS_SEALS_AND_COMPONENTS_VERIFIED'];
        foreach ([2024, 2025] as $year) {
            $events[] = ['event' => 'OUTCOME_RELEASE_AUTHORIZED', 'year' => $year];
        }
        JsonlArtifact::json($directory.'/access-order.json', $events);
        JsonlArtifact::json($directory.'/invariants.json', $audit);
        $result = $this->evaluation->evaluate($source, $paths, $directory);
        Sources::verify($source, true);

        return $result + ['invariants' => $audit];
    }

    public function decisions(array $source, int $year, string $index, array &$audit): Generator
    {
        $policy = array_fill_keys(['P2_probability_decreases', 'P3_probability_decreases', 'all_pairs', 'feasible_pairs',
            'excluded_p2_only', 'excluded_p3_only', 'excluded_both', 'original_pair_retained',
            'equal_maximum_retained', 'hash_selection', 'same_as_PR90', 'same_as_E05'], 0);
        $streamAudit = [];
        foreach ($this->reader->decisionsUsing($source, $year, $index, $streamAudit, $this->decoder->decode(...)) as $row) {
            $p = $row['candidate']['policy'];
            foreach ($policy as $key => $_) {
                $policy[$key] += str_ends_with($key, '_probability_decreases')
                    ? (int) ($p[$key === 'P2_probability_decreases' ? 'p2_delta' : 'p3_delta'] < 0.0)
                    : (int) $p[$key];
            }
            $row['candidate']['policy']['model_sha256'] = $row['model_sha256'];
            $row['candidate']['policy']['source_row'] = $row['source_row'];
            $row['candidate']['policy']['probabilities_semantic_sha256'] = $row['probabilities_semantic_sha256'];
            yield $row;
        }
        $audit = $streamAudit + $policy;
    }
}
