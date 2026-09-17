<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\TacticalPredictionPipeline;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\ModelLoader;
use RuntimeException;

class ModelIdentity
{
    public function __construct(private readonly ModelLoader $loader) {}

    public function validate(string $artifact): void
    {
        if (Files::identity(dirname($artifact).'/model.json')['sha256'] !== $this->expectedHash()) {
            throw new RuntimeException('Only the reviewed final C1 model is permitted.');
        }
        $this->loader->published($artifact);
    }

    protected function expectedHash(): string
    {
        return Contract::MODEL_SHA256;
    }
}
