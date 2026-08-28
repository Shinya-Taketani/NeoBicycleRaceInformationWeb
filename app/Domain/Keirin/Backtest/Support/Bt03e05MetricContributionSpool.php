<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Support;

use App\Domain\Keirin\Backtest\Calculators\Bt03e05MetricEvaluator;
use RuntimeException;

final class Bt03e05MetricContributionSpool
{
    private const VALUES_PER_RACE = 33;

    private const RECORD_BYTES = self::VALUES_PER_RACE * 8;

    /** @var resource|null */
    private $handle;

    private int $raceCount = 0;

    private bool $sealed = false;

    public function __construct(private readonly string $path)
    {
        $this->handle = fopen($path, 'xb');
        if ($this->handle === false) {
            throw new RuntimeException('Could not create the BT-03E-05 metric contribution spool.');
        }
    }

    /** @param array<string,mixed> $comparison */
    public function append(array $comparison): void
    {
        if ($this->handle === null || $this->sealed) {
            throw new RuntimeException('BT-03E-05 metric contribution spool was not writable.');
        }
        $values = [];
        foreach (Bt03e05MetricEvaluator::METRIC_CODES as $metric) {
            $values[] = (float) $comparison['candidate'][$metric]['numerator'];
            $values[] = (float) $comparison['baseline'][$metric]['numerator'];
            $values[] = (float) $comparison['candidate'][$metric]['denominator'];
        }
        if (count($values) !== self::VALUES_PER_RACE || array_filter($values, 'is_finite') !== $values) {
            throw new RuntimeException('BT-03E-05 metric contribution was invalid.');
        }
        $this->write(pack('E*', ...$values));
        $this->raceCount++;
    }

    public function seal(): void
    {
        if ($this->sealed) {
            return;
        }
        if ($this->handle === null || ! fflush($this->handle) || (function_exists('fsync') && ! fsync($this->handle))) {
            throw new RuntimeException('Could not seal the BT-03E-05 metric contribution spool.');
        }
        fclose($this->handle);
        $this->handle = null;
        $this->sealed = true;
    }

    public function raceCount(): int
    {
        return $this->raceCount;
    }

    /** @return \Generator<int,list<float>> */
    public function records(): \Generator
    {
        if (! $this->sealed) {
            throw new RuntimeException('BT-03E-05 metric contribution spool was not sealed.');
        }
        $handle = fopen($this->path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Could not replay the BT-03E-05 metric contribution spool.');
        }
        $count = 0;
        try {
            while (($record = fread($handle, self::RECORD_BYTES)) !== '') {
                if (strlen($record) !== self::RECORD_BYTES) {
                    throw new RuntimeException('BT-03E-05 metric contribution record was incomplete.');
                }
                $values = unpack('E*', $record);
                if ($values === false || count($values) !== self::VALUES_PER_RACE) {
                    throw new RuntimeException('BT-03E-05 metric contribution record was invalid.');
                }
                $count++;
                yield array_values($values);
            }
            if (! feof($handle) || $count !== $this->raceCount) {
                throw new RuntimeException('BT-03E-05 metric contribution count drifted.');
            }
        } finally {
            fclose($handle);
        }
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
                throw new RuntimeException('Could not write the BT-03E-05 metric contribution spool.');
            }
            $offset += $written;
        }
    }
}
