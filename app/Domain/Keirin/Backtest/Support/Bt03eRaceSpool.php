<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Support;

use App\Domain\Keirin\Backtest\DTO\Bt03eSpoolMetadataDto;
use App\Domain\Keirin\Backtest\Services\Bt03eContract;
use RuntimeException;

class Bt03eRaceSpool
{
    /** @var resource|null */
    private $handle;

    private int $raceCount = 0;

    private int $entryCount = 0;

    private int $byteCount = 0;

    /** @var resource */
    private $hash;

    private ?Bt03eSpoolMetadataDto $metadata = null;

    public function __construct(
        private readonly int $year,
        private readonly string $path,
    ) {
        if (! in_array($year, [Bt03eContract::TRAINING_YEAR, Bt03eContract::EVALUATION_YEAR], true)) {
            throw new RuntimeException('BT-03E spool year was outside the fixed contract.');
        }
        $this->handle = fopen($path, 'xb');
        if ($this->handle === false) {
            throw new RuntimeException('Could not create the BT-03E race spool.');
        }
        $this->hash = hash_init('sha256');
    }

    /** @param list<array{id: int, bike: int, raw: float, stat01_rank: int, directions: list<int>, rank: ?int, status: string}> $entries */
    public function append(int $raceId, array $entries): void
    {
        if ($this->handle === null || $this->metadata !== null || $raceId < 1 || $entries === []) {
            throw new RuntimeException('BT-03E spool append state was invalid.');
        }
        $line = json_encode([
            'v' => Bt03eContract::SPOOL_FORMAT,
            'race_id' => $raceId,
            'entries' => $entries,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
        if (fwrite($this->handle, $line) !== strlen($line)) {
            throw new RuntimeException('Could not write the BT-03E race spool.');
        }
        hash_update($this->hash, $line);
        $this->raceCount++;
        $this->entryCount += count($entries);
        $this->byteCount += strlen($line);
    }

    public function seal(): Bt03eSpoolMetadataDto
    {
        if ($this->metadata !== null) {
            return $this->metadata;
        }
        if ($this->handle === null || ! fflush($this->handle)) {
            throw new RuntimeException('Could not seal the BT-03E race spool.');
        }
        fclose($this->handle);
        $this->handle = null;
        $this->metadata = new Bt03eSpoolMetadataDto(
            $this->year,
            $this->raceCount,
            $this->entryCount,
            $this->byteCount,
            hash_final($this->hash),
        );

        return $this->metadata;
    }

    /** @return \Generator<int, array{race_id: int, entries: list<array{id: int, bike: int, raw: float, stat01_rank: int, directions: list<int>, rank: ?int, status: string}>}> */
    public function races(): \Generator
    {
        $metadata = $this->metadata ?? throw new RuntimeException('BT-03E spool must be sealed before replay.');
        $handle = fopen($this->path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Could not replay the BT-03E race spool.');
        }
        $hash = hash_init('sha256');
        $raceCount = $entryCount = $byteCount = 0;
        try {
            while (($line = fgets($handle)) !== false) {
                if (! str_ends_with($line, "\n")) {
                    throw new RuntimeException('BT-03E spool row was incomplete.');
                }
                hash_update($hash, $line);
                $byteCount += strlen($line);
                $row = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($row) || ($row['v'] ?? null) !== Bt03eContract::SPOOL_FORMAT
                    || ! is_int($row['race_id'] ?? null) || ! is_array($row['entries'] ?? null) || $row['entries'] === []) {
                    throw new RuntimeException('BT-03E spool row contract was invalid.');
                }
                $raceCount++;
                $entryCount += count($row['entries']);
                yield ['race_id' => $row['race_id'], 'entries' => $row['entries']];
            }
            if (! feof($handle)
                || $raceCount !== $metadata->raceCount
                || $entryCount !== $metadata->entryCount
                || $byteCount !== $metadata->byteCount
                || ! hash_equals($metadata->sha256, hash_final($hash))) {
                throw new RuntimeException('BT-03E spool replay did not match its seal.');
            }
        } finally {
            fclose($handle);
        }
    }

    public function metadata(): Bt03eSpoolMetadataDto
    {
        return $this->metadata ?? throw new RuntimeException('BT-03E spool was not sealed.');
    }

    public function cleanup(): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function __destruct()
    {
        $this->cleanup();
    }
}
