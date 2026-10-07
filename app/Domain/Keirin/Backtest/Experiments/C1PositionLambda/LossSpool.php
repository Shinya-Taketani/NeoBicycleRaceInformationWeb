<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\C1PositionLambda;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Services\Bt03e03Contract;
use RuntimeException;

final class LossSpool
{
    private $handle;

    private int $count = 0;

    private int $eligible = 0;

    private ?array $seal = null;

    private $mask;

    public readonly array $available;

    public function __construct(private readonly string $path, public readonly string $position, array $available)
    {
        $grid = array_map(self::key(...), Bt03e03Contract::LAMBDA_GRID);
        $available = array_map('strval', $available);
        if (! in_array($position, Bt03e03Contract::POSITIONS, true) || $available === []
            || count($available) !== count(array_unique($available)) || array_diff($available, $grid) !== []) {
            throw new RuntimeException('Invalid per-position loss candidates.');
        }
        $this->available = array_values(array_intersect($grid, $available));
        $this->handle = fopen($path, 'xb');
        $this->mask = hash_init('sha256');
        if ($this->handle === false) {
            throw new RuntimeException('Could not create position loss spool.');
        }
    }

    public function append(array $losses): void
    {
        if ($this->seal !== null || ! is_resource($this->handle)
            || array_map('strval', array_keys($losses)) !== $this->available) {
            throw new RuntimeException('Loss candidate keys or writable state disagreed.');
        }
        $mask = null;
        foreach ($losses as $loss) {
            if ($loss !== null && ((! is_int($loss) && ! is_float($loss)) || ! is_finite($loss) || $loss < 0)) {
                throw new RuntimeException('Non-finite/invalid validation loss.');
            }
            $present = $loss !== null;
            if ($mask !== null && $mask !== $present) {
                throw new RuntimeException('Candidate eligibility masks disagreed.');
            }
            $mask = $present;
        }
        $values = [];
        foreach (Bt03e03Contract::LAMBDA_GRID as $lambda) {
            $values[] = $losses[self::key($lambda)] ?? NAN;
        }
        if (fwrite($this->handle, pack('E*', ...$values)) !== 64) {
            throw new RuntimeException('Position loss spool write failed.');
        }
        $this->count++;
        $this->eligible += (int) $mask;
        hash_update($this->mask, $mask ? '1' : '0');
    }

    public function seal(): void
    {
        if ($this->seal !== null) {
            return;
        }
        if (! fflush($this->handle) || (function_exists('fsync') && ! fsync($this->handle))) {
            throw new RuntimeException('Position loss seal failed.');
        }
        fclose($this->handle);
        $this->handle = null;
        $this->seal = Files::identity($this->path);
    }

    public function audit(): array
    {
        if ($this->seal === null) {
            throw new RuntimeException('Loss spool was not sealed.');
        }

        return ['position' => $this->position, 'races' => $this->count, 'eligible' => $this->eligible,
            'excluded' => $this->count - $this->eligible, 'mask_sha256' => hash_final(hash_copy($this->mask)), 'seal' => $this->seal];
    }

    public function records(): \Generator
    {
        if ($this->seal === null) {
            throw new RuntimeException('Loss spool was not sealed.');
        }
        $handle = fopen($this->path, 'rb');
        $count = 0;
        try {
            while (($bytes = fread($handle, 64)) !== '') {
                if (strlen($bytes) !== 64) {
                    throw new RuntimeException('Incomplete position loss record.');
                }
                $count++;
                yield array_map(static fn ($v) => is_nan($v) ? null : $v, array_values(unpack('E*', $bytes)));
            }
            if (! feof($handle) || $count !== $this->count) {
                throw new RuntimeException('Loss record count drifted.');
            }
        } finally {
            fclose($handle);
        }
    }

    public function verify(): void
    {
        Files::verify($this->path, $this->audit()['seal']);
    }

    public static function key(float $lambda): string
    {
        return sprintf('%.17g', $lambda);
    }

    public function __destruct()
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }
}
