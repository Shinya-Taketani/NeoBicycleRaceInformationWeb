<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\PrestartCapture\Operational;

use App\Domain\Keirin\Scraping\PrestartEvidence\Attributes\ScenarioTiming;
use App\Domain\Keirin\Statistics\Support\DeterministicJsonHasher;
use InvalidArgumentException;

final class Contract
{
    public const VERSION = 'PRESTART-CAPTURE-APPROVAL-PREFLIGHT-v1';

    public const SIMULATED = 'SIMULATED_SCENARIO';

    public const OPERATIONAL = 'OPERATIONAL_READINESS';

    public const DECISIONS = ['D1', 'D2', 'D3', 'D4', 'D5', 'D6', 'D7', 'D8'];

    public static function digest(array $value): string
    {
        return (new DeterministicJsonHasher)->hash($value);
    }

    public static function instant(mixed $value): ?int
    {
        return ScenarioTiming::instant($value);
    }

    public static function reference(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,159}$/D', $value) === 1;
    }

    public static function sha(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }

    public static function scenario(string $scenario): void
    {
        if (! in_array($scenario, [self::SIMULATED, self::OPERATIONAL], true)) {
            throw new InvalidArgumentException('Unsupported offline scenario type.');
        }
    }

    public static function result(array $reasons, array $extra = []): array
    {
        return ['validation_result' => $reasons === [] ? 'VALIDATED' : 'BLOCKED',
            'failure_reasons' => array_values(array_unique($reasons)), 'missing_evidence' => [], ...$extra];
    }

    public static function restrictions(): array
    {
        return ['historical_as_of_available' => false, 'prediction_use' => 'NOT_AUTHORIZED',
            'real_http_capture' => 'NOT_RUN_NOT_AUTHORIZED', 'production_storage' => 'NOT_RUN_NOT_AUTHORIZED',
            'formal_adoption' => false, 'official_publication_at' => null, 'points' => null, 'confidence' => null,
            '2026_holdout' => 'FROZEN_NO_ACCESS'];
    }
}
