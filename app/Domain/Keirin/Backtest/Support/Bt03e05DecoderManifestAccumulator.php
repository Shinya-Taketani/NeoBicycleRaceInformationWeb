<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Support;

use App\Domain\Keirin\Backtest\Services\Bt03e05Contract;
use RuntimeException;

final class Bt03e05DecoderManifestAccumulator
{
    private ?\HashContext $hash;

    private int $raceCount = 0;

    private ?int $year = null;

    /** @var array{version:string,race_count:int,semantic_sha256:string}|null */
    private ?array $manifest = null;

    public function __construct(private readonly CanonicalHasher $hasher)
    {
        $this->hash = hash_init('sha256');
        hash_update($this->hash, Bt03e05Contract::DECODER_MANIFEST_VERSION."\n");
    }

    /** @param array<string,mixed> $decision */
    public function append(array $decision): void
    {
        if ($this->hash === null || $this->manifest !== null) {
            throw new RuntimeException('BT-03E-05 decoder manifest was not writable.');
        }
        $year = $decision['year'] ?? null;
        $raceId = $decision['race_id'] ?? null;
        if (! in_array($year, Bt03e05Contract::DEVELOPMENT_YEARS, true)
            || ! is_int($raceId) || $raceId < 1 || ($this->year !== null && $this->year !== $year)) {
            throw new RuntimeException('BT-03E-05 decoder manifest race identity was invalid.');
        }
        foreach (['rank', 'status', 'winner', 'official', 'result'] as $forbidden) {
            if ($this->containsKey($decision, $forbidden, ['winner_tie_count', 'PRIMARY_WINNER_P1'])) {
                throw new RuntimeException('BT-03E-05 decoder manifest contained outcome data.');
            }
        }
        $required = [
            'year', 'race_id', 'primary_position_1_bike', 'primary_position_2_bike', 'primary_position_3_bike',
            'primary_position_1_probability', 'primary_position_2_probability', 'primary_position_3_probability',
            'primary_second_third_objective_score', 'map_ordered_top3', 'map_ordered_probability',
            'map_top3_set', 'map_top3_set_probability', 'top2_marginal_bikes', 'top3_marginal_bikes',
            'expected_ndcg_top3', 'winner_tie_count', 'second_third_tie_count', 'primary_decision_tied',
            'primary_technical_tiebreak_used', 'decoder_tie_diagnostics',
        ];
        if (array_diff($required, array_keys($decision)) !== []) {
            throw new RuntimeException('BT-03E-05 decoder manifest payload was incomplete.');
        }
        $semantic = array_intersect_key($decision, array_flip($required));
        hash_update($this->hash, "{$year}|{$raceId}|".$this->hasher->hash($semantic)."\n");
        $this->raceCount++;
        $this->year = $year;
    }

    /** @return array{version:string,race_count:int,semantic_sha256:string} */
    public function seal(): array
    {
        if ($this->hash === null || $this->manifest !== null || $this->raceCount < 1) {
            throw new RuntimeException('BT-03E-05 decoder manifest could not be sealed.');
        }
        hash_update($this->hash, "COUNT|{$this->raceCount}\n");
        $this->manifest = [
            'version' => Bt03e05Contract::DECODER_MANIFEST_VERSION,
            'race_count' => $this->raceCount,
            'semantic_sha256' => hash_final($this->hash),
        ];
        $this->hash = null;

        return $this->manifest;
    }

    /** @param array<string,mixed> $value */
    private function containsKey(array $value, string $needle, array $allowedKeys = []): bool
    {
        foreach ($value as $key => $item) {
            if (is_string($key) && ! in_array($key, $allowedKeys, true) && str_contains(strtolower($key), $needle)) {
                return true;
            }
            if (is_array($item) && $this->containsKey($item, $needle, $allowedKeys)) {
                return true;
            }
        }

        return false;
    }
}
