<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\GrowthPointAnalysis;

use App\Domain\Keirin\Backtest\Experiments\TacticalGradeAnalysis\Aggregator;
use App\Domain\Keirin\Backtest\Experiments\TacticalGradeAnalysis\AnalysisStore;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalMeetingGradeAnalysis\Classification;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionPipeline\ArtifactStore;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionPipeline\ReadOnlySession;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionResult\ResultStore;
use App\Domain\Keirin\Statistics\Calculators\Stat10Calculator;
use App\Domain\Keirin\Statistics\Calculators\Stat24Calculator;
use App\Domain\Keirin\Statistics\Repositories\HistoricalRaceRepository;
use App\Domain\Keirin\Statistics\Support\StatisticalMath;
use ReflectionClass;
use RuntimeException;
use Throwable;

final class Service
{
    public function __construct(private readonly Sources $sources, private readonly HistorySource $history, private readonly Signals $signals,
        private readonly Analysis $analysis, private readonly Store $store, private readonly ResultStore $writer, private readonly AnalysisStore $csv) {}

    public function execute(string $root, string $id, string $sourceBundle): array
    {
        $this->id($id);
        $source = $this->sources->open($sourceBundle);
        $this->writer->prepare($root, $source['files']);

        return $this->writer->locked($root, $id, function () use ($root, $id, $source): array {
            $destination = $root.'/evaluations/'.$id;
            if (file_exists($destination) || is_link($destination)) {
                throw new RuntimeException('Use reproduce for an existing analysis.');
            }
            $stage = Files::directory($root.'/.staging/'.$id.'-'.bin2hex(random_bytes(8)));
            $code = $this->code();
            $expected = [];
            try {
                $expected += $this->writer->writeJson($stage, 'contract.json', Contract::plan());
                $expected += $this->writer->writeJson($stage, 'sources.json', $source);
                $expected += $this->writer->writeJson($stage, 'code.json', $code);
                $expected += $this->writer->writeJsonl($stage, 'cohort.jsonl', $this->sources->rows($source));
                $workspace = new Workspace($stage.'/workspace.sqlite');
                $workspace->cohort(JsonlArtifact::read($stage.'/cohort.jsonl'));
                $start = $this->history->capture($workspace, $stage);
                $expected += $start['expected'];
                unset($start['expected']);
                $expected += $this->writer->writeJson($stage, 'history-start.json', $start);
                $summary = $this->calculate($stage, $stage, $workspace, $expected);
                $end = $this->history->verify($workspace, $start['digest']);
                $this->sources->verify($source);
                Files::same($code, $this->code(), 'growth end code');
                $expected += $this->writer->writeJson($stage, 'source-end.json', ['status' => 'UNCHANGED', 'history' => $end, 'files' => $source['files']]);
                unset($workspace);
                unlink($stage.'/workspace.sqlite');
                $manifest = $this->store->publish($stage, $destination, $expected, $summary);

                return $this->receipt('GROWTH_ANALYSIS_LOCKED', $destination, $summary, $manifest['files']);
            } catch (Throwable $e) {
                JsonlArtifact::json($stage.'/failure.json', ['status' => 'FAILED_NOT_PUBLISHED', 'error' => $e->getMessage(), 'expected' => $expected]);
                throw $e;
            }
        });
    }

    public function reproduce(string $root, string $id): array
    {
        $this->id($id);
        $this->writer->prepare($root, []);

        return $this->writer->locked($root, $id, function () use ($root, $id): array {
            $path = $root.'/evaluations/'.$id;
            $manifest = $this->store->verify($path);
            Files::same(Files::json($path.'/code.json'), $this->code(), 'growth reproduction code');
            $stage = Files::directory($root.'/.staging/'.$id.'-reproduce-'.bin2hex(random_bytes(8)));
            $expected = [];
            try {
                $workspace = new Workspace($stage.'/workspace.sqlite');
                $workspace->cohort(JsonlArtifact::read($path.'/cohort.jsonl'));
                $summary = $this->calculate($stage, $path, $workspace, $expected);
                foreach ($expected as $file => $seal) {
                    Files::same($manifest['files'][$file], $seal, 'reproduced '.$file);
                }
                $this->writer->verifyGenerated($stage, $expected, $summary);
                Files::same($manifest, $this->store->verify($path), 'growth reproduction end bundle');
                Files::same(Files::json($path.'/code.json'), $this->code(), 'growth reproduction end code');
                unset($workspace);
                unlink($stage.'/workspace.sqlite');
                $result = $this->receipt('REPRODUCED', $stage, $summary, $expected) + ['database' => 'NONE'];
                $this->writer->event($root, $id, $result);

                return $result;
            } catch (Throwable $e) {
                JsonlArtifact::json($stage.'/failure.json', ['status' => 'FAILED_REPRODUCTION', 'error' => $e->getMessage(), 'expected' => $expected]);
                throw $e;
            }
        });
    }

    private function calculate(string $stage, string $input, Workspace $workspace, array &$expected): array
    {
        $workspace->history(JsonlArtifact::read($input.'/history.jsonl'));
        $workspace->training($this->signals);
        $thresholds = $workspace->thresholds();
        $expected += $this->writer->writeJson($stage, 'thresholds.json', $thresholds);
        $expected += $this->writer->writeJsonl($stage, 'growth-input.jsonl', $workspace->inputs());
        $expected += $this->writer->writeJsonl($stage, 'growth-details.jsonl', $this->analysis->details(JsonlArtifact::read($stage.'/growth-input.jsonl'), $thresholds, $workspace));
        $computed = $this->analysis->aggregate($workspace);
        $summary = $computed['summary'];
        $counts = $workspace->db->query('SELECT year,count(*) AS races FROM cohort GROUP BY year ORDER BY year')->fetchAll();
        foreach ($summary['year_totals'] as $i => $total) {
            if ($total['year'] !== $counts[$i]['year'] || $total['races'] !== $counts[$i]['races']) {
                throw new RuntimeException('All-entry cohort conservation failed.');
            }
        }
        if (array_sum(array_column($summary['year_totals'], 'entries')) !== (int) $workspace->db->query('SELECT count(*) FROM targets')->fetchColumn()) {
            throw new RuntimeException('Target entry conservation failed.');
        }
        $expected += $this->writer->writeJson($stage, 'summary.json', $summary);
        $expected += $this->csv->writeCsv($stage, $summary);
        $expected += $this->writer->writeJson($stage, 'correlations.json', $computed['correlations']);
        $expected += $this->writer->writeJson($stage, 'classification.json', $computed['classification']);
        $expected += $this->writer->writeJson($stage, 'c1-diagnostics.json', $computed['c1_diagnostics']);
        $this->writer->verifyGenerated($stage, $expected, $summary);

        return $summary;
    }

    private function receipt(string $status, string $path, array $summary, array $files): array
    {
        return ['status' => $status, 'path' => $path, 'year_totals' => $summary['year_totals'],
            'details' => $files['growth-details.jsonl'], 'summary' => $files['summary.json'], 'peak_bytes' => memory_get_peak_usage(true)];
    }

    private function id(string $id): void
    {
        if (preg_match('/\A[a-z0-9][a-z0-9-]{0,79}\z/D', $id) !== 1) {
            throw new RuntimeException('Invalid analysis ID.');
        }
    }

    private function code(): array
    {
        $paths = glob(__DIR__.'/*.php');
        $paths[] = app_path('Console/Commands/Keirin/GrowthPointAnalysisCommand.php');
        foreach ([AnalysisStore::class, JsonlArtifact::class, Files::class, ResultStore::class, ArtifactStore::class, ReadOnlySession::class,
            Aggregator::class,
            \App\Domain\Keirin\Backtest\Experiments\TacticalGradeAnalysis\Contract::class,
            \App\Domain\Keirin\Backtest\Experiments\TacticalMeetingGradeAnalysis\Store::class,
            \App\Domain\Keirin\Backtest\Experiments\TacticalMeetingGradeAnalysis\Contract::class,
            Classification::class,
            HistoricalRaceRepository::class,
            Stat10Calculator::class,
            Stat24Calculator::class,
            StatisticalMath::class] as $class) {
            $paths[] = (new ReflectionClass($class))->getFileName();
        }
        sort($paths, SORT_STRING);
        $files = [];
        foreach ($paths as $path) {
            $files[substr($path, strlen(base_path()) + 1)] = Files::identity($path);
        }

        return ['php' => PHP_VERSION, 'files' => $files];
    }
}
