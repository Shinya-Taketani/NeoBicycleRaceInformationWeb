<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\GrowthTrendAnalysis;

use App\Domain\Keirin\Backtest\Experiments\GrowthTrendScoreSource\Contract as SourceContract;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use Generator;
use RuntimeException;

final class TemporalAccess
{
    private array $events = [];

    private ?string $stage = null;

    private array $sealed = [];

    public function record(string $event): void
    {
        $this->events[] = ['sequence' => count($this->events) + 1, 'event' => $event];
    }

    public function seal(string $stage, array $expected): void
    {
        if ($this->stage !== null) {
            throw new RuntimeException('Trend already sealed.');
        }
        foreach (['contract.json', 'candidate-grid.json', 'code.json', 'trend-input.jsonl', 'trend-input.jsonl.manifest.json'] as $name) {
            Files::verify($stage.'/'.$name, $expected[$name] ?? []);
        }
        Files::same($expected, Files::json($stage.'/trend-input-seal.json'), 'trend seal');
        $this->stage = $stage;
        $this->sealed = $expected;
        $this->record('TREND_INPUT_SEALED');
    }

    public function authorize(): void
    {
        if ($this->stage === null) {
            throw new RuntimeException('Trend must be sealed before outcome access.');
        }
        foreach ($this->sealed as $name => $seal) {
            Files::verify($this->stage.'/'.$name, $seal);
        }
        Files::same($this->sealed, Files::json($this->stage.'/trend-input-seal.json'), 'immutable trend seal');
    }

    public function outcomes(int $year, string $path, array $files): Generator
    {
        SourceContract::year($year, true);
        $this->authorize();
        $this->record('OUTCOME_ACCESS_AUTHORIZED');
        $this->record($year.'_OUTCOME_OPEN');
        foreach ([$path, $path.'.manifest.json'] as $file) {
            Files::verify($file, $files[$file] ?? []);
        }
        yield from JsonlArtifact::read($path);
    }

    public function artifact(): array
    {
        return ['events' => $this->events, 'preseal_outcome_access' => 0,
            'evaluation' => '2024_AND_2025_DEVELOPMENT_SELECTION_NOT_HOLDOUT', '2026_access' => 0];
    }
}
