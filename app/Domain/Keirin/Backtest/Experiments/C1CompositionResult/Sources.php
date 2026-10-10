<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\C1CompositionResult;

use App\Domain\Keirin\Backtest\Experiments\C1CompositionRequest\Contract as Request;
use App\Domain\Keirin\Backtest\Experiments\C1CompositionRequest\Store as Requests;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact as Jsonl;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionResult\Matcher;
use Generator;
use PDO;
use RuntimeException;

class Sources
{
    public function __construct(
        private readonly Requests $requests,
        private readonly Matcher $matcher,
        public readonly string $labels = Contract::LABELS,
        public readonly array $labelSeal = Contract::LABEL_SEAL,
        public readonly array $races = Contract::RACES,
    ) {}

    public function capture(string $root, string $selectionPath): array
    {
        Requests::safe($root);
        Requests::safe($selectionPath);
        $selectionSeal = Files::identity($selectionPath);
        $selection = Files::json($selectionPath);
        if (array_keys($selection) !== ['result_year', 'targets'] || $selection['result_year'] !== 2025
            || ! is_array($selection['targets']) || ! array_is_list($selection['targets'])
            || count($selection['targets']) < 1 || count($selection['targets']) > 10
            || count($selection['targets']) !== count($this->races)) {
            throw new RuntimeException('Invalid explicit selection / forbidden year or target count.');
        }
        $seen = $ids = $fixed = [];
        $seals = [$selectionPath => $selectionSeal];
        $seals[$root.'/STORE.json'] = Files::identity($root.'/STORE.json');
        foreach ($selection['targets'] as $target) {
            if (! is_array($target) || array_keys($target) !== ['year', 'race_id', 'request_id', 'manifest']
                || $target['year'] !== 2025 || ! is_int($target['race_id']) || ! in_array($target['race_id'], $this->races, true)
                || ! is_string($target['request_id']) || isset($seen[$target['race_id']]) || isset($ids[$target['request_id']])) {
                throw new RuntimeException('Duplicate or mismatched request selection.');
            }
            Request::id($target['request_id']);
            if ($target['request_id'] !== 'dev-composition-2025-r'.$target['race_id'].'-validation-fix-01') {
                throw new RuntimeException('Unexpected fixed request_id.');
            }
            $seen[$target['race_id']] = $ids[$target['request_id']] = true;
            $saved = $this->requests->read($root, $target['request_id']) ?? throw new RuntimeException('Request NOT_FOUND.');
            Files::verify($saved['path'].'/manifest.json', $target['manifest']);
            $request = $saved['manifest']['request'];
            if ($request['year'] !== $target['year'] || $request['race_id'] !== $target['race_id']) {
                throw new RuntimeException('Selected request target mismatch.');
            }
            Files::same(['artifact' => Request::ARTIFACT_SEAL, 'receipt' => Request::RECEIPT_SEAL, 'input' => Request::INPUT_SEAL],
                $request['sources'], 'fixed model / input reference');
            Files::same(Request::code(), $request['code'], 'fixed request runtime code');
            foreach ([...Request::FILES, 'manifest.json', 'COMPLETE.json'] as $name) {
                $path = $saved['path'].'/'.$name;
                $seals[$path] = Files::identity($path);
            }
            $fixed[] = ['request_id' => $target['request_id'], 'input' => $saved['input'], 'prediction' => $saved['prediction']];
        }
        Requests::safe($this->labels);
        Files::verify($this->labels, $this->labelSeal);
        Files::same($this->labelSeal, Files::json($this->labels.'.manifest.json'), 'fixed label sidecar');
        $seals[$this->labels] = Files::identity($this->labels);
        $seals[$this->labels.'.manifest.json'] = Files::identity($this->labels.'.manifest.json');
        Files::verify($selectionPath, $selectionSeal);

        return ['selection' => $selection, 'seals' => $seals, 'fixed' => $fixed, 'request_root' => $root,
            'labels' => $this->labels, 'label_seal' => $this->labelSeal];
    }

    public function results(array $source): Generator
    {
        $wanted = array_fill_keys(array_column($source['selection']['targets'], 'race_id'), true);
        // This is an ephemeral SQLite identity spool, never the application/production DB.
        $db = new PDO('sqlite:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('PRAGMA cache_size=-1024');
        $db->exec('CREATE TABLE seen (race_id INTEGER PRIMARY KEY)');
        $insert = $db->prepare('INSERT INTO seen VALUES (?)');
        $db->beginTransaction();
        try {
            foreach (Jsonl::read($this->labels) as $row) {
                if (! is_array($row) || ($row['year'] ?? null) !== 2025 || ! is_int($row['race_id'] ?? null) || $row['race_id'] < 1) {
                    throw new RuntimeException('Invalid annual label race/year.');
                }
                $insert->execute([$row['race_id']]);
                if (isset($wanted[$row['race_id']])) {
                    unset($wanted[$row['race_id']]);
                    yield $this->matcher->result($row);
                }
            }
        } finally {
            $db->rollBack();
        }
        if ($wanted !== []) {
            throw new RuntimeException('Missing selected results: '.implode(',', array_keys($wanted)));
        }
        $this->end($source);
    }

    public function end(array $source): void
    {
        foreach ($source['seals'] as $path => $seal) {
            Files::verify($path, $seal);
        }
    }
}
