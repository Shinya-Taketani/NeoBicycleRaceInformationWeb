<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Audit\Stat35DataReadiness;

use App\Console\Commands\Keirin\Stat35DataReadinessAuditCommand;
use App\Domain\Keirin\Backtest\Experiments\GrowthTrendScoreSource\OuterSource;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalMeetingGradeAnalysis\Classification;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionPipeline\ArtifactStore;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionPipeline\ReadOnlySession;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionResult\ResultStore;
use App\Domain\Keirin\Scraping\Parsers\EmbeddedJsonExtractor;
use App\Domain\Keirin\Scraping\Support\CharacterEncodingConverter;
use App\Domain\Keirin\Scraping\Support\HtmlTextNormalizer;
use RuntimeException;

final class Service
{
    public function __construct(private readonly Source $source, private readonly Targets $targets,
        private readonly ResultStore $writer, private readonly ArtifactStore $paths, private readonly RawReader $raw) {}

    public function execute(string $root, string $id, string $outer): array
    {
        $this->id($id);
        $this->paths->root($root);
        if (file_exists($root.'/'.$id)) {
            throw new RuntimeException('Audit ID already exists; no overwrite.');
        }
        if ($outer === $root || str_starts_with($root, $outer.'/') || str_starts_with($outer, $root.'/')) {
            throw new RuntimeException('Audit output overlaps source.');
        }
        $targets = $this->targets->open($outer);
        $code = $this->code();
        $stage = Files::directory($root.'/.stage-'.$id);
        $expected = $this->writer->writeJson($stage, 'contract.json', Contract::plan());
        $expected += $this->writer->writeJson($stage, 'code.json', $code);
        $expected += $this->writer->writeJson($stage, 'source-scope.json', ['from' => Contract::FROM, 'to' => Contract::TO, 'target_sources' => $targets['files']]);
        $expected += $this->writer->writeJsonl($stage, 'targets.jsonl', $this->targets->identities($targets));
        $start = [];
        $expected += $this->source->session(function () use ($stage, &$start): array {
            return $this->writer->writeJsonl($stage, 'database-inventory.jsonl', $this->source->rows($start));
        });
        $expected += $this->writer->writeJson($stage, 'source-start.json', $start);
        $result = $this->analysis($stage, $stage.'/database-inventory.jsonl', $stage.'/targets.jsonl');
        $expected += $result['expected'];
        $end = [];
        $this->source->session(function () use (&$end): void {
            foreach ($this->source->rows($end) as $_) {
            }
        });
        Files::same($start, $end, 'database START/END');
        $rawCount = $this->verifyRaw($stage.'/raw-source-inventory.jsonl');
        Targets::verify($targets['files']);
        Files::same($code, $this->code(), 'direct code START/END');
        $expected += $this->writer->writeJson($stage, 'source-end.json', ['database' => $end, 'raw_verified' => $rawCount,
            'database_unchanged' => true, 'raw_unchanged' => true, 'code_unchanged' => true, 'outer_unchanged' => true]);
        $expected += $this->writer->writeJson($stage, 'db-query-audit.json', $this->source->audit);
        $expected += $this->writer->writeJson($stage, 'sample-pages.json', $this->samples($stage.'/raw-source-inventory.jsonl'));
        $this->writer->verifyGenerated($stage, $expected);
        $manifest = ['status' => 'AUDIT_LOCKED', 'audit_id' => $id, 'contract' => Contract::plan(), 'files' => $expected, 'summary' => $result['summary']];
        $seal = $this->writer->writeJson($stage, 'manifest.json', $manifest);
        $this->writer->writeJson($stage, 'LOCKED.json', $seal['manifest.json']);
        $this->verify($stage);
        if (file_exists($root.'/'.$id) || ! rename($stage, $root.'/'.$id)) {
            throw new RuntimeException('Cannot publish without overwrite.');
        }

        return ['status' => 'AUDIT_EXECUTED', 'path' => $root.'/'.$id, 'counts' => $result['counts'],
            'readiness' => $result['summary'], 'peak_memory_bytes' => memory_get_peak_usage(true)];
    }

    public function reproduce(string $root, string $id): array
    {
        $this->id($id);
        $this->paths->root($root);
        $path = $root.'/'.$id;
        $manifest = $this->verify($path);
        $code = $this->code();
        Files::same(Files::json($path.'/code.json'), $code, 'reproduce code');
        $this->verifyRaw($path.'/raw-source-inventory.jsonl');
        $stage = Files::directory($root.'/.reproduce-'.$id);
        $result = $this->analysis($stage, $path.'/database-inventory.jsonl', $path.'/targets.jsonl');
        foreach ($result['expected'] as $name => $seal) {
            Files::same($manifest['files'][$name] ?? [], $seal, 'byte-exact '.$name);
        }
        $this->writer->verifyGenerated($stage, $result['expected']);
        $this->verifyRaw($path.'/raw-source-inventory.jsonl');
        $this->verify($path);
        Files::same($code, $this->code(), 'reproduce END code');
        $report = ['status' => 'BYTE_EXACT', 'db' => 'NONE', 'files_compared' => count($result['expected']),
            '2026_access_count' => 0, 'peak_memory_bytes' => memory_get_peak_usage(true)];
        $this->writer->writeJson($stage, 'reproduce.json', $report);

        return $report;
    }

    private function analysis(string $stage, string $source, string $targets): array
    {
        return (new Analysis($this->writer, new Extractor, $this->raw))->run($stage, $source, $targets);
    }

    public function verify(string $path): array
    {
        if (realpath($path) !== $path) {
            throw new RuntimeException('Noncanonical audit bundle.');
        }
        Files::verify($path.'/manifest.json', Files::json($path.'/LOCKED.json'));
        $manifest = Files::json($path.'/manifest.json');
        if (($manifest['status'] ?? null) !== 'AUDIT_LOCKED') {
            throw new RuntimeException('Audit not locked.');
        }
        Files::same(Contract::plan(), $manifest['contract'], 'audit contract');
        foreach (array_keys($manifest['files']) as $name) {
            if (! preg_match('/\A[a-z0-9_.-]+\z/D', $name) || str_contains($name, '..')) {
                throw new RuntimeException('Unsafe audit artifact name.');
            }
        }
        $this->writer->verifyGenerated($path, $manifest['files']);

        return $manifest;
    }

    private function verifyRaw(string $inventory): int
    {
        $n = 0;
        foreach (JsonlArtifact::read($inventory) as $raw) {
            if (($raw['extraction_status'] ?? '') === 'RAW_MISSING') {
                if (file_exists($raw['absolute_path']) || is_link($raw['absolute_path'])) {
                    throw new RuntimeException('Missing Raw inventory drifted.');
                }
            } else {
                $this->raw->verify($raw);
                $n++;
            }
        }

        return $n;
    }

    private function samples(string $inventory): array
    {
        $sample = $counts = [];
        foreach (JsonlArtifact::read($inventory) as $row) {
            $year = substr($row['race_date'], 0, 4);
            if (($counts[$year] ?? 0) < 13) {
                $sample[] = $row;
                $counts[$year] = ($counts[$year] ?? 0) + 1;
            }
        }

        return ['rule' => 'FIRST_13_IMPORTS_PER_YEAR_RACE_ID_IMPORT_ID_ASC', 'pages' => $sample,
            'preimplementation_evidence' => 'Parent header-preinspection.json: 71 real pages, TIED/ABNORMAL/S_NINE supplementation'];
    }

    public function code(): array
    {
        $paths = glob(__DIR__.'/*.php');
        foreach ([Stat35DataReadinessAuditCommand::class, JsonlArtifact::class, Files::class,
            ArtifactStore::class, ResultStore::class, ReadOnlySession::class,
            OuterSource::class,
            Classification::class,
            HtmlTextNormalizer::class,
            CharacterEncodingConverter::class,
            EmbeddedJsonExtractor::class] as $class) {
            $paths[] = (new \ReflectionClass($class))->getFileName();
        }
        $paths[] = base_path('composer.lock');
        $paths[] = base_path('app/Domain/Keirin/Backtest/Experiments/GrowthTrendScoreSource/Contract.php');
        $paths[] = config_path('tactical_prediction_pipeline.php');
        sort($paths);
        $code = [];
        foreach (array_unique($paths) as $path) {
            $code[substr($path, strlen(base_path()) + 1)] = Files::identity($path);
        }

        return $code;
    }

    private function id(string $id): void
    {
        if (! preg_match('/\A[a-z0-9][a-z0-9-]{1,100}\z/D', $id)) {
            throw new RuntimeException('Invalid audit ID.');
        }
    }
}
