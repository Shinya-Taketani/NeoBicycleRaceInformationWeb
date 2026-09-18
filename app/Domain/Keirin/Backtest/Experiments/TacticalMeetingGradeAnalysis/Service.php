<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\TacticalMeetingGradeAnalysis;

use App\Domain\Keirin\Backtest\Calculators\Bt03e05MetricEvaluator;
use App\Domain\Keirin\Backtest\Experiments\TacticalGradeAnalysis\Aggregator as RiderAggregator;
use App\Domain\Keirin\Backtest\Experiments\TacticalGradeAnalysis\AnalysisStore;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionPipeline\ArtifactStore;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionPipeline\ReadOnlySession;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionResult\Matcher;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionResult\ResultStore;
use App\Domain\Keirin\Backtest\Services\Bt03e02Contract;
use App\Domain\Keirin\Scraping\Enums\RaceEntryResultStatus;
use ReflectionClass;
use RuntimeException;
use Throwable;

final class Service
{
    public function __construct(private readonly Sources $sources, private readonly MetadataSource $metadata,
        private readonly Classification $classification, private readonly Aggregator $aggregator,
        private readonly Store $store, private readonly ResultStore $writer) {}

    public function execute(string $root, string $id, string $sourceBundle): array
    {
        $this->id($id);
        $source = $this->sources->open($sourceBundle);
        $this->writer->prepare($root, $source['files']);

        return $this->writer->locked($root, $id, function () use ($root, $id, $source): array {
            $destination = $root.'/evaluations/'.$id;
            if (file_exists($destination) || is_link($destination)) {
                throw new RuntimeException('Analysis already exists; reproduce instead.');
            }
            $code = $this->code();
            $stage = Files::directory($root.'/.staging/'.$id.'-'.bin2hex(random_bytes(8)));
            $expected = [];
            try {
                $expected += $this->writer->writeJson($stage, 'contract.json', Contract::plan());
                $expected += $this->writer->writeJson($stage, 'sources.json', $source);
                $expected += $this->writer->writeJson($stage, 'code.json', $code);
                $start = $this->metadata->capture($source['input'], $stage);
                $expected += $start['expected'];
                unset($start['expected']);
                $expected += $this->writer->writeJson($stage, 'metadata-start.json', $start);
                $meetings = $this->classification->meetings(JsonlArtifact::read($stage.'/metadata.jsonl'));
                $expected += $this->writer->writeJson($stage, 'meetings.json', $meetings);
                $expected += $this->writer->writeJsonl($stage, 'analysis-input.jsonl', $this->sources->rows($source, $stage.'/metadata.jsonl'));
                $summary = $this->calculate($stage, $stage.'/analysis-input.jsonl', $meetings, $source['reference'], $expected);
                $this->writer->verifyGenerated($stage, $expected, $summary);
                $end = $this->metadata->verify($source['input'], $start['digest']);
                $this->sources->verify($source);
                Files::same($code, $this->code(), 'meeting end code');
                $expected += $this->writer->writeJson($stage, 'source-end.json', ['status' => 'UNCHANGED',
                    'files' => $source['files'], 'metadata' => $end, 'at' => gmdate(DATE_ATOM)]);
                $manifest = $this->store->publish($stage, $destination, $expected, $summary);

                return $this->receipt('MEETING_ANALYSIS_LOCKED', $destination, $summary, $manifest['files']);
            } catch (Throwable $e) {
                JsonlArtifact::json($stage.'/failure.json', ['status' => 'FAILED_NOT_PUBLISHED', 'message' => $e->getMessage(), 'expected' => $expected]);
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
            Files::same(Files::json($path.'/code.json'), $this->code(), 'meeting reproduction code');
            $meetings = $this->classification->meetings(JsonlArtifact::read($path.'/metadata.jsonl'));
            Files::same(Files::json($path.'/meetings.json'), $meetings, 'reproduced classification');
            $stage = Files::directory($root.'/.staging/'.$id.'-reproduce-'.bin2hex(random_bytes(8)));
            $expected = [];
            try {
                $summary = $this->calculate($stage, $path.'/analysis-input.jsonl', $meetings, Files::json($path.'/sources.json')['reference'], $expected);
                $this->writer->verifyGenerated($stage, $expected, $summary);
                foreach ($expected as $file => $seal) {
                    Files::same($manifest['files'][$file], $seal, 'reproduced '.$file);
                }
                Files::same($manifest, $this->store->verify($path), 'reproduction end bundle');
                Files::same(Files::json($path.'/code.json'), $this->code(), 'reproduction end code');
                $result = $this->receipt('REPRODUCED', $stage, $summary, $expected) + ['database' => 'NONE'];
                $this->writer->event($root, $id, $result);

                return $result;
            } catch (Throwable $e) {
                JsonlArtifact::json($stage.'/failure.json', ['status' => 'FAILED_REPRODUCTION', 'message' => $e->getMessage(), 'expected' => $expected]);
                throw $e;
            }
        });
    }

    private function calculate(string $stage, string $input, array $meetings, array $reference, array &$expected): array
    {
        $summary = [];
        $expected += $this->writer->writeJsonl($stage, 'details.jsonl', $this->aggregator->details(JsonlArtifact::read($input), $meetings, $reference, $summary));
        $expected += $this->writer->writeJson($stage, 'summary.json', $summary);
        $expected += $this->store->writeCsv($stage, $summary);

        return $summary;
    }

    private function receipt(string $status, string $path, array $summary, array $files): array
    {
        return ['status' => $status, 'path' => $path, 'races' => $summary['races'], 'year_totals' => $summary['year_totals'],
            'coverage' => $summary['coverage'], 'details' => $files['details.jsonl'], 'summary' => $files['summary.json'],
            'peak_bytes' => memory_get_peak_usage(true)];
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
        $paths[] = app_path('Console/Commands/Keirin/TacticalMeetingGradeAnalysisCommand.php');
        foreach ([Bt03e05MetricEvaluator::class, Bt03e02Contract::class, RiderAggregator::class, AnalysisStore::class,
            JsonlArtifact::class, Files::class, ResultStore::class, Matcher::class, ArtifactStore::class, ReadOnlySession::class,
            RaceEntryResultStatus::class, \App\Domain\Keirin\Backtest\Experiments\TacticalGradeAnalysis\Contract::class,
            \App\Domain\Keirin\Backtest\Experiments\TacticalPredictionResult\Contract::class] as $class) {
            $paths[] = (new ReflectionClass($class))->getFileName();
        }
        // Seal the inspected provenance path too; the scraping implementation is not modified.
        foreach (['app/Repositories/RaceRepository.php', 'app/Domain/Keirin/Scraping/Parsers/RaceScheduleParser.php',
            'app/Domain/Keirin/Scraping/Parsers/RaceDayMetadataParser.php'] as $relative) {
            $paths[] = base_path($relative);
        }
        sort($paths, SORT_STRING);
        $files = [];
        foreach ($paths as $path) {
            $files[substr($path, strlen(base_path()) + 1)] = Files::identity($path);
        }

        return ['php' => PHP_VERSION, 'files' => $files];
    }
}
