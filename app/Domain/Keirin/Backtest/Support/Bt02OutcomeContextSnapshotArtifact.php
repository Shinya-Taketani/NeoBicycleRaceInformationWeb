<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Support;

use App\Domain\Keirin\Backtest\Contracts\Bt02OutcomeContextSnapshot;
use App\Domain\Keirin\Backtest\DTO\Bt02OutcomeContextRaceDto;
use App\Domain\Keirin\Backtest\DTO\Bt02OutcomeSnapshotPartitionDto;
use App\Domain\Keirin\Backtest\DTO\FoldDefinitionDto;
use App\Domain\Keirin\Backtest\DTO\LabelResultDto;
use App\Domain\Keirin\Backtest\DTO\RaceContextDto;
use DateTimeImmutable;
use JsonException;
use RuntimeException;

class Bt02OutcomeContextSnapshotArtifact implements Bt02OutcomeContextSnapshot
{
    public const FORMAT_VERSION = 'BT02-OUTCOME-CONTEXT-SNAPSHOT-JSONL-v1';

    public const TARGET_FROM = '2022-01-01';

    public const TARGET_TO = '2025-12-31';

    /** @param list<Bt02OutcomeSnapshotPartitionDto> $partitions */
    private function __construct(
        private readonly string $rootPath,
        private readonly string $auditPath,
        private readonly array $partitions,
        private readonly string $hash,
    ) {}

    public static function open(string $rootPath, string $auditPath): self
    {
        $manifestPath = $rootPath.'/manifest.json';
        $contents = is_file($manifestPath) ? file_get_contents($manifestPath) : false;
        if (! is_string($contents) || ! str_ends_with($contents, "\n")) {
            throw new RuntimeException('BT-02 outcome snapshot manifest was unavailable or incomplete.');
        }
        try {
            $manifest = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('BT-02 outcome snapshot manifest JSON was invalid.', previous: $exception);
        }
        if (! is_array($manifest)
            || array_keys($manifest) !== ['format_version', 'target_from', 'target_to', 'partitions', 'manifest_sha256']
            || $manifest['format_version'] !== self::FORMAT_VERSION
            || $manifest['target_from'] !== self::TARGET_FROM
            || $manifest['target_to'] !== self::TARGET_TO
            || ! is_array($manifest['partitions'])
            || ! is_string($manifest['manifest_sha256'])
            || preg_match('/\A[0-9a-f]{64}\z/', $manifest['manifest_sha256']) !== 1) {
            throw new RuntimeException('BT-02 outcome snapshot manifest contract was invalid.');
        }

        $partitions = [];
        foreach ($manifest['partitions'] as $partition) {
            if (! is_array($partition)
                || array_keys($partition) !== ['year', 'file', 'race_count', 'result_row_count', 'byte_count', 'sha256']
                || ! is_int($partition['year']) || ! is_string($partition['file'])
                || ! is_int($partition['race_count']) || ! is_int($partition['result_row_count'])
                || ! is_int($partition['byte_count']) || ! is_string($partition['sha256'])) {
                throw new RuntimeException('BT-02 outcome snapshot partition manifest was invalid.');
            }
            $partitions[] = new Bt02OutcomeSnapshotPartitionDto(
                $partition['year'],
                $partition['file'],
                $partition['race_count'],
                $partition['result_row_count'],
                $partition['byte_count'],
                $partition['sha256'],
            );
        }
        if (array_map(fn (Bt02OutcomeSnapshotPartitionDto $partition): int => $partition->year, $partitions) !== [2022, 2023, 2024, 2025]) {
            throw new RuntimeException('BT-02 outcome snapshot required exactly the fixed four year partitions.');
        }
        $payload = self::manifestPayload($partitions);
        $actualHash = hash('sha256', self::encode($payload));
        if (! hash_equals($manifest['manifest_sha256'], $actualHash)
            || $contents !== self::encode([...$payload, 'manifest_sha256' => $actualHash])) {
            throw new RuntimeException('BT-02 outcome snapshot manifest identity was invalid.');
        }

        return new self($rootPath, $auditPath, $partitions, $actualHash);
    }

    /** @return \Generator<int, list<Bt02OutcomeContextRaceDto>> */
    public function chunks(FoldDefinitionDto $fold, int $chunkSize): \Generator
    {
        if ($chunkSize < 1 || $fold->evaluationFrom > $fold->evaluationTo
            || $fold->evaluationFrom < new DateTimeImmutable(self::TARGET_FROM)
            || $fold->evaluationTo > new DateTimeImmutable(self::TARGET_TO)) {
            throw new RuntimeException('BT-02 outcome snapshot replay range was invalid.');
        }

        $chunk = [];
        foreach ($this->partitions as $partition) {
            $fromYear = (int) $fold->evaluationFrom->format('Y');
            $toYear = (int) $fold->evaluationTo->format('Y');
            if ($partition->year < $fromYear || $partition->year > $toYear) {
                continue;
            }
            foreach ($this->partitionRows($partition) as $race) {
                if ($race->context->raceDate < $fold->evaluationFrom || $race->context->raceDate > $fold->evaluationTo) {
                    continue;
                }
                $chunk[] = $race;
                if (count($chunk) === $chunkSize) {
                    yield $chunk;
                    $chunk = [];
                }
            }
        }
        if ($chunk !== []) {
            yield $chunk;
        }
    }

    /** @return array<string, mixed> */
    public function auditParameters(): array
    {
        return [
            'outcome_snapshot_format_version' => self::FORMAT_VERSION,
            'outcome_snapshot_manifest_hash' => $this->hash,
            'outcome_snapshot_path' => $this->auditPath,
            'outcome_snapshot_target_from' => self::TARGET_FROM,
            'outcome_snapshot_target_to' => self::TARGET_TO,
            'outcome_snapshot_partitions' => array_map(
                fn (Bt02OutcomeSnapshotPartitionDto $partition): array => $partition->canonical(),
                $this->partitions,
            ),
        ];
    }

    public function manifestHash(): string
    {
        return $this->hash;
    }

    public function verify(): void
    {
        foreach ($this->partitions as $partition) {
            foreach ($this->partitionRows($partition) as $_) {
                // Exhausting the partition performs all replay integrity checks.
            }
        }
    }

    public function partitionPath(int $year): string
    {
        $partition = $this->partition($year);

        return $this->rootPath.'/'.$partition->file;
    }

    /** @param list<Bt02OutcomeSnapshotPartitionDto> $partitions @return array{format_version: string, target_from: string, target_to: string, partitions: list<array<string, int|string>>} */
    public static function manifestPayload(array $partitions): array
    {
        return [
            'format_version' => self::FORMAT_VERSION,
            'target_from' => self::TARGET_FROM,
            'target_to' => self::TARGET_TO,
            'partitions' => array_map(
                fn (Bt02OutcomeSnapshotPartitionDto $partition): array => $partition->canonical(),
                $partitions,
            ),
        ];
    }

    /** @param array<string, mixed> $value */
    public static function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    }

    /** @return \Generator<int, Bt02OutcomeContextRaceDto> */
    private function partitionRows(Bt02OutcomeSnapshotPartitionDto $partition): \Generator
    {
        $path = $this->rootPath.'/'.$partition->file;
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Could not open a BT-02 outcome snapshot partition.');
        }
        $hash = hash_init('sha256');
        $raceCount = $resultCount = $byteCount = 0;
        $lastDate = null;
        $lastRaceId = null;
        try {
            while (($line = fgets($handle)) !== false) {
                if (! str_ends_with($line, "\n")) {
                    throw new RuntimeException('BT-02 outcome snapshot row did not end with LF.');
                }
                hash_update($hash, $line);
                $byteCount += strlen($line);
                $race = $this->decodeRace($line, $partition->year);
                $date = $race->context->raceDate->format('Y-m-d');
                if ($lastDate !== null && ($date < $lastDate || ($date === $lastDate && $race->context->raceId <= $lastRaceId))) {
                    throw new RuntimeException('BT-02 outcome snapshot race order or identity was invalid.');
                }
                $lastDate = $date;
                $lastRaceId = $race->context->raceId;
                $raceCount++;
                $resultCount += count($race->results);
                yield $race;
            }
            if (! feof($handle)) {
                throw new RuntimeException('Could not fully read a BT-02 outcome snapshot partition.');
            }
            $actualHash = hash_final($hash);
            if ($raceCount !== $partition->raceCount || $resultCount !== $partition->resultRowCount
                || $byteCount !== $partition->byteCount || ! hash_equals($partition->sha256, $actualHash)) {
                throw new RuntimeException('BT-02 outcome snapshot partition identity did not match its seal metadata.');
            }
        } finally {
            fclose($handle);
        }
    }

    private function decodeRace(string $line, int $year): Bt02OutcomeContextRaceDto
    {
        try {
            $row = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('BT-02 outcome snapshot row JSON was invalid.', previous: $exception);
        }
        if (! is_array($row)
            || array_keys($row) !== ['format_version', 'race_id', 'race_date', 'scheduled_start_at', 'sales_close_at', 'entrant_count', 'race_status', 'race_type', 'results']
            || $row['format_version'] !== self::FORMAT_VERSION
            || ! is_int($row['race_id']) || $row['race_id'] < 1
            || ! is_string($row['race_date']) || preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $row['race_date']) !== 1
            || (int) substr($row['race_date'], 0, 4) !== $year
            || ($row['scheduled_start_at'] !== null && ! is_string($row['scheduled_start_at']))
            || ($row['sales_close_at'] !== null && ! is_string($row['sales_close_at']))
            || ! is_int($row['entrant_count']) || $row['entrant_count'] < 5 || $row['entrant_count'] > 9
            || ! is_string($row['race_status']) || $row['race_status'] === ''
            || ! is_string($row['race_type']) || ! preg_match('/\A[ＡＳ]級/u', $row['race_type'])
            || ! is_array($row['results'])) {
            throw new RuntimeException('BT-02 outcome snapshot race data was invalid.');
        }
        $results = [];
        $lastBike = 0;
        foreach ($row['results'] as $result) {
            if (! is_array($result)
                || array_keys($result) !== ['bike_number', 'rank', 'result_status']
                || ! is_int($result['bike_number']) || $result['bike_number'] < 1 || $result['bike_number'] > 9
                || $result['bike_number'] <= $lastBike
                || ($result['rank'] !== null && (! is_int($result['rank']) || $result['rank'] < 1))
                || ! is_string($result['result_status']) || $result['result_status'] === '') {
                throw new RuntimeException('BT-02 outcome snapshot result data was invalid.');
            }
            $lastBike = $result['bike_number'];
            $results[] = new LabelResultDto($row['race_id'], $result['bike_number'], $result['rank'], $result['result_status']);
        }

        return new Bt02OutcomeContextRaceDto(
            new RaceContextDto(
                $row['race_id'],
                new DateTimeImmutable($row['race_date']),
                $row['scheduled_start_at'] !== null ? new DateTimeImmutable($row['scheduled_start_at']) : null,
                $row['sales_close_at'] !== null ? new DateTimeImmutable($row['sales_close_at']) : null,
                $row['entrant_count'],
                $row['race_status'],
            ),
            $row['race_type'],
            $results,
        );
    }

    private function partition(int $year): Bt02OutcomeSnapshotPartitionDto
    {
        foreach ($this->partitions as $partition) {
            if ($partition->year === $year) {
                return $partition;
            }
        }

        throw new RuntimeException("BT-02 outcome snapshot partition {$year} was unavailable.");
    }
}
