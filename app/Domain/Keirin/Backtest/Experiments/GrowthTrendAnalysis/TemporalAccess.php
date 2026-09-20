<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\GrowthTrendAnalysis;

use App\Domain\Keirin\Backtest\Experiments\GrowthTrendScoreSource\Contract as SourceContract;
use App\Domain\Keirin\Backtest\Experiments\GrowthTrendScoreSource\OuterSource;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use Generator;
use RuntimeException;

final class TemporalAccess
{
    private array $events = [];

    private ?string $stage = null;

    private array $sealed = [];

    private array $sources = [];

    private array $accesses = [];

    public function observe(string $kind): void
    {
        if (! in_array($kind, ['export_manifest_open', 'label_identity_resolve', 'label_file_open'], true)) {
            throw new RuntimeException('Unknown outcome access kind.');
        }
        $phase = $this->stage === null ? 'preseal' : 'postseal';
        $key = $phase.'_'.$kind.'_count';
        $this->accesses[$key] = ($this->accesses[$key] ?? 0) + 1;
        if ($phase === 'preseal') {
            throw new RuntimeException('Outcome access path reached before trend seal.');
        }
    }

    public function counts(): array
    {
        $counts = [];
        foreach (['preseal', 'postseal'] as $phase) {
            foreach (['export_manifest_open', 'label_identity_resolve', 'label_file_open'] as $kind) {
                $key = $phase.'_'.$kind.'_count';
                $counts[$key] = $this->accesses[$key] ?? 0;
            }
        }

        return $counts;
    }

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

    public function outcomes(int $year, string $root, OuterSource $reader): Generator
    {
        SourceContract::year($year, true);
        $this->authorize();
        $this->record('OUTCOME_ACCESS_AUTHORIZED');
        $source = $reader->openOutcomeSource($root, $year, $this);
        $this->sources[$year] = $source;
        $this->record($year.'_OUTCOME_OPEN');
        $this->observe('label_file_open');
        yield from JsonlArtifact::read($source['path']);
    }

    public function sources(): array
    {
        $this->authorize();

        return $this->sources;
    }

    public function artifact(): array
    {
        $counts = $this->counts();

        return ['events' => $this->events, 'access_counts' => $counts,
            'counter_scope' => 'GUARDED_OUTCOME_RESOLUTION_AND_ITERATION_PATH_CALLS_NOT_OS_SYSCALLS',
            'preseal_outcome_access' => $counts['preseal_label_file_open_count'], 'preseal_label_hash_access' => $counts['preseal_label_identity_resolve_count'],
            'evaluation' => '2024_AND_2025_DEVELOPMENT_SELECTION_NOT_HOLDOUT', '2026_access' => 0];
    }
}
