<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Support;

use App\Domain\Keirin\Backtest\DTO\Bt02PredictionManifestDto;
use RuntimeException;

class Bt02PredictionManifestAccumulator
{
    private ?\HashContext $baselineHash;

    private ?\HashContext $incrementalHash;

    private ?\HashContext $outcomeHash;

    private int $rowCount = 0;

    private int $raceCount = 0;

    private ?int $lastRaceId = null;

    private ?int $lastRaceEntryId = null;

    /** @var array<int, true> */
    private array $seenRaceIds = [];

    private ?Bt02PredictionManifestDto $manifests = null;

    /** @param array<string, int|string> $identity */
    public function __construct(array $identity)
    {
        $prefix = json_encode([
            'format_version' => Bt02PredictionSpool::FORMAT_VERSION,
            ...$identity,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
        $this->baselineHash = hash_init('sha256');
        $this->incrementalHash = hash_init('sha256');
        $this->outcomeHash = hash_init('sha256');
        hash_update($this->baselineHash, "BASELINE_MATCHED\n{$prefix}");
        hash_update($this->incrementalHash, "INCREMENTAL\n{$prefix}");
        hash_update($this->outcomeHash, "EVALUATION_OUTCOME\n{$prefix}");
    }

    public function append(int $raceId, int $raceEntryId, int $label, float $baseline, float $incremental): void
    {
        $this->assertWritable();
        if ($raceId < 1 || $raceEntryId < 1 || ! in_array($label, [0, 1], true)
            || ! is_finite($baseline) || ! is_finite($incremental)) {
            throw new RuntimeException('BT-02 prediction manifest row was invalid.');
        }
        if (($raceId === $this->lastRaceId && $raceEntryId <= $this->lastRaceEntryId)
            || ($raceId !== $this->lastRaceId && isset($this->seenRaceIds[$raceId]))) {
            throw new RuntimeException('BT-02 prediction manifest order or identity was invalid.');
        }
        if ($raceId !== $this->lastRaceId) {
            $this->seenRaceIds[$raceId] = true;
            $this->raceCount++;
        }
        $baselineText = sprintf('%.17g', $baseline);
        $incrementalText = sprintf('%.17g', $incremental);
        hash_update($this->baselineHash, "{$raceId},{$raceEntryId},{$baselineText}\n");
        hash_update($this->incrementalHash, "{$raceId},{$raceEntryId},{$incrementalText}\n");
        hash_update($this->outcomeHash, "{$raceId},{$raceEntryId},{$label}\n");
        $this->lastRaceId = $raceId;
        $this->lastRaceEntryId = $raceEntryId;
        $this->rowCount++;
    }

    public function seal(): Bt02PredictionManifestDto
    {
        $this->assertWritable();
        if ($this->rowCount === 0 || $this->raceCount === 0) {
            throw new RuntimeException('BT-02 prediction manifests could not be sealed without rows.');
        }
        $this->manifests = new Bt02PredictionManifestDto(
            $this->rowCount,
            $this->raceCount,
            hash_final($this->baselineHash),
            hash_final($this->incrementalHash),
            hash_final($this->outcomeHash),
        );
        $this->baselineHash = $this->incrementalHash = $this->outcomeHash = null;

        return $this->manifests;
    }

    private function assertWritable(): void
    {
        if ($this->manifests !== null || $this->baselineHash === null
            || $this->incrementalHash === null || $this->outcomeHash === null) {
            throw new RuntimeException('BT-02 prediction manifest accumulator is not writable.');
        }
    }
}
