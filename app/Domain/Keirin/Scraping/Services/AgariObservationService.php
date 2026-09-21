<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Services;

use App\Domain\Keirin\Scraping\DTO\RaceResultDto;
use App\Domain\Keirin\Scraping\Support\AgariTimeNormalizer;
use App\Models\RaceEntry;
use App\Models\RaceResultAgariObservation;
use App\Models\RaceResultImport;
use App\Models\ScrapingFetchLog;
use Carbon\CarbonImmutable;
use RuntimeException;

class AgariObservationService
{
    public const VERSION = 'AGARI-STORAGE-v1';

    public function __construct(private readonly AgariTimeNormalizer $normalizer) {}

    /** Call only inside the transaction holding the parent race lock. */
    public function record(RaceResultImport $import, RaceResultDto $result, ?RaceEntry $entry, bool $backfilled = false, bool $dryRun = false): array
    {
        if ($result->bikeNumber === null || $result->bikeNumber < 1 || $result->bikeNumber > 9 || $import->race_id === null) {
            throw new RuntimeException('Invalid agari observation identity.');
        }
        if ($entry !== null && ((int) $entry->race_id !== (int) $import->race_id || $entry->bike_number !== $result->bikeNumber
            || ($result->externalPlayerId !== null && $result->externalPlayerId !== $entry->external_player_id))) {
            throw new RuntimeException('Agari observation entry identity mismatch.');
        }
        if ($result->externalPlayerId === null) {
            $entry = null;
        }
        $log = $import->scraping_fetch_log_id === null ? null : ScrapingFetchLog::query()->findOrFail($import->scraping_fetch_log_id);
        if ($log !== null && ($log->sha256 !== $import->source_hash || $log->raw_file_path !== $import->raw_file_path
            || (int) $log->response_size !== (int) $import->raw_response_size)) {
            throw new RuntimeException('Agari import/fetch provenance conflict.');
        }
        $values = $this->normalizer->result($result);
        $attributes = [
            'race_id' => (int) $import->race_id, 'race_result_import_id' => (int) $import->id,
            'race_entry_id' => $entry?->id, 'player_id' => $entry?->player_id,
            'external_player_id' => $result->externalPlayerId,
            'bike_number' => $result->bikeNumber, 'result_status' => $result->status->value,
            ...$values, 'source_url' => $import->source_url, 'fetched_at' => $log?->fetched_at,
            'parser_version' => self::VERSION,
        ];
        $existing = RaceResultAgariObservation::query()->where('race_result_import_id', $import->id)
            ->where('bike_number', $result->bikeNumber)->first();
        if ($existing !== null) {
            foreach ($attributes as $key => $value) {
                // Live entry/player linkage may change; the first observation keeps its historical IDs.
                if (in_array($key, ['race_entry_id', 'player_id'], true)) {
                    continue;
                }
                $actual = $existing->getAttribute($key);
                if ($key === 'fetched_at') {
                    $actual = $actual?->utc()->format('Y-m-d\TH:i:s.uP');
                    $value = $value?->utc()->format('Y-m-d\TH:i:s.uP');
                }
                if ($actual !== $value) {
                    throw new RuntimeException("Immutable agari observation conflict: import={$import->id}, bike={$result->bikeNumber}, field={$key}");
                }
            }
            if (($existing->metadata['source_hash'] ?? null) !== $import->source_hash
                || ($existing->metadata['converted_hash'] ?? null) !== $import->converted_hash) {
                throw new RuntimeException('Immutable agari source hash conflict.');
            }
        } elseif (! $dryRun) {
            RaceResultAgariObservation::query()->create([...$attributes,
                'created_at' => CarbonImmutable::now(),
                'metadata' => ['origin' => $backfilled ? 'BACKFILLED_FINAL_RESULT' : 'RESULT_IMPORT',
                    'semantic' => 'AGARI_TIME', 'publication_timestamp' => 'UNKNOWN',
                    'source_hash' => $import->source_hash, 'converted_hash' => $import->converted_hash,
                    'raw_file_path' => $import->raw_file_path, 'source_parser_version' => $import->parser_version,
                    'normalizer_version' => AgariTimeNormalizer::VERSION,
                    'identity_link_timing' => 'OBSERVATION_PERSISTENCE',
                    'acquisition_timing' => $log === null ? 'UNKNOWN' : 'FETCH_LOG',
                ],
            ]);
        }

        return ['values' => $values, 'created' => $existing === null];
    }
}
