<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\TacticalPredictionPipeline;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use RuntimeException;

class ArtifactStore
{
    public function root(string $root): string
    {
        $base = realpath(config('tactical_prediction_pipeline.artifact_base'));
        $resolved = realpath($root);
        if ($base === false || $resolved === false || $root !== $resolved || ! is_dir($root) || is_link($root)
            || ! str_starts_with($root, $base.'/') || str_starts_with($root, base_path().'/')) {
            throw new RuntimeException('Output root must be an existing dedicated directory under the agreed artifact base.');
        }
        foreach (['tactical-history-final-01-20260917-01', 'tactical-history-01-review-fix-20260916-01'] as $source) {
            if ($root === $base.'/'.$source || str_starts_with($root, $base.'/'.$source.'/')) {
                throw new RuntimeException('Output root cannot be an original source directory.');
            }
        }

        return $root;
    }

    public function prepareRoot(string $root): void
    {
        $root = $this->root($root);
        foreach (['requests', '.locks', '.staging', 'events'] as $name) {
            $path = $root.'/'.$name;
            if (is_link($path) || (! is_dir($path) && ! mkdir($path, 0755))) {
                throw new RuntimeException('Unsafe artifact subdirectory.');
            }
        }
    }

    public function locked(string $root, string $id, callable $work): array
    {
        $path = $root.'/.locks/'.$id.'.lock';
        if (is_link($path)) {
            throw new RuntimeException('Unsafe lock path.');
        }
        $handle = fopen($path, 'c');
        if ($handle === false) {
            throw new RuntimeException('Cannot open request lock.');
        }
        try {
            if (! flock($handle, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('BUSY: request is already executing.');
            }

            return $work();
        } finally {
            fclose($handle);
        }
    }

    public function event(string $root, string $requestId, array $event): void
    {
        JsonlArtifact::json($root.'/events/'.$requestId.'-'.bin2hex(random_bytes(12)).'.json',
            ['request_id' => $requestId, 'recorded_at' => gmdate(DATE_ATOM)] + $event);
    }

    public function verify(string $path): array
    {
        if (! is_dir($path) || is_link($path)) {
            throw new RuntimeException('Locked request is missing or unsafe.');
        }
        $seal = Files::json($path.'/LOCKED.json');
        Files::verify($path.'/manifest.json', $seal);
        $manifest = Files::json($path.'/manifest.json');
        if (($manifest['status'] ?? null) !== 'DEVELOPMENT_REPLAY_LOCKED') {
            throw new RuntimeException('Request was not locked.');
        }
        Files::same(Contract::plan(), $manifest['contract'] ?? [], 'pipeline contract');
        $required = ['request.json', 'model.json', 'artifact.json', 'input.jsonl', 'input.jsonl.manifest.json',
            'prediction.jsonl', 'prediction.jsonl.manifest.json', 'audit.json', 'source-end.json', 'code.json'];
        if (array_keys($manifest['files'] ?? []) !== $required) {
            throw new RuntimeException('Locked request file inventory disagreed.');
        }
        foreach ($manifest['files'] as $name => $fileSeal) {
            Files::verify($path.'/'.$name, $fileSeal);
        }
        Files::same($manifest['request'], Files::json($path.'/request.json'), 'locked request identity');

        return $manifest;
    }

    public function publish(string $stage, string $destination): void
    {
        $this->verify($stage);
        if (file_exists($destination) || is_link($destination) || ! rename($stage, $destination)) {
            throw new RuntimeException('Cannot atomically publish request without overwrite.');
        }
    }
}
