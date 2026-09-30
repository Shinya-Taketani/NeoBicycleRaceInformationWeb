<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\Stat35C1Diagnostic;

use App\Domain\Keirin\Backtest\Calculators\Bt03e05MetricEvaluator;
use App\Domain\Keirin\Backtest\Experiments\Stat35C1Comparison\ModelLoader as C2Loader;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\ModelLoader as C1Loader;
use App\Domain\Keirin\Statistics\AgariRaceRelative\Artifacts;
use RuntimeException;
use Throwable;

final class Builder
{
    public function __construct(private readonly Sources $sources, private readonly Reader $reader,
        private readonly Utility $utility, private readonly C1Loader $c1Loader, private readonly C2Loader $c2Loader,
        private readonly Bt03e05MetricEvaluator $metrics) {}

    public function build(string $compare, string $input, string $baseline, string $output, ?string $original = null): array
    {
        $this->guardOutput($output, array_filter([$compare, $input, $baseline, $original]));
        $prior = $original === null ? null : $this->published($original);
        Artifacts::create($output);
        try {
            $source = $this->sources->open($compare, $input, $baseline);
            $code = Contract::code();
            $files = ['contract.json' => Artifacts::json($output, 'contract.json', Contract::plan())];
            $transitions = new Transitions($this->metrics);
            $utilitySummary = new UtilitySummary;
            $examples = $models = $counts = [];
            $identities = Reader::identityIndex();
            foreach (Contract::YEARS as $year) {
                $paths = $source['paths'][$year];
                $c1 = $this->c1Loader->load($paths['c1_model'], $source['seals'][$paths['c1_model']]);
                $c2 = $this->c2Loader->load($paths['c2_model'], $source['seals'][$paths['c2_model']]);
                $models[$year] = ['C1' => $c1, 'C2' => $c2];
                $entryName = 'entry-utility-'.$year.'.jsonl';
                $raceName = 'race-change-'.$year.'.jsonl';
                $entries = new LineWriter($output.'/'.$entryName);
                $races = new LineWriter($output.'/'.$raceName);
                $counts[$year] = ['races' => 0, 'entries' => 0];
                $examples[$year] = array_fill_keys([1, 2, 3], ['B' => [], 'C' => []]);
                foreach ($this->reader->rows($source, $year, $identities) as $row) {
                    $counts[$year]['races']++;
                    $details = [];
                    foreach ($row['input']['entries'] as $i => $entry) {
                        $detail = $this->utility->entry($entry, $row['means'][$i], $c1, $c2);
                        $detail = $this->utility->verifySaved($detail, $row['c1']['probabilities']['entries'][$i], $row['c2']['probabilities']['entries'][$i]);
                        $utilitySummary->add($year, $detail);
                        $detail = ['year' => $year, 'race_id' => $row['input']['race_id'], ...$detail];
                        $entries->append($detail);
                        $details[] = $detail;
                        $counts[$year]['entries']++;
                    }
                    $change = $transitions->add($row['labels'], $row['c1']['decision'], $row['c2']['decision'], $row['contributions']);
                    $change['source_race_line'] = $counts[$year]['races'];
                    $races->append($change);
                    foreach ($change['positions'] as $k => $position) {
                        $category = $position['transition'];
                        if (in_array($category, ['B', 'C'], true) && count($examples[$year][$k][$category]) < 3) {
                            $examples[$year][$k][$category][] = ['race_change' => $change, 'entry_utilities' => $details];
                        }
                    }
                }
                $files[$entryName] = $entries->finish();
                $files[$raceName] = $races->finish();
                Files::same($source['expected'][$year], $counts[$year], 'written cohort');
            }
            $identities->rollBack();
            $old = Files::json($source['comparisons']);
            $summary = $transitions->finish($old);
            $utilities = $utilitySummary->finish();
            $hit3 = ['years' => array_map(fn ($year) => $year['hit3'], $summary['years']),
                'year_equal_delta' => $summary['year_equal_delta']['hit3'], 'existing_comparison_verified' => true];
            foreach (['transition-summary.json' => $summary, 'hit3-transition.json' => $hit3,
                'utility-summary.json' => $utilities, 'examples.json' => $examples] as $name => $value) {
                $files[$name] = Artifacts::json($output, $name, $value);
            }
            $ledger = function () use ($models): \Generator {
                foreach ($models as $year => $both) {
                    foreach ($both as $name => $model) {
                        yield from $this->utility->ledger($year, $name, $model);
                    }
                }
            };
            $files['coefficient-ledger.csv'] = Artifacts::write($output, 'coefficient-ledger.csv', $this->csv($ledger()));
            $rows = [];
            foreach ($summary['years'] as $year => $data) {
                foreach ($data['positions'] as $position => $row) {
                    $rows[] = ['year' => $year, 'position' => $position, ...$row];
                }
            }
            $files['transition-summary.csv'] = Artifacts::write($output, 'transition-summary.csv', $this->csv($rows));
            $rows = [];
            foreach ($hit3['years'] as $year => $data) {
                foreach ($data['matrix'] as $i => $cells) {
                    foreach ($cells as $j => $count) {
                        $rows[] = ['year' => $year, 'c1_matches' => $i, 'c2_matches' => $j, 'races' => $count,
                            'delta_numerator' => ($j - $i) * $count];
                    }
                }
            }
            $files['hit3-transition.csv'] = Artifacts::write($output, 'hit3-transition.csv', $this->csv($rows));
            $files['report.md'] = Artifacts::write($output, 'report.md', [$this->report($summary, $utilities)]);
            Sources::verify($source);
            Files::same($code, Contract::code(), 'diagnostic code start/end');
            $verification = ['status' => 'VERIFIED', 'cohort' => $counts, 'saved_utility_exact' => true,
                'source_start_end_unchanged' => true, 'source_files_read' => count($source['seals']),
                'primary_contributions_and_unrounded_rates_identical' => true,
                'regrouped_residuals_within_frozen_bound' => true, 'diagnostic_only' => true,
                'prior_incremental_gate_copied_not_recomputed' => $old['incremental_gate'] ?? null,
                'training_prediction_ci_gate_executions' => 0, 'db_http_2026_access' => 0];
            $files['verification.json'] = Artifacts::json($output, 'verification.json', $verification);
            $manifest = ['contract' => Contract::plan(), 'status' => 'DESCRIPTIVE_DIAGNOSTIC_COMPLETE',
                'source' => $source, 'code' => $code, 'files' => $files];
            if ($prior !== null) {
                Files::same($prior, $manifest, 'independent diagnostic reproduction');
                Files::same($prior, $this->published($original), 'original diagnostic end seal');
            }
            Artifacts::publish($output, $manifest);

            return ['status' => $manifest['status'], 'cohort' => $counts, 'manifest' => Files::identity($output.'/manifest.json'),
                'independent_reproduction' => $prior !== null, 'semantic_files' => count($files)];
        } catch (Throwable $e) {
            Artifacts::json($output, 'FAILED.json', ['status' => 'DIAGNOSTIC_FAILED_NOT_PUBLISHED', 'error_class' => $e::class, 'message' => $e->getMessage()]);
            throw $e;
        }
    }

    private function published(string $root): array
    {
        Files::verify($root.'/manifest.json', Files::json($root.'/COMPLETE.json'));
        $manifest = Files::json($root.'/manifest.json');
        Files::same(Contract::plan(), $manifest['contract'] ?? [], 'diagnostic contract');
        Files::same(Contract::code(), $manifest['code'] ?? [], 'diagnostic code');
        foreach ($manifest['files'] as $name => $seal) {
            if (basename($name) !== $name) {
                throw new RuntimeException('Invalid diagnostic child path.');
            }
            Files::verify($root.'/'.$name, $seal);
        }

        return $manifest;
    }

    private function guardOutput(string $output, array $sources): void
    {
        $parent = realpath(dirname($output));
        if ($parent === false || file_exists($output) || is_link($output) || in_array(basename($output), ['.', '..'], true)) {
            throw new RuntimeException('Output must be new with an existing parent.');
        }
        foreach ($sources as $source) {
            $root = realpath($source);
            if ($root === false || $parent === $root || str_starts_with($parent.'/', $root.'/')) {
                throw new RuntimeException('Output overlaps or source is missing.');
            }
        }
    }

    private function csv(iterable $rows): \Generator
    {
        $first = true;
        foreach ($rows as $row) {
            if ($first) {
                yield $this->csvLine(array_keys($row));
                $first = false;
            }
            yield $this->csvLine(array_map(fn ($v) => is_array($v) ? Files::canonical($v)
                : (is_float($v) ? json_encode($v, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION) : ($v ?? '')), $row));
        }
    }

    private function csvLine(array $row): string
    {
        $stream = fopen('php://memory', 'w+');
        fputcsv($stream, $row, ',', '"', '');
        rewind($stream);
        $line = stream_get_contents($stream);
        fclose($stream);

        return $line;
    }

    private function report(array $summary, array $utilities): string
    {
        $text = "# STAT-35-C1-DIAGNOSTIC-01\n\nPOST_HOC_DESCRIPTIVE_DIAGNOSTIC. Not a new validation or causal explanation.\n\n";
        $text .= "C1 retained; existing C2-C1 Gate NOT_PASSED unchanged. No new training, prediction, CI or Gate.\n\n";
        $text .= "| Year | Position | A | B | C | D | Changed (all/eligible) | C-B | Delta pp |\n|---|---|---:|---:|---:|---:|---|---:|---:|\n";
        foreach ($summary['years'] as $year => $s) {
            foreach ($s['positions'] as $k => $p) {
                $text .= sprintf("| %d | %d | %d | %d | %d | %d | %d/%d | %+d | %s |\n", $year, $k,
                    $p['A'], $p['B'], $p['C'], $p['D'], $p['prediction_changed_all'], $p['prediction_changed_eligible'],
                    $p['delta_numerator'], $p['delta'] === null ? 'NOT_EVALUATED' : sprintf('%+.8f', 100 * $p['delta']));
            }
            $text .= "\nHit3 {$year}: ".Files::canonical($s['hit3'])."\n\n";
        }
        $text .= "\nUtility units are not probability points, race scores, or causal effects.\n";
        $text .= "Direct mean6 and refitted existing-feature differences are both retained; NULL direct contribution is zero, not necessarily zero total difference.\n";
        $text .= "Examples: examples.json contains the first three B/C races per year/position in fixed input order, with source line and all entrant utilities. Empty lists mean no case. Only ordered-eligible races can be P2/P3 swap examples.\n";
        $text .= "Coefficient/bin/support mappings: coefficient-ledger.csv. Arithmetic and source pins: contract.json / manifest.json.\n";
        $text .= "\nUtility summary:\n```json\n".json_encode($utilities, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n```\n";
        $text .= "\nObserved utility and Primary changes do not identify a causal benefit or future performance. historical_as_of_available=false; 2026 remains closed.\n";

        return $text;
    }
}
