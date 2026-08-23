<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Support;

use App\Domain\Keirin\Backtest\Services\Bt03e02Contract;
use RuntimeException;

final class Bt03e02RaceSpool
{
    /** @var resource|null */
    private $handle;

    /** @var resource */
    private $hash;

    private int $raceCount = 0;

    private int $entryCount = 0;

    private int $byteCount = 0;

    /** @var array{role:string,race_count:int,entry_count:int,byte_count:int,sha256:string}|null */
    private ?array $metadata = null;

    public function __construct(private readonly string $role, private readonly string $path)
    {
        if (! in_array($role, ['RAW', 'BINNED', 'PREDICTION'], true)) {
            throw new RuntimeException('BT-03E-02 spool role was invalid.');
        }
        $this->handle = fopen($path, 'xb');
        if ($this->handle === false) {
            throw new RuntimeException('Could not create the BT-03E-02 race spool.');
        }
        $this->hash = hash_init('sha256');
    }

    /** @param array{year:int,race_id:int,entries:list<array<string,mixed>>} $race */
    public function append(array $race): void
    {
        if ($this->handle === null || $this->metadata !== null
            || ! in_array($race['year'] ?? null, Bt03e02Contract::DEVELOPMENT_YEARS, true)
            || ! is_int($race['race_id'] ?? null) || $race['race_id'] < 1
            || ! is_array($race['entries'] ?? null) || $race['entries'] === []) {
            throw new RuntimeException('BT-03E-02 spool row was invalid.');
        }
        $line = json_encode([
            'v' => Bt03e02Contract::SPOOL_VERSION,
            'role' => $this->role,
            ...$race,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)."\n";
        $this->write($line);
        hash_update($this->hash, $line);
        $this->raceCount++;
        $this->entryCount += count($race['entries']);
        $this->byteCount += strlen($line);
    }

    /** @return array{role:string,race_count:int,entry_count:int,byte_count:int,sha256:string} */
    public function seal(): array
    {
        if ($this->metadata !== null) {
            return $this->metadata;
        }
        if ($this->handle === null || ! fflush($this->handle) || (function_exists('fsync') && ! fsync($this->handle))) {
            throw new RuntimeException('Could not seal the BT-03E-02 race spool.');
        }
        fclose($this->handle);
        $this->handle = null;

        return $this->metadata = [
            'role' => $this->role,
            'race_count' => $this->raceCount,
            'entry_count' => $this->entryCount,
            'byte_count' => $this->byteCount,
            'sha256' => hash_final($this->hash),
        ];
    }

    /** @return \Generator<int, array{year:int,race_id:int,entries:list<array<string,mixed>>}> */
    public function races(): \Generator
    {
        $metadata = $this->metadata ?? throw new RuntimeException('BT-03E-02 spool must be sealed before replay.');
        $handle = fopen($this->path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Could not replay the BT-03E-02 race spool.');
        }
        $hash = hash_init('sha256');
        $races = $entries = $bytes = 0;
        try {
            while (($line = fgets($handle)) !== false) {
                if (! str_ends_with($line, "\n")) {
                    throw new RuntimeException('BT-03E-02 spool row was incomplete.');
                }
                hash_update($hash, $line);
                $bytes += strlen($line);
                $row = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($row) || ($row['v'] ?? null) !== Bt03e02Contract::SPOOL_VERSION
                    || ($row['role'] ?? null) !== $this->role
                    || ! in_array($row['year'] ?? null, Bt03e02Contract::DEVELOPMENT_YEARS, true)
                    || ! is_int($row['race_id'] ?? null)
                    || ! is_array($row['entries'] ?? null) || $row['entries'] === []) {
                    throw new RuntimeException('BT-03E-02 spool replay contract was invalid.');
                }
                $races++;
                $entries += count($row['entries']);
                yield ['year' => $row['year'], 'race_id' => $row['race_id'], 'entries' => $row['entries']];
            }
            if (! feof($handle) || $races !== $metadata['race_count'] || $entries !== $metadata['entry_count']
                || $bytes !== $metadata['byte_count'] || ! hash_equals($metadata['sha256'], hash_final($hash))) {
                throw new RuntimeException('BT-03E-02 spool replay did not match its seal.');
            }
        } finally {
            fclose($handle);
        }
    }

    /** @return array{role:string,race_count:int,entry_count:int,byte_count:int,sha256:string} */
    public function metadata(): array
    {
        return $this->metadata ?? throw new RuntimeException('BT-03E-02 spool was not sealed.');
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

    private function write(string $bytes): void
    {
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $written = fwrite($this->handle, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Could not write the BT-03E-02 race spool.');
            }
            $offset += $written;
        }
    }
}
