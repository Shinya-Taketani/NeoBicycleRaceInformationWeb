<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Support;

use App\Domain\Keirin\Backtest\Services\Bt03e04Contract;
use RuntimeException;

final class Bt03e04DecoderManifestAccumulator
{
    private ?\HashContext $hash;

    private int $raceCount = 0;

    private ?int $year = null;

    /** @var array{version:string,race_count:int,semantic_sha256:string}|null */
    private ?array $manifest = null;

    public function __construct(private readonly CanonicalHasher $hasher)
    {
        $this->hash = hash_init('sha256');
        hash_update($this->hash, Bt03e04Contract::DECODER_MANIFEST_VERSION."\n");
    }

    /** @param array<string,mixed> $decision */
    public function append(array $decision): void
    {
        if ($this->hash === null || $this->manifest !== null) {
            throw new RuntimeException('BT-03E-04 decoder manifest was not writable.');
        }
        $year = $decision['year'] ?? null;
        $raceId = $decision['race_id'] ?? null;
        if (! in_array($year, Bt03e04Contract::DEVELOPMENT_YEARS, true)
            || ! is_int($raceId) || $raceId < 1 || ($this->year !== null && $this->year !== $year)) {
            throw new RuntimeException('BT-03E-04 decoder manifest race identity was invalid.');
        }
        foreach (['rank', 'status', 'winner', 'official', 'result'] as $forbidden) {
            if ($this->containsKey($decision, $forbidden)) {
                throw new RuntimeException('BT-03E-04 decoder manifest contained outcome data.');
            }
        }
        $required = [
            'year', 'race_id', 'primary_position_1_bike', 'primary_position_2_bike', 'primary_position_3_bike',
            'primary_objective_score', 'argmax_p1_bike', 'argmax_p1_probability', 'map_ordered_top3',
            'map_ordered_probability', 'map_top3_set', 'map_top3_set_probability', 'top2_marginal_bikes',
            'top3_marginal_bikes', 'expected_ndcg_top3', 'primary_tie_count',
            'primary_technical_tiebreak_used', 'decoder_tie_diagnostics',
        ];
        if (array_diff($required, array_keys($decision)) !== []) {
            throw new RuntimeException('BT-03E-04 decoder manifest payload was incomplete.');
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
            throw new RuntimeException('BT-03E-04 decoder manifest could not be sealed.');
        }
        hash_update($this->hash, "COUNT|{$this->raceCount}\n");
        $this->manifest = [
            'version' => Bt03e04Contract::DECODER_MANIFEST_VERSION,
            'race_count' => $this->raceCount,
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
