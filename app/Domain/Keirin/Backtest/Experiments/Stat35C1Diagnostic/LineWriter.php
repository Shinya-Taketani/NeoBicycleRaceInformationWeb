<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\Stat35C1Diagnostic;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use RuntimeException;

final class LineWriter
{
    private mixed $handle;

    private \HashContext $hash;

    private int $bytes = 0;

    public function __construct(private readonly string $path)
    {
        if (file_exists($path) || is_link($path) || ($this->handle = fopen($path.'.partial', 'xb')) === false) {
            throw new RuntimeException('Refusing diagnostic overwrite.');
        }
        $this->hash = hash_init('sha256');
    }

    public function append(array $row): void
    {
        $line = Files::canonical($row)."\n";
        if (fwrite($this->handle, $line) !== strlen($line)) {
            throw new RuntimeException('Short diagnostic write.');
        }
        hash_update($this->hash, $line);
        $this->bytes += strlen($line);
    }

    public function finish(): array
    {
        if (! fflush($this->handle) || ! fsync($this->handle)) {
            throw new RuntimeException('Diagnostic flush failed.');
        }
        fclose($this->handle);
        $this->handle = null;
        $seal = ['bytes' => $this->bytes, 'sha256' => hash_final($this->hash)];
        if (! rename($this->path.'.partial', $this->path)) {
            throw new RuntimeException('Diagnostic finalization failed.');
        }
        Files::verify($this->path, $seal);

        return $seal;
    }

    public function __destruct()
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }
}
