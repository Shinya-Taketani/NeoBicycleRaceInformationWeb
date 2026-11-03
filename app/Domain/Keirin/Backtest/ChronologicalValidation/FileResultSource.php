<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\ChronologicalValidation;

final readonly class FileResultSource implements ResultSource
{
    // Do not stat, hash or read any result source during construction/preflight.
    public function __construct(private string $root, private Scope $scope, private Store $store) {}

    public function read(string $day, array $receipt): iterable
    {
        $this->scope->target($day);
        $opened = $this->store->get($receipt);
        BundleRules::require($opened['kind'] === 'day_manifest' && $opened['data']['state'] === 'RESULTS_OPENED' && $opened['data']['date'] === $day,
            'Verified opening receipt for this day required before result file access.');
        $run = $this->store->get($this->store->reference('run'))['data'];
        BundleRules::require(Json::hash($run['scope']) === Json::hash(['kind' => $this->scope->kind, 'from' => $this->scope->from, 'to' => $this->scope->to]), 'Result scope/header mismatch.');
        $path = $this->root.'/'.$day.'.json';
        Json::file($path);
        BundleRules::require(filesize($path) <= 32 * 1024 * 1024, 'Bounded daily result file capacity exceeded.');
        $bytes = file_get_contents($path);
        $seal = ['bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
        $document = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        unset($bytes);
        BundleRules::require($document['material_kind'] === $this->scope->kind && $document['date'] === $day, 'Result source scope/day mismatch.');
        foreach ($document['results'] as $row) {
            if (! isset($opened['data']['predictions'][$row['external_race_key']])) {
                BundleRules::require(in_array($row['external_race_key'], array_column($opened['data']['candidates'], 'external_race_key'), true), 'Result outside fixed candidate set.');

                continue;
            }
            yield [...$row, 'source_seal' => $seal];
        }
        Json::verify($path, $seal);
    }
}
