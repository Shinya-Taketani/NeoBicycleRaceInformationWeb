<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\TacticalPredictionResult;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionPipeline\ArtifactStore;
use Generator;
use RuntimeException;

class ResultStore
{
    private const INVENTORY = ['request.json', 'sources.json', 'code.json', 'fixed.jsonl', 'fixed.jsonl.manifest.json',
        'results.jsonl', 'results.jsonl.manifest.json', 'joined.jsonl', 'joined.jsonl.manifest.json',
        'contributions.jsonl', 'contributions.jsonl.manifest.json', 'summary.json', 'source-end.json'];

    public function __construct(private readonly ArtifactStore $paths) {}

    public function prepare(string $root, array $sources): void
    {
        $this->paths->root($root);
        // No output directories are created until all reference overlap checks pass.
        foreach (array_keys($sources) as $path) {
            $parent = dirname($path);
            if ($root === $parent || str_starts_with($root, $parent.'/') || str_starts_with($path, $root.'/')) {
                throw new RuntimeException('Result output overlaps an input source.');
            }
        }
        foreach (['evaluations', '.staging', '.locks', 'events'] as $name) {
            $path = $root.'/'.$name;
            if (is_link($path) || (! is_dir($path) && ! mkdir($path, 0755))) {
                throw new RuntimeException('Unsafe result directory.');
            }
        }
    }

    public function locked(string $root, string $id, callable $work): array
    {
        return $this->paths->locked($root, $id, $work);
    }

    public function verify(string $path): array
    {
        if (realpath($path) !== $path || ! is_dir($path) || is_link($path)) {
            throw new RuntimeException('Missing or unsafe result bundle.');
        }
        Files::verify($path.'/manifest.json', Files::json($path.'/LOCKED.json'));
        $manifest = Files::json($path.'/manifest.json');
        Files::same(Contract::plan(), $manifest['contract'] ?? [], 'result contract');
        if (($manifest['status'] ?? null) !== 'RESULT_LOCKED' || array_keys($manifest['files'] ?? []) !== self::INVENTORY) {
            throw new RuntimeException('Invalid result inventory/status.');
        }
        $this->verifyGenerated($path, $manifest['files']);
        Files::same($manifest['request'], Files::json($path.'/request.json'), 'result request');

        return $manifest;
    }

    public function publish(string $stage, string $destination, array $request, array $expected, array $summary): array
    {
        if (array_keys($expected) !== self::INVENTORY) {
            throw new RuntimeException('Incomplete write-time artifact inventory.');
        }
        foreach ($expected as $name => $seal) {
            if (str_ends_with($name, '.jsonl') && ! is_int($seal['rows'] ?? null)) {
                throw new RuntimeException('Missing write-time JSONL row count.');
            }
        }
        $this->verifyGenerated($stage, $expected, $summary);
        $manifestSeal = $this->writeJson($stage, 'manifest.json', ['status' => 'RESULT_LOCKED', 'locked_at' => gmdate(DATE_ATOM),
            'contract' => Contract::plan(), 'request' => $request, 'files' => $expected,
            'generation_verification' => ['policy' => 'WRITE_TIME_SEALS_AND_SUMMARY_STRICT_EQUALITY-v1',
                'checked_at' => gmdate(DATE_ATOM), 'files' => count($expected), 'jsonl_streams' => 4]]);
        $lockSeal = $this->writeJson($stage, 'LOCKED.json', $manifestSeal['manifest.json']);
        $manifest = $this->verify($stage);
        $this->verifyGenerated($stage, $manifestSeal + $lockSeal);
        if (file_exists($destination) || is_link($destination) || ! rename($stage, $destination)) {
            throw new RuntimeException('Cannot publish results without overwrite.');
        }

        return $manifest;
    }

    public function writeJson(string $directory, string $name, array $data): array
    {
        $seal = $this->jsonSeal($data);
        JsonlArtifact::json($directory.'/'.$name, $data);
        Files::verify($directory.'/'.$name, $seal);

        return [$name => $seal];
    }

    public function writeJsonl(string $directory, string $name, iterable $rows): array
    {
        $count = $bytes = 0;
        $hash = hash_init('sha256');
        // Hash the emitted bytes, not a later read of a possibly modified stage file.
        $stream = (function () use ($rows, &$count, &$bytes, $hash): Generator {
            foreach ($rows as $row) {
                $line = Files::canonical($row)."\n";
                $count++;
                $bytes += strlen($line);
                hash_update($hash, $line);
                yield $row;
            }
        })();
        $written = JsonlArtifact::write($directory.'/'.$name, $stream);
        $seal = ['rows' => $count, 'bytes' => $bytes, 'sha256' => hash_final($hash)];
        Files::same($seal, $written, 'JSONL write-time receipt');
        $expected = [$name => $seal, $name.'.manifest.json' => $this->jsonSeal($seal)];
        $this->verifyGenerated($directory, $expected);

        return $expected;
    }

    public function verifyGenerated(string $directory, array $expected, ?array $summary = null): void
    {
        foreach ($expected as $name => $seal) {
            Files::verify($directory.'/'.$name, $seal);
        }
        foreach ($expected as $name => $seal) {
            if (! str_ends_with($name, '.jsonl')) {
                continue;
            }
            if (! isset($expected[$name.'.manifest.json'])) {
                throw new RuntimeException('Missing JSONL sidecar seal.');
            }
            $sidecar = Files::json($directory.'/'.$name.'.manifest.json');
            // Legacy bundles have no row count in files[]. Their already-sealed sidecar supplies it.
            $rows = $seal['rows'] ?? $sidecar['rows'] ?? null;
            if (! is_int($rows) || $rows < 0) {
                throw new RuntimeException('Invalid JSONL row count.');
            }
            Files::same(['rows' => $rows, 'bytes' => $seal['bytes'], 'sha256' => $seal['sha256']], $sidecar, 'JSONL body/sidecar');
            foreach (JsonlArtifact::read($directory.'/'.$name) as $_) {
                // Fully drain the bounded reader to verify rows, bytes, hash, and JSON syntax.
            }
        }
        if ($summary !== null) {
            Files::same($summary, Files::json($directory.'/summary.json'), 'generated summary');
        }
        foreach ($expected as $name => $seal) {
            Files::verify($directory.'/'.$name, $seal);
        }
    }

    private function jsonSeal(array $data): array
    {
        // Match JsonlArtifact::json's byte encoding without changing the shared writer.
        $text = json_encode($data, JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

        return ['bytes' => strlen($text), 'sha256' => hash('sha256', $text)];
    }

    public function event(string $root, string $id, array $event): void
    {
        JsonlArtifact::json($root.'/events/'.$id.'-'.bin2hex(random_bytes(12)).'.json',
            ['evaluation_id' => $id, 'at' => gmdate(DATE_ATOM)] + $event);
    }
}
