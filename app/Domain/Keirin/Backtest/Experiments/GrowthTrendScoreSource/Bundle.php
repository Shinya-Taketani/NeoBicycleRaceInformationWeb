<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\GrowthTrendScoreSource;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionResult\ResultStore;
use RuntimeException;

class Bundle
{
    public function __construct(public readonly ResultStore $writer) {}

    public function prepare(string $root, string $id, array $files): void
    {
        if (! preg_match('/\A[a-z0-9][a-z0-9-]{0,119}\z/D', $id) || file_exists($root.'/evaluations/'.$id)) {
            throw new RuntimeException('New valid artifact ID required; overwrite forbidden.');
        }
        $this->writer->prepare($root, $files);
    }

    public function publish(string $stage, string $destination, array $contract, array $expected): array
    {
        $this->writer->verifyGenerated($stage, $expected);
        $seal = $this->writer->writeJson($stage, 'manifest.json', ['status' => 'LOCKED', 'contract' => $contract, 'files' => $expected]);
        $this->writer->writeJson($stage, 'LOCKED.json', $seal['manifest.json']);
        $manifest = $this->verify($stage, $contract);
        if (file_exists($destination) || ! rename($stage, $destination)) {
            throw new RuntimeException('Atomic publication refused.');
        }

        return $manifest;
    }

    public function verify(string $path, array $contract): array
    {
        if (realpath($path) !== $path) {
            throw new RuntimeException('Canonical bundle path required.');
        }
        Files::verify($path.'/manifest.json', Files::json($path.'/LOCKED.json'));
        $manifest = Files::json($path.'/manifest.json');
        Files::same($contract, $manifest['contract'], 'bundle contract');
        if ($manifest['status'] !== 'LOCKED') {
            throw new RuntimeException('Not LOCKED.');
        }
        foreach (array_keys($manifest['files']) as $name) {
            if (basename($name) !== $name) {
                throw new RuntimeException('Unsafe bundle member.');
            }
        }
        $this->writer->verifyGenerated($path, $manifest['files']);

        return $manifest;
    }
}
