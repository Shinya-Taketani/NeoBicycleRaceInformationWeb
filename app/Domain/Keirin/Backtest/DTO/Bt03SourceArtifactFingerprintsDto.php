<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\DTO;

readonly class Bt03SourceArtifactFingerprintsDto
{
    public function __construct(
        public string $runAndFoldFingerprint,
        public string $signalSpecFingerprint,
        public string $modelFingerprint,
        public string $metricFingerprint,
        public string $effectBinFingerprint,
        public string $manifestHash,
    ) {}

    /** @return array<string, string> */
    public function canonical(): array
    {
        return [
            'run_and_fold' => $this->runAndFoldFingerprint,
            'signal_specs' => $this->signalSpecFingerprint,
            'models' => $this->modelFingerprint,
            'metrics' => $this->metricFingerprint,
            'effect_bins' => $this->effectBinFingerprint,
        ];
    }
}
