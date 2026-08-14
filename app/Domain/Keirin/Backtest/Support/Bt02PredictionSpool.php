<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Support;

use App\Domain\Keirin\Backtest\DTO\Bt02PredictionSpoolMetadataDto;
use JsonException;
use RuntimeException;

class Bt02PredictionSpool
{
    public const FORMAT_VERSION = 'BT02-PAIRED-PREDICTION-JSONL-v2';

    /** @var resource|null */
    private $handle;

    private string $path;

    private ?\HashContext $fileHash;

    private ?\HashContext $baselineHash;

    private ?\HashContext $incrementalHash;

    private ?\HashContext $outcomeHash;

    private ?Bt02PredictionSpoolMetadataDto $metadata = null;

    private int $rowCount = 0;

    private int $raceCount = 0;

    private int $byteCount = 0;

    private ?int $lastRaceId = null;

    private ?int $lastRaceEntryId = null;

    /** @var array<int, true> */
    private array $seenRaceIds = [];

    /** @param array<string, int|string> $identity */
    public function __construct(array $identity, ?string $directory = null)
    {
        $path = tempnam($directory ?? sys_get_temp_dir(), 'bt02-prediction-');
        if ($path === false || ($handle = fopen($path, 'wb')) === false) {
            throw new RuntimeException('Could not create the BT-02 prediction spool.');
        }
        $this->path = $path;
        $this->handle = $handle;
        $prefix = json_encode([
            'format_version' => self::FORMAT_VERSION,
            ...$identity,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
        $this->fileHash = hash_init('sha256');
        $this->baselineHash = hash_init('sha256');
        $this->incrementalHash = hash_init('sha256');
        $this->outcomeHash = hash_init('sha256');
        hash_update($this->baselineHash, "BASELINE_MATCHED\n{$prefix}");
        hash_update($this->incrementalHash, "INCREMENTAL\n{$prefix}");
        hash_update($this->outcomeHash, "EVALUATION_OUTCOME\n{$prefix}");
    }

    public function append(int $raceId, int $raceEntryId, int $label, float $baseline, float $incremental): void
    {
        $this->assertWritable();
        if ($raceId < 1 || $raceEntryId < 1 || ! in_array($label, [0, 1], true)
            || ! is_finite($baseline) || ! is_finite($incremental)) {
            throw new RuntimeException('BT-02 prediction spool row was invalid.');
        }
        $this->assertOrder($raceId, $raceEntryId);
        $baselineText = sprintf('%.17g', $baseline);
        $incrementalText = sprintf('%.17g', $incremental);
        hash_update($this->baselineHash, "{$raceId},{$raceEntryId},{$baselineText}\n");
        hash_update($this->incrementalHash, "{$raceId},{$raceEntryId},{$incrementalText}\n");
        hash_update($this->outcomeHash, "{$raceId},{$raceEntryId},{$label}\n");
        $line = json_encode([
            'format_version' => self::FORMAT_VERSION,
            'race_id' => $raceId,
            'race_entry_id' => $raceEntryId,
            'label' => $label,
            'baseline' => $baselineText,
            'incremental' => $incrementalText,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
        try {
            $this->write($line);
            hash_update($this->fileHash, $line);
            $this->rowCount++;
            $this->byteCount += strlen($line);
        } catch (\Throwable $throwable) {
            $this->cleanup();
            throw $throwable;
        }
    }

    public function seal(): Bt02PredictionSpoolMetadataDto
    {
        $this->assertWritable();
        if ($this->rowCount === 0 || $this->raceCount === 0) {
            throw new RuntimeException('BT-02 prediction spool could not be sealed without rows.');
        }
        try {
            if (! fflush($this->handle)) {
                throw new RuntimeException('Could not flush the BT-02 prediction spool.');
            }
            if (function_exists('fsync') && ! fsync($this->handle)) {
                throw new RuntimeException('Could not fsync the BT-02 prediction spool.');
            }
            fclose($this->handle);
            $this->handle = null;
            $this->metadata = new Bt02PredictionSpoolMetadataDto(
                self::FORMAT_VERSION,
                $this->rowCount,
                $this->raceCount,
                $this->byteCount,
                hash_final($this->fileHash),
                hash_final($this->baselineHash),
                hash_final($this->incrementalHash),
                hash_final($this->outcomeHash),
            );
            $this->fileHash = $this->baselineHash = $this->incrementalHash = $this->outcomeHash = null;

            return $this->metadata;
        } catch (\Throwable $throwable) {
            $this->cleanup();
            throw $throwable;
        }
    }

    public function metadata(): Bt02PredictionSpoolMetadataDto
    {
        return $this->metadata ?? throw new RuntimeException('BT-02 prediction spool has not been sealed.');
    }

    /** @return \Generator<int, array{race_id: int, race_entry_id: int, label: int, baseline: float, incremental: float}> */
    public function rows(): \Generator
    {
        $metadata = $this->metadata();
        $handle = fopen($this->path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Could not open the BT-02 prediction spool.');
        }
        $hash = hash_init('sha256');
        $count = $bytes = $raceCount = 0;
        $lastRaceId = $lastRaceEntryId = null;
        $seenRaceIds = [];
        try {
            while (($line = fgets($handle)) !== false) {
                if (! str_ends_with($line, "\n")) {
                    throw new RuntimeException('BT-02 prediction spool row did not end with LF.');
                }
                hash_update($hash, $line);
                $bytes += strlen($line);
                $count++;
                $row = $this->decode($line);
                $this->assertReplayOrder(
                    $row['race_id'],
                    $row['race_entry_id'],
                    $lastRaceId,
                    $lastRaceEntryId,
                    $seenRaceIds,
                );
                if ($lastRaceId !== $row['race_id']) {
                    $raceCount++;
                }
                $lastRaceId = $row['race_id'];
                $lastRaceEntryId = $row['race_entry_id'];
                yield $row;
            }
            if (! feof($handle)) {
                throw new RuntimeException('Could not fully read the BT-02 prediction spool.');
            }
            $actualHash = hash_final($hash);
            if ($count !== $metadata->rowCount || $raceCount !== $metadata->raceCount
                || $bytes !== $metadata->byteCount || ! hash_equals($metadata->fileSha256, $actualHash)) {
                throw new RuntimeException('BT-02 prediction spool replay identity did not match its seal metadata.');
            }
        } finally {
            fclose($handle);
        }
    }

    /** @return list<array{race_id: int, labels: list<int>, baseline: list<float>, incremental: list<float>}> */
    public function racePayloads(): array
    {
        $payloads = [];
        $current = null;
        foreach ($this->rows() as $row) {
            if ($current === null || $current['race_id'] !== $row['race_id']) {
                if ($current !== null) {
                    $payloads[] = $current;
                }
                $current = ['race_id' => $row['race_id'], 'labels' => [], 'baseline' => [], 'incremental' => []];
            }
            $current['labels'][] = $row['label'];
            $current['baseline'][] = $row['baseline'];
            $current['incremental'][] = $row['incremental'];
        }
        if ($current !== null) {
            $payloads[] = $current;
        }

        return $payloads;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function cleanup(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
        $this->handle = null;
        $this->fileHash = $this->baselineHash = $this->incrementalHash = $this->outcomeHash = null;
        $this->seenRaceIds = [];
        if (isset($this->path) && is_file($this->path)) {
            @unlink($this->path);
        }
    }

    public function __destruct()
    {
        $this->cleanup();
    }

    /** @return array{race_id: int, race_entry_id: int, label: int, baseline: float, incremental: float} */
    private function decode(string $line): array
    {
        try {
            $row = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('BT-02 prediction spool JSON was invalid.', previous: $exception);
        }
        if (! is_array($row)
            || array_keys($row) !== ['format_version', 'race_id', 'race_entry_id', 'label', 'baseline', 'incremental']
            || $row['format_version'] !== self::FORMAT_VERSION
            || ! is_int($row['race_id']) || $row['race_id'] < 1
            || ! is_int($row['race_entry_id']) || $row['race_entry_id'] < 1
            || ! in_array($row['label'], [0, 1], true)
            || ! is_string($row['baseline']) || ! is_numeric($row['baseline'])
            || ! is_string($row['incremental']) || ! is_numeric($row['incremental'])) {
            throw new RuntimeException('BT-02 prediction spool data was invalid.');
        }
        $baseline = (float) $row['baseline'];
        $incremental = (float) $row['incremental'];
        if (! is_finite($baseline) || ! is_finite($incremental)) {
            throw new RuntimeException('BT-02 prediction spool probability was not finite.');
        }

        return [
            'race_id' => $row['race_id'],
            'race_entry_id' => $row['race_entry_id'],
            'label' => $row['label'],
            'baseline' => $baseline,
            'incremental' => $incremental,
        ];
    }

    private function assertWritable(): void
    {
        if ($this->metadata !== null || ! is_resource($this->handle)
            || $this->fileHash === null || $this->baselineHash === null
            || $this->incrementalHash === null || $this->outcomeHash === null) {
            throw new RuntimeException('BT-02 prediction spool is not writable.');
        }
    }

    private function assertOrder(int $raceId, int $raceEntryId): void
    {
        if (($raceId === $this->lastRaceId && $raceEntryId <= $this->lastRaceEntryId)
            || ($raceId !== $this->lastRaceId && isset($this->seenRaceIds[$raceId]))) {
            throw new RuntimeException('BT-02 prediction spool order or identity was invalid.');
        }
        if ($this->lastRaceId !== $raceId) {
            $this->seenRaceIds[$raceId] = true;
            $this->raceCount++;
        }
        $this->lastRaceId = $raceId;
        $this->lastRaceEntryId = $raceEntryId;
    }

    /** @param array<int, true> $seenRaceIds */
    private function assertReplayOrder(
        int $raceId,
        int $raceEntryId,
        ?int $lastRaceId,
        ?int $lastRaceEntryId,
        array &$seenRaceIds,
    ): void {
        if (($raceId === $lastRaceId && $raceEntryId <= $lastRaceEntryId)
            || ($raceId !== $lastRaceId && isset($seenRaceIds[$raceId]))) {
            throw new RuntimeException('BT-02 prediction spool replay order or identity was invalid.');
        }
        $seenRaceIds[$raceId] = true;
    }

    private function write(string $bytes): void
    {
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $written = fwrite($this->handle, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Could not write the BT-02 prediction spool.');
            }
            $offset += $written;
        }
    }
}
