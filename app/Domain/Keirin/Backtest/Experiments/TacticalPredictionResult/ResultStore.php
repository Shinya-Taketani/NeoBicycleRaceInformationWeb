<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\TacticalPredictionResult;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionPipeline\ArtifactStore;
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
        foreach ($manifest['files'] as $name => $seal) {
            Files::verify($path.'/'.$name, $seal);
        }
        Files::same($manifest['request'], Files::json($path.'/request.json'), 'result request');

        return $manifest;
    }

    public function publish(string $stage, string $destination, array $request): array
    {
        $files = [];
        foreach (self::INVENTORY as $name) {
            $files[$name] = Files::identity($stage.'/'.$name);
        }
        JsonlArtifact::json($stage.'/manifest.json', ['status' => 'RESULT_LOCKED', 'locked_at' => gmdate(DATE_ATOM),
            'contract' => Contract::plan(), 'request' => $request, 'files' => $files]);
        JsonlArtifact::json($stage.'/LOCKED.json', Files::identity($stage.'/manifest.json'));
        $manifest = $this->verify($stage);
        if (file_exists($destination) || is_link($destination) || ! rename($stage, $destination)) {
            throw new RuntimeException('Cannot publish results without overwrite.');
        }

        return $manifest;
    }

    public function event(string $root, string $id, array $event): void
    {
        JsonlArtifact::json($root.'/events/'.$id.'-'.bin2hex(random_bytes(12)).'.json',
            ['evaluation_id' => $id, 'at' => gmdate(DATE_ATOM)] + $event);
    }
}
