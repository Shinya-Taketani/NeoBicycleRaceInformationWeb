<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\GrowthAdjustmentCalibration;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use RuntimeException;

final class TemporalAccess
{
    private array $events = [];

    private ?string $stage = null;

    private array $selection = [];

    private array $sealFile = [];

    public function seal(string $stage, array $selection, array $sealFile): void
    {
        if ($this->stage !== null) {
            throw new RuntimeException('Selection can only be sealed once.');
        }
        Files::verify($stage.'/selection.json', $selection);
        Files::verify($stage.'/selection-seal.json', $sealFile);
        Files::same($selection, Files::json($stage.'/selection-seal.json'), 'selection seal');
        $this->stage = $stage;
        $this->selection = $selection;
        $this->sealFile = $sealFile;
        $this->record(['phase' => 'SELECTION_SEALED', 'selection_sha256' => $selection['sha256']]);
    }

    public function requireSelection(): void
    {
        if ($this->stage === null) {
            throw new RuntimeException('Selection must be sealed before 2025 outcome access.');
        }
        Files::verify($this->stage.'/selection.json', $this->selection);
        Files::verify($this->stage.'/selection-seal.json', $this->sealFile);
    }

    public function phase(int $year, string $phase): void
    {
        $this->authorize($year);
        $this->record(['phase' => $phase, 'year' => $year, 'outcome_access' => true]);
    }

    public function opening(int $year, string $kind): void
    {
        $this->authorize($year);
        if (! in_array($kind, ['labels', 'contributions'], true)) {
            throw new RuntimeException('Unknown outcome source kind.');
        }
        $this->record(['phase' => 'OUTCOME_SOURCE_OPEN', 'year' => $year, 'source_kind' => $kind, 'outcome_access' => true]);
    }

    public function artifact(): array
    {
        $this->requireSelection();
        $sealed = $first = null;
        foreach ($this->events as $event) {
            if ($event['phase'] === 'SELECTION_SEALED') {
                $sealed = $event['sequence'];
            }
            if (($event['year'] ?? null) === 2025 && ($event['outcome_access'] ?? false)) {
                $first ??= $event['sequence'];
            }
        }
        if ($sealed === null || $first === null || $first <= $sealed) {
            throw new RuntimeException('Invalid temporal access order.');
        }

        return ['events' => $this->events, 'selection_sealed_sequence' => $sealed,
            'first_2025_outcome_access_sequence' => $first, 'preseal_2025_outcome_accesses' => 0];
    }

    private function authorize(int $year): void
    {
        if (! in_array($year, Contract::YEARS, true)) {
            throw new RuntimeException('Forbidden outcome year.');
        }
        if ($year === 2025) {
            $this->requireSelection();
        }
    }

    private function record(array $event): void
    {
        $this->events[] = ['sequence' => count($this->events) + 1] + $event;
    }
}
