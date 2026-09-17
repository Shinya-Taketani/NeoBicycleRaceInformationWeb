<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\TacticalPredictionResult;

use App\Domain\Keirin\Backtest\Calculators\Bt03e05MetricEvaluator;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionPipeline\ArtifactStore;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionPipeline\ModelIdentity;
use App\Domain\Keirin\Scraping\Enums\RaceEntryResultStatus;
use Generator;
use RuntimeException;
use Throwable;

final class ResultService
{
    public function __construct(private readonly Sources $sources, private readonly ResultStore $store,
        private readonly Matcher $matcher, private readonly Bt03e05MetricEvaluator $metrics) {}

    public function execute(string $mode, string $id, string $root, string $requests, string $labels, string $labelsManifest): array
    {
        Contract::mode($mode);
        Contract::id($id);
        $source = $this->sources->capture($requests, $labels, $labelsManifest);
        $references = $source['seals'];
        // The explicit request list may live beside this run's output, but never in a writable subdirectory.
        if (dirname($requests) === $root) {
            unset($references[$requests]);
        }
        $this->store->prepare($root, $references);

        return $this->store->locked($root, $id, function () use ($mode, $id, $root, $source): array {
            $code = $this->code();
            $request = ['evaluation_id' => $id, 'mode' => $mode, 'contract' => Contract::plan(),
                'source_sha256' => hash('sha256', Files::canonical($source)), 'code_sha256' => hash('sha256', Files::canonical($code))];
            $destination = $root.'/evaluations/'.$id;
            if (file_exists($destination) || is_link($destination)) {
                $manifest = $this->store->verify($destination);
                if ($manifest['request'] !== $request) {
                    throw new RuntimeException('CONFLICT: evaluation ID has different prediction/result/code identity.');
                }
                $this->sources->verify($source['seals']);

                return ['status' => 'REUSED', 'path' => $destination, 'summary' => Files::json($destination.'/summary.json')];
            }
            $stage = Files::directory($root.'/.staging/'.$id.'-'.bin2hex(random_bytes(8)));
            try {
                JsonlArtifact::json($stage.'/request.json', $request);
                JsonlArtifact::json($stage.'/sources.json', ['frozen_at' => gmdate(DATE_ATOM)] + $source);
                JsonlArtifact::json($stage.'/code.json', $code);
                JsonlArtifact::write($stage.'/fixed.jsonl', $this->fixedRows($source['selection']['targets']));
                JsonlArtifact::write($stage.'/results.jsonl', $this->sources->extract($source));
                $summary = $this->calculate($stage, $stage.'/fixed.jsonl', $stage.'/results.jsonl');
                $this->sources->verify($source['seals']);
                Files::same($code, $this->code(), 'end code integrity');
                JsonlArtifact::json($stage.'/source-end.json', ['checked_at' => gmdate(DATE_ATOM), 'status' => 'UNCHANGED', 'seals' => $source['seals']]);
                $manifest = $this->store->publish($stage, $destination, $request);

                return ['status' => 'RESULT_LOCKED', 'path' => $destination, 'manifest' => $manifest, 'summary' => $summary];
            } catch (Throwable $e) {
                JsonlArtifact::json($stage.'/failure.json', ['status' => 'FAILED_NOT_PUBLISHED', 'at' => gmdate(DATE_ATOM),
                    'type' => $e::class, 'message' => $e->getMessage(), 'all_targets_successful' => false]);
                throw $e;
            }
        });
    }

    public function reproduce(string $mode, string $root, string $id): array
    {
        Contract::mode($mode);
        Contract::id($id);
        $this->store->prepare($root, []);

        return $this->store->locked($root, $id, function () use ($root, $id): array {
            $path = $root.'/evaluations/'.$id;
            $manifest = $this->store->verify($path);
            Files::same(Files::json($path.'/code.json'), $this->code(), 'reproduction code identity');
            $stage = Files::directory($root.'/.staging/'.$id.'-reproduce-'.bin2hex(random_bytes(8)));
            try {
                $summary = $this->calculate($stage, $path.'/fixed.jsonl', $path.'/results.jsonl');
                foreach (['joined.jsonl', 'contributions.jsonl', 'summary.json'] as $file) {
                    Files::verify($stage.'/'.$file, $manifest['files'][$file]);
                }
                Files::same($manifest, $this->store->verify($path), 'reproduction end integrity');
                Files::same(Files::json($path.'/code.json'), $this->code(), 'reproduction end code integrity');
                $this->store->event($root, $id, ['status' => 'REPRODUCED', 'stage' => basename($stage),
                    'summary' => Files::identity($stage.'/summary.json'), 'database' => 'NONE', 'inference' => 'NONE']);

                return ['status' => 'REPRODUCED', 'path' => $stage, 'summary' => $summary, 'database' => 'NONE', 'inference' => 'NONE'];
            } catch (Throwable $e) {
                JsonlArtifact::json($stage.'/failure.json', ['status' => 'FAILED_REPRODUCTION', 'message' => $e->getMessage()]);
                throw $e;
            }
        });
    }

    private function calculate(string $stage, string $fixed, string $results): array
    {
        // Only offsets/IDs stay in memory; neither annual labels nor selected race payloads accumulate.
        $offsets = [];
        $offset = 0;
        foreach (JsonlArtifact::read($results) as $row) {
            $row = $this->matcher->result($row);
            $key = $row['year'].':'.$row['race_id'];
            if (isset($offsets[$key])) {
                throw new RuntimeException('Duplicate extracted race.');
            }
            $offsets[$key] = $offset;
            $offset += strlen(Files::canonical($row)."\n");
        }
        JsonlArtifact::write($stage.'/joined.jsonl', $this->joined($fixed, $results, $offsets));
        $summary = $this->metrics->emptySummary();
        $excluded = array_fill_keys(Bt03e05MetricEvaluator::METRIC_CODES, 0);
        JsonlArtifact::write($stage.'/contributions.jsonl', (function () use ($stage, &$summary, &$excluded): Generator {
            foreach (JsonlArtifact::read($stage.'/joined.jsonl') as $joined) {
                $row = $this->matcher->comparison($joined);
                $this->metrics->add($summary, $row['comparison']);
                foreach ($row['unevaluable'] as $metric => $_) {
                    $excluded[$metric]++;
                }
                yield $row;
            }
        })());
        $finished = $this->metrics->finish($summary);
        $display = [];
        foreach (Bt03e05MetricEvaluator::METRIC_CODES as $metric) {
            $denominator = $summary['denominators'][$metric];
            $display[$metric] = ['numerator' => $summary['candidate_numerators'][$metric],
                'baseline_numerator' => $summary['baseline_numerators'][$metric], 'denominator' => $denominator,
                'rate' => $denominator > 0 ? $finished['candidate'][$metric] : null,
                'baseline_rate' => $denominator > 0 ? $finished['baseline'][$metric] : null,
                'status' => $denominator > 0 ? 'EVALUABLE' : 'UNEVALUABLE_ZERO_DENOMINATOR', 'excluded_races' => $excluded[$metric]];
        }
        $result = ['purpose' => Contract::plan()['purpose'], 'matched' => $summary['race_count'], 'missing' => 0, 'mismatched' => 0,
            'metrics' => $display, 'accumulator' => $summary, 'evaluator' => $finished,
            'exact_ordered_top3_semantics' => 'SUPPORTING_MAP; primary exact order is separately in decoder_diagnostics',
            'hit_at_3_semantics' => 'POSITION_HITS / (3 * UNIQUE_ORDERED_TOP3_RACES)',
            'gate_ci_bootstrap' => 'NOT_RUN'];
        JsonlArtifact::json($stage.'/summary.json', $result);

        return $result;
    }

    private function joined(string $fixed, string $results, array $offsets): Generator
    {
        $handle = fopen($results, 'rb');
        try {
            foreach (JsonlArtifact::read($fixed) as $row) {
                $key = $row['input']['year'].':'.$row['input']['race_id'];
                if (! isset($offsets[$key]) || fseek($handle, $offsets[$key]) !== 0) {
                    throw new RuntimeException('Missing or duplicate matched prediction.');
                }
                unset($offsets[$key]);
                $result = json_decode(fgets($handle), true, flags: JSON_THROW_ON_ERROR);
                yield $this->matcher->join($row, $result);
            }
            if ($offsets !== []) {
                throw new RuntimeException('Extra extracted result races.');
            }
        } finally {
            fclose($handle);
        }
    }

    private function fixedRows(array $targets): Generator
    {
        foreach ($targets as $target) {
            yield $this->sources->fixed($target);
        }
    }

    private function code(): array
    {
        $files = glob(__DIR__.'/*.php');
        $files[] = app_path('Console/Commands/Keirin/TacticalPredictionResultCommand.php');
        foreach ([Bt03e05MetricEvaluator::class, JsonlArtifact::class, Files::class,
            ArtifactStore::class,
            ModelIdentity::class,
            \App\Domain\Keirin\Backtest\Experiments\TacticalPredictionPipeline\Contract::class,
            RaceEntryResultStatus::class] as $class) {
            $files[] = (new \ReflectionClass($class))->getFileName();
        }
        sort($files, SORT_STRING);
        $seals = [];
        foreach ($files as $file) {
            $seals[substr($file, strlen(base_path()) + 1)] = Files::identity($file);
        }

        return ['php' => PHP_VERSION, 'files' => $seals];
    }
}
