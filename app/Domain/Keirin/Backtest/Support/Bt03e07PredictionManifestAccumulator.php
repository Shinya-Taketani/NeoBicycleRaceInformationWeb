<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Support;

use App\Domain\Keirin\Backtest\Services\Bt03e07Contract;
use RuntimeException;

final class Bt03e07PredictionManifestAccumulator
{
    private ?\HashContext $hash;

    private int $raceCount = 0;

    /** @var array<string,mixed>|null */
    private ?array $manifest = null;

    /** @param array<string,mixed> $sourceIdentity */
    public function __construct(private readonly int $year, private readonly array $sourceIdentity, private readonly CanonicalHasher $hasher)
    {
        if (! in_array($year, Bt03e07Contract::OUTER_YEARS, true)) {
            throw new RuntimeException('BT-03E-07 prediction manifest year was invalid.');
        }
        $this->hash = hash_init('sha256');
        hash_update($this->hash, Bt03e07Contract::PREDICTION_MANIFEST_VERSION."\n");
        hash_update($this->hash, $this->hasher->hash($sourceIdentity)."\n");
    }

    /** @param array<string,mixed> $decision */
    public function append(array $decision): void
    {
        if ($this->hash === null || $this->manifest !== null || ($decision['year'] ?? null) !== $this->year
            || ! is_int($decision['race_id'] ?? null) || $decision['race_id'] < 1) {
            throw new RuntimeException('BT-03E-07 prediction manifest race identity was invalid.');
        }
        foreach (['rank', 'status', 'label', 'actual', 'payout', 'result'] as $forbidden) {
            if ($this->containsKey($decision, $forbidden)) {
                throw new RuntimeException('BT-03E-07 prediction manifest contained official outcome data.');
            }
        }
        $required = [
            'year', 'race_id', 'primary_position_1_bike', 'primary_position_2_bike', 'primary_position_3_bike',
            'source_p1', 'selected_d2', 'selected_d3', 'primary_second_third_objective_score',
            'direct_p2_distribution_sha256', 'direct_p3_distribution_sha256', 'map_ordered_top3',
            'map_ordered_probability', 'map_top3_set', 'map_top3_set_probability', 'top2_marginal_bikes',
            'top3_marginal_bikes', 'expected_ndcg_top3', 'winner_tie_count', 'second_third_tie_count',
            'primary_decision_tied', 'primary_technical_tiebreak_used', 'decoder_tie_diagnostics', 'p1_freeze_verified',
        ];
        if (array_diff($required, array_keys($decision)) !== []) {
            throw new RuntimeException('BT-03E-07 prediction manifest payload was incomplete.');
        }
        $semantic = array_intersect_key($decision, array_flip($required));
        $semantic['source_e03_identity'] = $this->sourceIdentity;
        hash_update($this->hash, $this->year.'|'.$decision['race_id'].'|'.$this->hasher->hash($semantic)."\n");
        $this->raceCount++;
    }

    /** @return array<string,mixed> */
    public function seal(): array
    {
        if ($this->hash === null || $this->manifest !== null || $this->raceCount < 1) {
            throw new RuntimeException('BT-03E-07 prediction manifest could not be sealed.');
        }
        hash_update($this->hash, "COUNT|{$this->raceCount}\n");
        $this->manifest = [
            'version' => Bt03e07Contract::PREDICTION_MANIFEST_VERSION,
            'year' => $this->year,
            'race_count' => $this->raceCount,
            'source_e03_identity' => $this->sourceIdentity,
            'semantic_sha256' => hash_final($this->hash),
        ];
        $this->hash = null;

        return $this->manifest;
    }

    /** @param array<string,mixed> $value */
    private function containsKey(array $value, string $needle): bool
    {
        foreach ($value as $key => $item) {
            if (is_string($key) && str_contains(strtolower($key), $needle)) {
                return true;
            }
            if (is_array($item) && $this->containsKey($item, $needle)) {
                return true;
            }
        }

        return false;
    }
}
