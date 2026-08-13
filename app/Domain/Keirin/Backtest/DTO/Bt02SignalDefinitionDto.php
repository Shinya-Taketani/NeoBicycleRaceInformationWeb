<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\DTO;

use App\Domain\Keirin\Backtest\Enums\Bt02AnalysisRole;

readonly class Bt02SignalDefinitionDto
{
    /** @param list<string> $operationalAllowedQualityReasons */
    public function __construct(
        public string $statCode,
        public string $subjectType,
        public Bt02AnalysisRole $analysisRole,
        public string $primaryFeatureCode,
        public ?string $primaryFeaturePath,
        public string $transformCode,
        public array $operationalAllowedQualityReasons,
    ) {}

    public function permitsOperationalUse(): bool
    {
        return $this->analysisRole === Bt02AnalysisRole::EntryIncremental;
    }
}
