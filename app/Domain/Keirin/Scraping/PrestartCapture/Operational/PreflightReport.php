<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\PrestartCapture\Operational;

use JsonSerializable;

final readonly class PreflightReport implements JsonSerializable
{
    public function __construct(
        public string $scenarioType,
        public array $decisionResults,
        public array $manifestValidation,
        public array $budgetValidation,
        public array $sourceValidation,
        public array $timingValidation,
        public array $additionalReasons = [],
    ) {}

    public function jsonSerialize(): array
    {
        $missingDecisions = $missingEvidence = $reasons = [];
        foreach ($this->decisionResults as $id => $result) {
            if ($result['validation_result'] !== 'SIMULATED_APPROVAL_VALIDATED') {
                $missingDecisions[] = $id;
            }
            foreach ($result['missing_evidence'] as $item) {
                $missingEvidence[] = $id.':'.$item;
            }
            foreach ($result['failure_reasons'] as $reason) {
                $reasons[] = $id.':'.$reason;
            }
        }
        foreach (['manifest' => $this->manifestValidation, 'budget' => $this->budgetValidation,
            'source' => $this->sourceValidation, 'timing' => $this->timingValidation] as $name => $result) {
            foreach ($result['failure_reasons'] as $reason) {
                $reasons[] = $name.':'.$reason;
            }
            foreach ($result['missing_evidence'] ?? [] as $item) {
                $missingEvidence[] = $name.':'.$item;
            }
        }
        $reasons = array_values(array_unique([...$reasons, ...$this->additionalReasons]));
        if ($this->scenarioType !== Contract::SIMULATED) {
            $reasons[] = 'REAL_OPERATION_NOT_AUTHORIZED_IN_THIS_PHASE';
        }

        return ['contract_version' => Contract::VERSION, 'scenario_type' => $this->scenarioType,
            'status' => $reasons === [] ? 'OFFLINE_SIMULATION_VALIDATED' : 'NOT_AUTHORIZED',
            'decision_results' => $this->decisionResults, 'manifest_validation' => $this->manifestValidation,
            'budget_validation' => $this->budgetValidation, 'source_validation' => $this->sourceValidation,
            'timing_validation' => $this->timingValidation, 'missing_decisions' => $missingDecisions,
            'missing_evidence' => array_values(array_unique($missingEvidence)), 'blocking_reasons' => $reasons,
            'second_source_status' => 'SECOND_SOURCE_NOT_CAPTURED', 'two_source_consistency' => 'NOT_ESTABLISHED',
            'http_calls' => 0, 'database_writes' => 0, 'model_updates' => 0, 'attempt_reservations' => 0,
            ...Contract::restrictions()];
    }

    public function json(): string
    {
        return json_encode($this, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    }
}
