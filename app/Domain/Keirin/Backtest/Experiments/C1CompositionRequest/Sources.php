<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\C1CompositionRequest;

use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Input;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Package;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use RuntimeException;

class Sources
{
    public function __construct(public readonly string $artifact = Contract::PACKAGE, public readonly string $input = Contract::INPUT,
        public readonly array $artifactSeal = Contract::ARTIFACT_SEAL, public readonly array $receiptSeal = Contract::RECEIPT_SEAL,
        public readonly array $inputSeal = Contract::INPUT_SEAL, public readonly array $years = [2025]) {}

    public function reference(): array
    {
        return ['artifact' => $this->artifactSeal, 'receipt' => $this->receiptSeal, 'input' => $this->inputSeal];
    }

    public function year(int $year): void
    {
        if (! in_array($year, [2024, 2025], true) || ! in_array($year, $this->years, true)) {
            throw new RuntimeException('Forbidden request year; no input opened.');
        }
    }

    public function load(Package $packages, ?string $artifact = null): array
    {
        $artifact ??= $this->artifact;
        Files::verify($artifact, $this->artifactSeal);
        Files::verify(dirname($artifact).'/RELEASE_COMMITTED.json', $this->receiptSeal);

        return $packages->load($artifact);
    }

    public function capture(): array
    {
        Files::verify($this->input, $this->inputSeal);
        $seals = [];
        foreach (['', '.manifest.json', '.input.json'] as $suffix) {
            $seals[$suffix] = Files::identity($this->input.$suffix);
        }

        return $seals;
    }

    public function extract(int $year, int $raceId): array
    {
        $this->year($year);
        $found = null;
        // Exhaustion is required even after finding the target: late duplicate and end seals matter.
        foreach (Input::read($this->input) as $race) {
            if ($race['year'] !== $year) {
                throw new RuntimeException('Annual input year mismatch.');
            }
            if ($race['race_id'] === $raceId) {
                if ($found !== null) {
                    throw new RuntimeException('Duplicate target race.');
                }
                $found = $race;
            }
        }

        return $found ?? throw new RuntimeException('Target race NOT_FOUND in fixed input.');
    }

    public function end(array $seals): void
    {
        foreach ($seals as $suffix => $seal) {
            Files::verify($this->input.$suffix, $seal);
        }
    }
}
