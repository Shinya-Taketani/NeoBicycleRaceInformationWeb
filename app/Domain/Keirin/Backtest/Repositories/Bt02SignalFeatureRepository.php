<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Repositories;

use App\Domain\Keirin\Backtest\DTO\Bt02SignalFeatureDto;
use App\Domain\Keirin\Backtest\Services\Bt02SignalRegistry;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class Bt02SignalFeatureRepository
{
    public function __construct(private readonly Bt02SignalRegistry $registry) {}

    /** @param list<int> $raceIds @return array<int, list<Bt02SignalFeatureDto>> */
    public function forRaces(int $featureRunId, string $statCode, array $raceIds): array
    {
        if ($raceIds === []) {
            return [];
        }

        $grouped = [];
        $rows = DB::table('statistic_feature_results')
            ->select(['race_id', 'race_entry_id', 'status', 'quality_status', 'features', 'evidence'])
            ->where('feature_run_id', $featureRunId)
            ->where('stat_code', $statCode)
            ->whereIn('race_id', $raceIds)
            ->orderBy('race_id')
            ->orderBy('race_entry_id')
            ->get();

        foreach ($rows as $row) {
            if ($row->race_id === null || $row->race_entry_id === null) {
                throw new RuntimeException("{$statCode} yielded a non-entry feature row.");
            }
            $features = $this->object($row->features, 'features');
            $evidence = $this->object($row->evidence, 'evidence');
            $qualityReasons = $evidence['quality_reasons'] ?? [];
            if (! is_array($qualityReasons) || array_filter($qualityReasons, 'is_string') !== $qualityReasons) {
                throw new RuntimeException("{$statCode} quality reasons were invalid.");
            }

            $grouped[(int) $row->race_id][] = new Bt02SignalFeatureDto(
                raceId: (int) $row->race_id,
                raceEntryId: (int) $row->race_entry_id,
                status: (string) $row->status,
                qualityStatus: (string) $row->quality_status,
                qualityReasons: array_values($qualityReasons),
                primaryValue: $this->registry->primary($statCode, $features),
            );
        }

        return $grouped;
    }

    /** @return array<string, mixed> */
    private function object(mixed $value, string $name): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value)) {
            throw new RuntimeException("BT-02 {$name} was not a JSON object.");
        }
        $decoded = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new RuntimeException("BT-02 {$name} was not a JSON object.");
        }

        return $decoded;
    }
}
