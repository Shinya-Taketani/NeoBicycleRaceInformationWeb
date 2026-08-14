<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Support;

use App\Domain\Keirin\Backtest\DTO\Bt02BinaryLabelsDto;
use App\Domain\Keirin\Backtest\DTO\Bt02EvaluationRowDto;
use App\Domain\Keirin\Backtest\DTO\Bt02SpoolMetadataDto;
use RuntimeException;

class Bt02EvaluationRowSpool
{
    public const FORMAT_VERSION = 'BT02-EVALUATION-ROW-JSONL-v1';

    /** @var resource|null */
    private $handle;

    private string $path;

    private ?\HashContext $hash;

    private int $rowCount = 0;

    private int $byteCount = 0;

    private ?Bt02SpoolMetadataDto $metadata = null;

    private ?int $lastRaceId = null;

    private ?int $lastRaceEntryId = null;

    /** @var array<int, true> */
    private array $seenRaceIds = [];

    private function __construct(?string $directory)
    {
        $path = tempnam($directory ?? sys_get_temp_dir(), 'bt02-evaluation-row-');
        if ($path === false || ($handle = fopen($path, 'wb')) === false) {
            throw new RuntimeException('Could not create the BT-02 evaluation row spool.');
        }
        $this->path = $path;
        $this->handle = $handle;
        $this->hash = hash_init('sha256');
    }

    /** @param iterable<Bt02EvaluationRowDto> $rows */
    public static function create(iterable $rows, ?string $directory = null): self
    {
        $spool = new self($directory);
        try {
            foreach ($rows as $row) {
                if (! $row instanceof Bt02EvaluationRowDto) {
                    throw new RuntimeException('BT-02 evaluation spool input row was invalid.');
                }
                $spool->append($row);
            }
            $spool->seal();

            return $spool;
        } catch (\Throwable $throwable) {
            $spool->cleanup();
            throw $throwable;
        }
    }

    /** @return \Generator<int, Bt02EvaluationRowDto> */
    public function rows(): \Generator
    {
        $metadata = $this->metadata ?? throw new RuntimeException('BT-02 evaluation row spool was not sealed.');
        $handle = fopen($this->path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Could not replay the BT-02 evaluation row spool.');
        }
        $hash = hash_init('sha256');
        $count = $bytes = 0;
        $lastRaceId = $lastRaceEntryId = null;
        $seenRaceIds = [];
        try {
            while (($line = fgets($handle)) !== false) {
                if (! str_ends_with($line, "\n")) {
                    throw new RuntimeException('BT-02 evaluation row spool row did not end with LF.');
                }
                hash_update($hash, $line);
                $count++;
                $bytes += strlen($line);
                $row = $this->decode($line);
                $this->assertOrdered($row->raceId, $row->raceEntryId, $lastRaceId, $lastRaceEntryId, $seenRaceIds);
                $lastRaceId = $row->raceId;
                $lastRaceEntryId = $row->raceEntryId;
                yield $row;
            }
            if (! feof($handle)) {
                throw new RuntimeException('Could not fully read the BT-02 evaluation row spool.');
            }
            $actualHash = hash_final($hash);
            if ($count !== $metadata->rowCount || $bytes !== $metadata->byteCount
                || ! hash_equals($metadata->sha256, $actualHash)) {
                throw new RuntimeException('BT-02 evaluation row spool identity did not match its seal metadata.');
            }
        } finally {
            fclose($handle);
        }
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

    private function append(Bt02EvaluationRowDto $row): void
    {
        if (! is_resource($this->handle) || $this->hash === null || $this->metadata !== null
            || ! is_finite($row->baselineValue) || ! is_finite($row->signalValue)) {
            throw new RuntimeException('BT-02 evaluation row spool was not writable.');
        }
        $this->assertOrdered(
            $row->raceId,
            $row->raceEntryId,
            $this->lastRaceId,
            $this->lastRaceEntryId,
            $this->seenRaceIds,
        );
        $line = json_encode([
            'format_version' => self::FORMAT_VERSION,
            'race_id' => $row->raceId,
            'race_entry_id' => $row->raceEntryId,
            'baseline' => sprintf('%.17g', $row->baselineValue),
            'signal' => sprintf('%.17g', $row->signalValue),
            'is_win' => (int) $row->labels->isWin,
            'is_top2' => (int) $row->labels->isTop2,
            'is_top3' => (int) $row->labels->isTop3,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
        $this->write($line);
        hash_update($this->hash, $line);
        $this->rowCount++;
        $this->byteCount += strlen($line);
        $this->lastRaceId = $row->raceId;
        $this->lastRaceEntryId = $row->raceEntryId;
    }

    private function seal(): void
    {
        if (! is_resource($this->handle) || $this->hash === null || $this->rowCount === 0) {
            throw new RuntimeException('BT-02 evaluation row spool could not be sealed.');
        }
        if (! fflush($this->handle) || (function_exists('fsync') && ! fsync($this->handle))) {
            throw new RuntimeException('Could not flush the BT-02 evaluation row spool.');
        }
        fclose($this->handle);
        $this->handle = null;
        $this->metadata = new Bt02SpoolMetadataDto(
            $this->rowCount,
            $this->byteCount,
            hash_final($this->hash),
            self::FORMAT_VERSION,
        );
        $this->hash = null;
    }

    private function decode(string $line): Bt02EvaluationRowDto
    {
        $row = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($row)
            || array_keys($row) !== ['format_version', 'race_id', 'race_entry_id', 'baseline', 'signal', 'is_win', 'is_top2', 'is_top3']
            || $row['format_version'] !== self::FORMAT_VERSION
            || ! is_int($row['race_id']) || $row['race_id'] < 1
            || ! is_int($row['race_entry_id']) || $row['race_entry_id'] < 1
            || ! is_string($row['baseline']) || ! is_numeric($row['baseline'])
            || ! is_string($row['signal']) || ! is_numeric($row['signal'])
            || ! in_array($row['is_win'], [0, 1], true)
            || ! in_array($row['is_top2'], [0, 1], true)
            || ! in_array($row['is_top3'], [0, 1], true)) {
            throw new RuntimeException('BT-02 evaluation row spool data was invalid.');
        }
        $baseline = (float) $row['baseline'];
        $signal = (float) $row['signal'];
        if (! is_finite($baseline) || ! is_finite($signal)) {
            throw new RuntimeException('BT-02 evaluation row spool feature was not finite.');
        }

        return new Bt02EvaluationRowDto(
            $row['race_id'],
            $row['race_entry_id'],
            $baseline,
            $signal,
            new Bt02BinaryLabelsDto((bool) $row['is_win'], (bool) $row['is_top2'], (bool) $row['is_top3']),
        );
    }

    /** @param array<int, true> $seenRaceIds */
    private function assertOrdered(
        int $raceId,
        int $raceEntryId,
        ?int $lastRaceId,
        ?int $lastRaceEntryId,
        array &$seenRaceIds,
    ): void {
        if ($raceId < 1 || $raceEntryId < 1
            || ($raceId === $lastRaceId && $raceEntryId <= $lastRaceEntryId)
            || ($raceId !== $lastRaceId && isset($seenRaceIds[$raceId]))) {
            throw new RuntimeException('BT-02 evaluation row spool order or identity was invalid.');
        }
        $seenRaceIds[$raceId] = true;
    }

    private function write(string $bytes): void
    {
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $written = fwrite($this->handle, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Could not write the BT-02 evaluation row spool.');
            }
            $offset += $written;
        }
    }
}
