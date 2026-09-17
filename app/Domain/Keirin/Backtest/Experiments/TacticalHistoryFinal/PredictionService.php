<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;

final class PredictionService
{
    public function __construct(private readonly ModelLoader $loader) {}

    public function run(string $artifact, string $input, string $output): array
    {
        $seal = Files::identity($artifact);
        $model = $this->loader->published($artifact);
        $rows = function () use ($artifact, $input, $model, $seal): \Generator {
            yield from $this->loader->predictions($input, $model);
            Files::verify($artifact, $seal);
        };

        return JsonlArtifact::write($output, $rows());
    }
}
