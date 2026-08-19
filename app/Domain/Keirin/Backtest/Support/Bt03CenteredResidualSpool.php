<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Support;

use App\Domain\Keirin\Backtest\DTO\Bt03CenteredRacePayloadDto;
use App\Domain\Keirin\Backtest\DTO\Bt03CenteredResidualEntryDto;
use App\Domain\Keirin\Backtest\DTO\Bt03CenteredSpoolIdentityDto;
use App\Domain\Keirin\Backtest\DTO\Bt03CenteredSpoolMetadataDto;
use JsonException;
use RuntimeException;

class Bt03CenteredResidualSpool
{
    public const FORMAT_VERSION = 'BT03-CENTERED-RESIDUAL-SPOOL-JSONL-v1';

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

    private ?Bt03CenteredSpoolMetadataDto $metadata = null;

    public function __construct(
        private readonly Bt03CenteredSpoolIdentityDto $identity,
        ?string $directory = null,
    ) {
        $path = tempnam($directory ?? sys_get_temp_dir(), 'bt03-centered-residual-');
        if ($path === false || ($handle = fopen($path, 'wb')) === false) {
            throw new RuntimeException('Could not create the BT-03 centered residual spool.');
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

    public function append(int $raceId, Bt03CenteredResidualEntryDto $entry): void
    {
        if (! is_resource($this->handle) || $this->hash === null || $this->metadata !== null
            || $raceId < 1 || $entry->raceEntryId < 1 || $entry->binIndex < 0
            || ! in_array($entry->label, [0, 1], true)
            || ! is_finite($entry->baselineProbability)
            || $entry->baselineProbability < 0.0 || $entry->baselineProbability > 1.0) {
            throw new RuntimeException('BT-03 centered residual spool row was invalid.');
        }
        $this->assertOrdered($raceId, $entry->raceEntryId, $this->lastRaceId, $this->lastRaceEntryId, $this->seenRaceIds);
        $line = json_encode([
            'race_id' => $raceId,
            'race_entry_id' => $entry->raceEntryId,
            'bin_index' => $entry->binIndex,
            'label' => $entry->label,
            'baseline_probability' => sprintf('%.17g', $entry->baselineProbability),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
        $this->writeAndHash($line);
        $this->rowCount++;
        if ($raceId !== $this->lastRaceId) {
            $this->raceCount++;
        }
        $this->lastRaceId = $raceId;
        $this->lastRaceEntryId = $entry->raceEntryId;
    }

    public function seal(): Bt03CenteredSpoolMetadataDto
    {
        if (! is_resource($this->handle) || $this->hash === null || $this->metadata !== null) {
            throw new RuntimeException('BT-03 centered residual spool was not sealable.');
        }
        try {
            if (! fflush($this->handle) || (function_exists('fsync') && ! fsync($this->handle))) {
                throw new RuntimeException('Could not flush the BT-03 centered residual spool.');
            }
            fclose($this->handle);
            $this->handle = null;
            $this->metadata = new Bt03CenteredSpoolMetadataDto(
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

    public function metadata(): Bt03CenteredSpoolMetadataDto
    {
        return $this->metadata ?? throw new RuntimeException('BT-03 centered residual spool was not sealed.');
    }

    /** @return \Generator<int, Bt03CenteredRacePayloadDto> */
    public function payloads(): \Generator
    {
        $metadata = $this->metadata();
        $handle = fopen($this->path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Could not replay the BT-03 centered residual spool.');
        }
        $hash = hash_init('sha256');
        $rows = $races = $bytes = 0;
        $lastRaceId = $lastRaceEntryId = null;
        $seenRaceIds = [];
        try {
            $header = fgets($handle);
            if ($header !== $this->header()) {
                throw new RuntimeException('BT-03 centered residual spool identity header was invalid.');
            }
            hash_update($hash, $header);
            $bytes += strlen($header);
            $raceId = null;
            $entries = [];
            while (($line = fgets($handle)) !== false) {
                if (! str_ends_with($line, "\n")) {
                    throw new RuntimeException('BT-03 centered residual spool row did not end with LF.');
                }
                [$nextRaceId, $entry] = $this->decode($line);
                $this->assertOrdered($nextRaceId, $entry->raceEntryId, $lastRaceId, $lastRaceEntryId, $seenRaceIds);
                if ($nextRaceId !== $lastRaceId) {
                    $races++;
                }
                $lastRaceId = $nextRaceId;
                $lastRaceEntryId = $entry->raceEntryId;
                $rows++;
                $bytes += strlen($line);
                hash_update($hash, $line);
                if ($raceId !== null && $nextRaceId !== $raceId) {
                    yield new Bt03CenteredRacePayloadDto($raceId, $entries);
                    $entries = [];
                }
                $raceId = $nextRaceId;
                $entries[] = $entry;
            }
            if (! feof($handle)) {
                throw new RuntimeException('Could not fully replay the BT-03 centered residual spool.');
            }
            if ($raceId !== null) {
                yield new Bt03CenteredRacePayloadDto($raceId, $entries);
            }
            if ($rows !== $metadata->rowCount || $races !== $metadata->raceCount || $bytes !== $metadata->byteCount
                || ! hash_equals($metadata->sha256, hash_final($hash))) {
                throw new RuntimeException('BT-03 centered residual spool did not match its seal metadata.');
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

    /** @return array{int, Bt03CenteredResidualEntryDto} */
    private function decode(string $line): array
    {
        try {
            $row = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('BT-03 centered residual spool JSON was invalid.', previous: $exception);
        }
        if (! is_array($row)
            || array_keys($row) !== ['race_id', 'race_entry_id', 'bin_index', 'label', 'baseline_probability']
            || ! is_int($row['race_id']) || $row['race_id'] < 1
            || ! is_int($row['race_entry_id']) || $row['race_entry_id'] < 1
            || ! is_int($row['bin_index']) || $row['bin_index'] < 0
            || ! in_array($row['label'], [0, 1], true)
            || ! is_string($row['baseline_probability']) || ! is_numeric($row['baseline_probability'])) {
            throw new RuntimeException('BT-03 centered residual spool data was invalid.');
        }
        $baseline = (float) $row['baseline_probability'];
        if (! is_finite($baseline) || $baseline < 0.0 || $baseline > 1.0) {
            throw new RuntimeException('BT-03 centered residual spool probability was invalid.');
        }

        return [
            $row['race_id'],
            new Bt03CenteredResidualEntryDto(
                $row['race_entry_id'],
                $row['bin_index'],
                $row['label'],
                $baseline,
            ),
        ];
    }

    /** @param array<int, true> $seenRaceIds */
    private function assertOrdered(int $raceId, int $raceEntryId, ?int $lastRaceId, ?int $lastRaceEntryId, array &$seenRaceIds): void
    {
        if (($raceId === $lastRaceId && $raceEntryId <= $lastRaceEntryId)
            || ($raceId !== $lastRaceId && isset($seenRaceIds[$raceId]))) {
            throw new RuntimeException('BT-03 centered residual spool race grouping or entry identity was invalid.');
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
                throw new RuntimeException('Could not write the BT-03 centered residual spool.');
            }
            $offset += $written;
        }
        hash_update($this->hash, $bytes);
        $this->byteCount += strlen($bytes);
    }
}
