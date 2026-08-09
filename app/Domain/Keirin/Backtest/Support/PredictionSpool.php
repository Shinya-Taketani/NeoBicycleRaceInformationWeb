<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Support;

use App\Domain\Keirin\Backtest\DTO\PredictionDto;
use App\Domain\Keirin\Backtest\DTO\RaceContextDto;
use DateTimeImmutable;
use RuntimeException;

class PredictionSpool
{
    /** @var resource|null */
    private $handle;

    private readonly string $path;

    public function __construct()
    {
        $handle = tmpfile();
        if ($handle === false) {
            throw new RuntimeException('Could not create the BT-01 prediction spool.');
        }
        $metadata = stream_get_meta_data($handle);
        $this->handle = $handle;
        $this->path = (string) ($metadata['uri'] ?? '');
    }

    /** @param list<PredictionDto> $predictions */
    public function append(RaceContextDto $race, array $predictions): void
    {
        $line = json_encode([
            'race' => [
                'race_id' => $race->raceId,
                'race_date' => $race->raceDate->format('Y-m-d'),
                'scheduled_start_at' => $race->scheduledStartAt?->format(DATE_ATOM),
                'sales_close_at' => $race->salesCloseAt?->format(DATE_ATOM),
                'entrant_count' => $race->entrantCount,
                'result_status' => $race->resultStatus,
            ],
            'predictions' => array_map(fn (PredictionDto $prediction): array => [
                'race_id' => $prediction->raceId,
                'race_entry_id' => $prediction->raceEntryId,
                'player_id' => $prediction->playerId,
                'bike_number' => $prediction->bikeNumber,
                'feature_run_id' => $prediction->featureRunId,
                'feature_result_id' => $prediction->featureResultId,
                'source_input_hash' => $prediction->sourceInputHash,
                'prediction_score' => $prediction->predictionScore,
                'predicted_rank' => $prediction->predictedRank,
                'is_rank1_set' => $prediction->isRank1Set,
                'is_top3_set' => $prediction->isTop3Set,
                'prediction_hash' => $prediction->predictionHash,
            ], $predictions),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
        if ($this->handle === null || fwrite($this->handle, $line) !== strlen($line)) {
            throw new RuntimeException('Could not write the BT-01 prediction spool.');
        }
    }

    /** @return \Generator<int, list<array{race: RaceContextDto, predictions: list<PredictionDto>}>> */
    public function chunks(int $chunkSize): \Generator
    {
        if ($this->handle === null || rewind($this->handle) === false) {
            throw new RuntimeException('Could not rewind the BT-01 prediction spool.');
        }
        $chunk = [];
        while (($line = fgets($this->handle)) !== false) {
            $chunk[] = $this->decode($line);
            if (count($chunk) === $chunkSize) {
                yield $chunk;
                $chunk = [];
            }
        }
        if ($chunk !== []) {
            yield $chunk;
        }
    }

    public function close(): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }
    }

    public function isClosed(): bool
    {
        return $this->handle === null;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function __destruct()
    {
        $this->close();
    }

    /** @return array{race: RaceContextDto, predictions: list<PredictionDto>} */
    private function decode(string $line): array
    {
        $data = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($data) || ! is_array($data['race'] ?? null) || ! is_array($data['predictions'] ?? null)) {
            throw new RuntimeException('BT-01 prediction spool data was invalid.');
        }
        $race = $data['race'];
        $predictions = array_map(fn (array $prediction): PredictionDto => new PredictionDto(
            raceId: (int) $prediction['race_id'],
            raceEntryId: (int) $prediction['race_entry_id'],
            playerId: $prediction['player_id'] !== null ? (int) $prediction['player_id'] : null,
            bikeNumber: (int) $prediction['bike_number'],
            featureRunId: (int) $prediction['feature_run_id'],
            featureResultId: (int) $prediction['feature_result_id'],
            sourceInputHash: (string) $prediction['source_input_hash'],
            predictionScore: (string) $prediction['prediction_score'],
            predictedRank: (int) $prediction['predicted_rank'],
            isRank1Set: (bool) $prediction['is_rank1_set'],
            isTop3Set: (bool) $prediction['is_top3_set'],
            predictionHash: (string) $prediction['prediction_hash'],
        ), $data['predictions']);

        return [
            'race' => new RaceContextDto(
                raceId: (int) $race['race_id'],
                raceDate: new DateTimeImmutable((string) $race['race_date']),
                scheduledStartAt: $race['scheduled_start_at'] !== null ? new DateTimeImmutable((string) $race['scheduled_start_at']) : null,
                salesCloseAt: $race['sales_close_at'] !== null ? new DateTimeImmutable((string) $race['sales_close_at']) : null,
                entrantCount: (int) $race['entrant_count'],
                resultStatus: (string) $race['result_status'],
            ),
            'predictions' => $predictions,
        ];
    }
}
