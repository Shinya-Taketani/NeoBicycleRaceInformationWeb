<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\InitialHistoryMetadata;

use App\Domain\Keirin\Backtest\ChronologicalValidation\Json;

final class Writer
{
    private mixed $stream;

    private \HashContext $hash;

    private int $bytes = 0;

    private int $rows = 0;

    public function __construct(string $path)
    {
        Evidence::require(! file_exists($path) && ! is_link($path), 'Metadata stream already exists.');
        $this->stream = fopen($path, 'xb');
        Evidence::require(is_resource($this->stream), 'New metadata stream required.');
        $this->hash = hash_init('sha256');
    }

    public function append(array $row): void
    {
        $line = Json::encode($row);
        Evidence::require(strlen($line) <= 1048576 && fwrite($this->stream, $line) === strlen($line), 'Metadata stream write failed.');
        hash_update($this->hash, $line);
        $this->bytes += strlen($line);
        $this->rows++;
    }

    public function finish(): array
    {
        Evidence::require(fflush($this->stream) && fsync($this->stream), 'Metadata stream flush failed.');
        fclose($this->stream);
        $this->stream = null;

        return ['bytes' => $this->bytes, 'sha256' => hash_final($this->hash), 'rows' => $this->rows];
    }

    public function __destruct()
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }
}
