<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\DTO;

use InvalidArgumentException;

readonly class Bt03eCandidateDto
{
    /** @param array<string, int> $weights */
    public function __construct(
        public int $baseStep,
        public array $weights,
    ) {
        if ($baseStep < 0) {
            throw new InvalidArgumentException('BT-03E base step must not be negative.');
        }
        foreach ($weights as $statCode => $weight) {
            if (! is_string($statCode) || ! is_int($weight) || $weight < 0) {
                throw new InvalidArgumentException('BT-03E stat weights were invalid.');
            }
        }
    }

    public function key(): string
    {
        return json_encode($this->canonical(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public function complexity(): int
    {
        return $this->baseStep + array_sum(array_map('abs', $this->weights));
    }

    /** @return array{base_step: int, weights: array<string, int>} */
    public function canonical(): array
    {
        $weights = $this->weights;
        ksort($weights, SORT_STRING);

        return ['base_step' => $this->baseStep, 'weights' => $weights];
    }
}
