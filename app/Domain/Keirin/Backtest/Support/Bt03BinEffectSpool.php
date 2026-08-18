<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Support;

use App\Domain\Keirin\Backtest\DTO\Bt03BinEffectEntryDto;
use App\Domain\Keirin\Backtest\DTO\Bt03BinSpoolIdentityDto;
use App\Domain\Keirin\Backtest\DTO\Bt03BinSpoolMetadataDto;
use App\Domain\Keirin\Backtest\DTO\Bt03RaceBinPayloadDto;
use JsonException;
use RuntimeException;

class Bt03BinEffectSpool
{
    public const FORMAT_VERSION = 'BT03-BIN-EFFECT-SPOOL-JSONL-v1';

    /** @var resource|null */
    private $handle;

    private string $path;

    private ?\HashContext $hash;

    private int $rowCount = 0;

    private int $raceCount = 0;

    private int $byteCount = 0;

    private ?int $lastRaceId = null;

    private ?int $lastRaceEntryId = null;

    /** @var array<int, true> */
    private array $seenRaceIds = [];

    private ?Bt03BinSpoolMetadataDto $metadata = null;

    public function __construct(
        private readonly Bt03BinSpoolIdentityDto $identity,
        ?string $directory = null,
    ) {
        $path = tempnam($directory ?? sys_get_temp_dir(), 'bt03-bin-effect-');
        if ($path === false || ($handle = fopen($path, 'wb')) === false) {
            throw new RuntimeException('Could not create the BT-03 bin effect spool.');
        }
        $this->path = $path;
        $this->handle = $handle;
        $this->hash = hash_init('sha256');
        try {
            $this->writeAndHash($this->header());
        } catch (\Throwable $throwable) {
            $this->cleanup();
            throw $throwable;
        }
    }

    public function append(int $raceId, Bt03BinEffectEntryDto $entry): void
    {
        if (! is_resource($this->handle) || $this->hash === null || $this->metadata !== null
            || $raceId < 1 || $entry->raceEntryId < 1 || ! in_array($entry->label, [0, 1], true)
            || ! is_finite($entry->baselineProbability) || ! is_finite($entry->incrementalProbability)
            || $entry->baselineProbability < 0.0 || $entry->baselineProbability > 1.0
            || $entry->incrementalProbability < 0.0 || $entry->incrementalProbability > 1.0) {
            throw new RuntimeException('BT-03 bin effect spool row was invalid.');
        }
        $this->assertOrdered($raceId, $entry->raceEntryId, $this->lastRaceId, $this->lastRaceEntryId, $this->seenRaceIds);
        $line = json_encode([
            'race_id' => $raceId,
            'race_entry_id' => $entry->raceEntryId,
            'label' => $entry->label,
            'baseline_probability' => sprintf('%.17g', $entry->baselineProbability),
            'incremental_probability' => sprintf('%.17g', $entry->incrementalProbability),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
        $this->writeAndHash($line);
        $this->rowCount++;
        if ($raceId !== $this->lastRaceId) {
            $this->raceCount++;
        }
        $this->lastRaceId = $raceId;
        $this->lastRaceEntryId = $entry->raceEntryId;
    }

    public function seal(): Bt03BinSpoolMetadataDto
    {
        if (! is_resource($this->handle) || $this->hash === null || $this->metadata !== null) {
            throw new RuntimeException('BT-03 bin effect spool was not sealable.');
        }
        try {
            if (! fflush($this->handle) || (function_exists('fsync') && ! fsync($this->handle))) {
                throw new RuntimeException('Could not flush the BT-03 bin effect spool.');
            }
            fclose($this->handle);
            $this->handle = null;
            $this->metadata = new Bt03BinSpoolMetadataDto(
                $this->identity,
                $this->rowCount,
                $this->raceCount,
                $this->byteCount,
                hash_final($this->hash),
                self::FORMAT_VERSION,
            );
            $this->hash = null;

            return $this->metadata;
        } catch (\Throwable $throwable) {
            $this->cleanup();
            throw $throwable;
        }
    }

    public function metadata(): Bt03BinSpoolMetadataDto
    {
        return $this->metadata ?? throw new RuntimeException('BT-03 bin effect spool was not sealed.');
    }

    /** @return \Generator<int, Bt03RaceBinPayloadDto> */
    public function payloads(): \Generator
    {
        $this->verify();
        $handle = fopen($this->path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Could not replay the BT-03 bin effect spool.');
        }
        try {
            $header = fgets($handle);
            if ($header !== $this->header()) {
                throw new RuntimeException('BT-03 bin effect spool header changed after verification.');
            }
            $raceId = null;
            $entries = [];
            while (($line = fgets($handle)) !== false) {
                [$nextRaceId, $entry] = $this->decode($line);
                if ($raceId !== null && $nextRaceId !== $raceId) {
                    yield new Bt03RaceBinPayloadDto($raceId, $entries);
                    $entries = [];
                }
                $raceId = $nextRaceId;
                $entries[] = $entry;
            }
            if (! feof($handle)) {
                throw new RuntimeException('Could not fully replay the BT-03 bin effect spool.');
            }
            if ($raceId !== null) {
                yield new Bt03RaceBinPayloadDto($raceId, $entries);
            }
        } finally {
            fclose($handle);
        }
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
        $this->hash = null;
        $this->seenRaceIds = [];
        if (isset($this->path) && is_file($this->path)) {
            @unlink($this->path);
        }
    }

    public function __destruct()
    {
        $this->cleanup();
    }

    private function verify(): void
    {
        $metadata = $this->metadata();
        $handle = fopen($this->path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Could not verify the BT-03 bin effect spool.');
        }
        $hash = hash_init('sha256');
        $rows = $races = $bytes = 0;
        $lastRaceId = $lastRaceEntryId = null;
        $seenRaceIds = [];
        try {
            $header = fgets($handle);
            if ($header !== $this->header()) {
                throw new RuntimeException('BT-03 bin effect spool identity header was invalid.');
            }
            hash_update($hash, $header);
            $bytes += strlen($header);
            while (($line = fgets($handle)) !== false) {
                if (! str_ends_with($line, "\n")) {
                    throw new RuntimeException('BT-03 bin effect spool row did not end with LF.');
                }
                [$raceId, $entry] = $this->decode($line);
                $this->assertOrdered($raceId, $entry->raceEntryId, $lastRaceId, $lastRaceEntryId, $seenRaceIds);
                if ($raceId !== $lastRaceId) {
                    $races++;
                }
                $lastRaceId = $raceId;
                $lastRaceEntryId = $entry->raceEntryId;
                $rows++;
                $bytes += strlen($line);
                hash_update($hash, $line);
            }
            if (! feof($handle)) {
                throw new RuntimeException('Could not fully verify the BT-03 bin effect spool.');
            }
            if ($rows !== $metadata->rowCount || $races !== $metadata->raceCount || $bytes !== $metadata->byteCount
                || ! hash_equals($metadata->sha256, hash_final($hash))) {
                throw new RuntimeException('BT-03 bin effect spool did not match its seal metadata.');
            }
        } finally {
            fclose($handle);
        }
    }

    /** @return array{int, Bt03BinEffectEntryDto} */
    private function decode(string $line): array
    {
        try {
            $row = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('BT-03 bin effect spool JSON was invalid.', previous: $exception);
        }
        if (! is_array($row)
            || array_keys($row) !== ['race_id', 'race_entry_id', 'label', 'baseline_probability', 'incremental_probability']
            || ! is_int($row['race_id']) || $row['race_id'] < 1
            || ! is_int($row['race_entry_id']) || $row['race_entry_id'] < 1
            || ! in_array($row['label'], [0, 1], true)
            || ! is_string($row['baseline_probability']) || ! is_numeric($row['baseline_probability'])
            || ! is_string($row['incremental_probability']) || ! is_numeric($row['incremental_probability'])) {
            throw new RuntimeException('BT-03 bin effect spool data was invalid.');
        }
        $baseline = (float) $row['baseline_probability'];
        $incremental = (float) $row['incremental_probability'];
        if (! is_finite($baseline) || ! is_finite($incremental)) {
            throw new RuntimeException('BT-03 bin effect spool probability was not finite.');
        }

        return [
            $row['race_id'],
            new Bt03BinEffectEntryDto($row['race_entry_id'], $row['label'], $baseline, $incremental),
        ];
    }

    /** @param array<int, true> $seenRaceIds */
    private function assertOrdered(int $raceId, int $raceEntryId, ?int $lastRaceId, ?int $lastRaceEntryId, array &$seenRaceIds): void
    {
        if (($raceId === $lastRaceId && $raceEntryId <= $lastRaceEntryId)
            || ($raceId !== $lastRaceId && isset($seenRaceIds[$raceId]))) {
            throw new RuntimeException('BT-03 bin effect spool race grouping or entry identity was invalid.');
        }
        $seenRaceIds[$raceId] = true;
    }

    private function header(): string
    {
        return json_encode([
            'format_version' => self::FORMAT_VERSION,
            'identity' => $this->identity->canonical(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
    }

    private function writeAndHash(string $bytes): void
    {
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $written = fwrite($this->handle, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Could not write the BT-03 bin effect spool.');
            }
            $offset += $written;
        }
        hash_update($this->hash, $bytes);
        $this->byteCount += strlen($bytes);
    }
}
