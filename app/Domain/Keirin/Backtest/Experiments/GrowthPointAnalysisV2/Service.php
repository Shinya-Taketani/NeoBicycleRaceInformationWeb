<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\GrowthPointAnalysisV2;

use App\Domain\Keirin\Backtest\Experiments\GrowthPointAnalysis\Signals;
use App\Domain\Keirin\Backtest\Experiments\GrowthPointAnalysis\Workspace;
use App\Domain\Keirin\Backtest\Experiments\TacticalGradeAnalysis\AnalysisStore;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionResult\ResultStore;
use RuntimeException;
use Throwable;

final class Service
{
    public function __construct(private readonly Sources $sources, private readonly Thresholds $thresholds,
        private readonly Evaluation $evaluation, private readonly Analysis $analysis, private readonly Signals $signals,
        private readonly Store $store, private readonly ResultStore $writer, private readonly AnalysisStore $csv) {}

    public function execute(string $root, string $id, string $sourceV1): array
    {
        return $this->run($root, $id, $this->sources->open($sourceV1), false);
    }

    public function reproduce(string $root, string $id): array
    {
        $this->id($id);
        $manifest = $this->store->verify($root.'/evaluations/'.$id);
        $source = Files::json($root.'/evaluations/'.$id.'/sources.json');
        Files::same($source, $this->sources->open($source['path']), 'v2 reproduce v1 source');

        return $this->run($root, $id, $source, true, $manifest);
    }

    private function run(string $root, string $id, array $source, bool $reproduce, ?array $original = null): array
    {
        $this->id($id);
        $this->writer->prepare($root, $source['files']);

        return $this->writer->locked($root, $id, function () use ($root, $id, $source, $reproduce, $original): array {
            $destination = $root.'/evaluations/'.$id;
            if (! $reproduce && (file_exists($destination) || is_link($destination))) {
                throw new RuntimeException('Existing v2 analysis: use reproduce.');
            }
            $code = $this->code($source);
            if ($reproduce) {
                Files::same(Files::json($destination.'/code.json'), $code, 'v2 reproduction code');
            }
            $stage = Files::directory($root.'/.staging/'.$id.($reproduce ? '-reproduce-' : '-').bin2hex(random_bytes(8)));
            $expected = [];
            try {
                $expected += $this->writer->writeJson($stage, 'contract.json', Contract::plan());
                $expected += $this->writer->writeJson($stage, 'sources.json', $source);
                $expected += $this->writer->writeJson($stage, 'code.json', $code);
                $expected += $this->writer->writeJson($stage, 'v1-source-verification.json', ['status' => 'VERIFIED', 'files' => $source['files'], 'database' => 'NONE']);
                $w = new Workspace($stage.'/workspace.sqlite');
                $w->cohort(JsonlArtifact::read($source['path'].'/cohort.jsonl'));
                $w->history(JsonlArtifact::read($source['path'].'/history.jsonl'));
                $w->training($this->signals);
                Files::same(Files::json($source['path'].'/thresholds.json'), $w->thresholds(), 'v1 historical training unchanged');
                $thresholds = $this->thresholds->calculate($w);
                $expected += $this->writer->writeJson($stage, 'thresholds-v2.json', $thresholds);
                $audit = [];
                $expected += $this->writer->writeJsonl($stage, 'growth-details-v2.jsonl', $this->evaluation->details($w, $source['path'], $thresholds, $audit));
                $transition = array_values($audit['transitions']);
                unset($audit['transitions']);
                $expected += $this->writer->writeJson($stage, 'raw-verification.json', $audit + ['status' => 'ALL_EXACTLY_EQUAL']);
                $expected += $this->writer->writeJson($stage, 'point-transition.json', $transition);
                $computed = $this->analysis->aggregate($w, $source['path']);
                $summary = $computed['summary'];
                if ($audit['entries'] !== array_sum(array_column($summary['year_totals'], 'entries')) || $audit['input_exact_matches'] !== $audit['entries']) {
                    throw new RuntimeException('v2 population mismatch.');
                }
                $expected += $this->writer->writeJson($stage, 'summary-v2.json', $summary);
                $csv = $this->csv->writeCsv($stage, $summary);
                if (! rename($stage.'/summary.csv', $stage.'/summary-v2.csv')) {
                    throw new RuntimeException('Cannot finalize v2 CSV.');
                }
                $expected['summary-v2.csv'] = $csv['summary.csv'];
                $expected += $this->writer->writeJson($stage, 'correlations-v2.json', $computed['correlations']);
                $expected += $this->writer->writeJson($stage, 'classification-v2.json', $computed['classification']);
                $expected += $this->writer->writeJson($stage, 'c1-diagnostics-v2.json', $computed['c1_diagnostics']);
                $expected += $this->writer->writeJson($stage, 'comparison-v1-v2.json', $computed['comparison']);
                $this->sources->verify($source);
                Files::same($code, $this->code($source), 'v2 end code');
                $expected += $this->writer->writeJson($stage, 'source-end.json', ['status' => 'UNCHANGED', 'files' => $source['files'], 'database' => 'NONE']);
                $this->writer->verifyGenerated($stage, $expected);
                unset($w);
                unlink($stage.'/workspace.sqlite');
                if ($reproduce) {
                    Files::same($original['files'], $expected, 'all reproduced v2 artifacts');
                    Files::same($original, $this->store->verify($destination), 'v2 reproduction end bundle');
                } else {
                    $this->store->publish($stage, $destination, $expected, $summary);
                }
                $result = ['status' => $reproduce ? 'REPRODUCED' : 'GROWTH_V2_LOCKED', 'path' => $reproduce ? $stage : $destination,
                    'year_totals' => $summary['year_totals'], 'raw_verification' => $audit,
                    'details' => $expected['growth-details-v2.jsonl'], 'summary' => $expected['summary-v2.json'], 'database' => 'NONE', 'peak_bytes' => memory_get_peak_usage(true)];
                $this->writer->event($root, $id, $result);

                return $result;
            } catch (Throwable $e) {
                JsonlArtifact::json($stage.'/failure.json', ['status' => 'FAILED_NOT_PUBLISHED', 'error' => $e->getMessage(), 'expected' => $expected]);
                throw $e;
            }
        });
    }

    private function id(string $id): void
    {
        if (preg_match('/\A[a-z0-9][a-z0-9-]{0,79}\z/D', $id) !== 1) {
            throw new RuntimeException('Invalid v2 analysis ID.');
        }
    }

    private function code(array $source): array
    {
        $files = $source['code']['files'];
        foreach (glob(__DIR__.'/*.php') as $path) {
            $files[substr($path, strlen(base_path()) + 1)] = Files::identity($path);
        }
        $command = 'app/Console/Commands/Keirin/GrowthPointAnalysisV2Command.php';
        $files[$command] = Files::identity(base_path($command));
        ksort($files, SORT_STRING);
        foreach ($files as $path => $seal) {
            Files::verify(base_path($path), $seal);
        }

        return ['php' => PHP_VERSION, 'files' => $files];
    }
}
