<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Presentation\CompositionResultView;

use App\Domain\Keirin\Backtest\Experiments\C1CompositionRequest\Contract as RequestContract;
use App\Domain\Keirin\Backtest\Experiments\C1CompositionRequest\Store as RequestStore;
use App\Domain\Keirin\Backtest\Experiments\C1CompositionResult\Store;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use RuntimeException;

final class Reader
{
    public function __construct(private readonly Store $store, private readonly RequestStore $requests) {}

    public function read(): array
    {
        $root = $this->store->root(config('composition_result_view.root'));
        $id = config('composition_result_view.evaluation_id');
        RequestContract::id($id);
        $path = $root.'/evaluations/'.$id;
        RequestStore::safe($path.'/manifest.json');
        $pin = config('composition_result_view.manifest');
        Files::verify($path.'/manifest.json', $pin);
        $saved = $this->store->verify($path, $id);
        Files::verify($path.'/manifest.json', $pin);

        return $saved;
    }

    public function detail(array $saved, int $raceId): ?array
    {
        $target = null;
        foreach ($saved['manifest']['request']['selection']['targets'] as $row) {
            if ($row['race_id'] === $raceId) {
                $target = $row;
                break;
            }
        }
        if ($target === null) {
            return null;
        }
        $copied = $this->requests->verify($saved['path'].'/requests/'.$target['request_id'], $target['request_id']);
        Files::verify($copied['path'].'/manifest.json', $target['manifest']);
        $joined = null;
        foreach (JsonlArtifact::read($saved['path'].'/joined.jsonl') as $row) {
            if ($row['context']['race_id'] === $raceId && $row['request_id'] === $target['request_id']) {
                $joined = $row;
            }
        }
        Files::verify($saved['path'].'/joined.jsonl', $saved['manifest']['files']['joined.jsonl']);
        Files::verify($saved['path'].'/manifest.json', config('composition_result_view.manifest'));
        if ($joined === null) {
            throw new RuntimeException('Verified detail is missing.');
        }
        Files::same($copied['prediction'], $joined['prediction'], 'copied prediction / displayed detail');

        return ['request_id' => $target['request_id'], 'prediction' => $copied['prediction'], 'context' => $joined['context']];
    }
}
