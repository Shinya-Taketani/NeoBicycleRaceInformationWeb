<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\TacticalPredictionPipeline;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final readonly class Request
{
    public DateTimeImmutable $asOf;

    public function __construct(public string $mode, public int $raceId, string $inputAsOf,
        public string $artifact, public string $requestId, public string $outputRoot)
    {
        $this->asOf = self::timestamp($inputAsOf);
        if ($mode !== Contract::MODE || $raceId < 1 || preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,95}\z/', $requestId) !== 1
            || ! in_array((int) $this->asOf->setTimezone(new DateTimeZone('Asia/Tokyo'))->format('Y'), [2022, 2023, 2024, 2025], true)) {
            throw new RuntimeException('Invalid development replay mode, identity or input_as_of year.');
        }
    }

    public static function timestamp(mixed $value): DateTimeImmutable
    {
        if (! is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}(?::?\d{2})?)\z/', $value) !== 1) {
            throw new RuntimeException('Missing or invalid timestamp with explicit timezone.');
        }
        $date = new DateTimeImmutable($value);
        if (DateTimeImmutable::getLastErrors() !== false) {
            throw new RuntimeException('Invalid calendar timestamp.');
        }

        return $date;
    }

    public function identity(array $artifactSeal, array $modelSeal): array
    {
        return ['request_id' => $this->requestId, 'race_id' => $this->raceId, 'mode' => $this->mode,
            'pipeline_version' => Contract::VERSION, 'input_as_of' => $this->asOf->format('Y-m-d\TH:i:s.uP'),
            'artifact' => $artifactSeal, 'model' => $modelSeal];
    }
}
