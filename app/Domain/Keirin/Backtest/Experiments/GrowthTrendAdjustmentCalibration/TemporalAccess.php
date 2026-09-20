<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\GrowthTrendAdjustmentCalibration;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use RuntimeException;

final class TemporalAccess
{
    private array $seals = [];

    private array $events = [];

    public function record(string $event, ?int $year = null): void
    {
        if ($year !== null) {
            Contract::year($year);
        }
        $this->events[] = ['sequence' => count($this->events) + 1, 'event' => $event, 'year' => $year];
    }

    public function seal(string $kind, string $stage, array $expected): void
    {
        if (! in_array($kind, ['signal-scaling', 'selection'], true) || isset($this->seals[$kind])) {
            throw new RuntimeException('Invalid/repeated temporal seal.');
        }
        if ($kind === 'selection') {
            $this->requireSeal('signal-scaling');
        }
        foreach ([$kind.'.json', $kind.'-seal.json'] as $name) {
            Files::verify($stage.'/'.$name, $expected[$name]);
        }
        Files::same($expected[$kind.'.json'], Files::json($stage.'/'.$kind.'-seal.json'), 'temporal seal');
        $this->seals[$kind] = ['stage' => $stage, 'files' => array_intersect_key($expected, array_flip([$kind.'.json', $kind.'-seal.json']))];
        $this->record($kind === 'selection' ? 'SELECTION_SEALED' : 'SCALING_SEALED');
    }

    private function requireSeal(string $kind): void
    {
        $seal = $this->seals[$kind] ?? throw new RuntimeException($kind.' must be sealed before outcome access.');
        foreach ($seal['files'] as $name => $identity) {
            Files::verify($seal['stage'].'/'.$name, $identity);
        }
    }

    public function authorize(int $year, string $event): void
    {
        Contract::year($year);
        $this->requireSeal('signal-scaling');
        if ($year === 2025) {
            $this->requireSeal('selection');
        }
        $this->record($event, $year);
    }

    public function artifact(): array
    {
        $this->requireSeal('selection');

        return ['events' => $this->events, 'database' => 'NONE', '2026_access' => 0,
            'counter_scope' => 'GUARDED_OUTCOME_RESOLUTION_AND_ITERATION_PATHS', '2025_role' => Contract::plan()['year_roles'][2025]];
    }
}
