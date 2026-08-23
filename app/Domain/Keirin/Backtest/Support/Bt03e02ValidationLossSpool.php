<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Support;

use App\Domain\Keirin\Backtest\Services\Bt03e02Contract;
use RuntimeException;

final class Bt03e02ValidationLossSpool
{
    private const VALUE_COUNT = 24;

    private const RECORD_BYTES = self::VALUE_COUNT * 8;

    /** @var resource|null */
    private $handle;

    private int $raceCount = 0;

    private bool $sealed = false;

    /** @var list<string> */
    private readonly array $availableLambdaKeys;

    /** @param list<string>|null $availableLambdaKeys */
    public function __construct(private readonly string $path, ?array $availableLambdaKeys = null)
    {
        $canonicalKeys = array_map(self::lambdaKey(...), Bt03e02Contract::LAMBDA_GRID);
        $availableLambdaKeys ??= $canonicalKeys;
        $availableLambdaKeys = array_map(static fn (int|string $key): string => (string) $key, $availableLambdaKeys);
        if (count($availableLambdaKeys) !== count(array_unique($availableLambdaKeys))
            || array_diff($availableLambdaKeys, $canonicalKeys) !== []) {
            throw new RuntimeException('BT-03E-02 validation loss available candidates were invalid.');
        }
        $this->availableLambdaKeys = array_values(array_filter(
            $canonicalKeys,
            static fn (string $key): bool => in_array($key, $availableLambdaKeys, true),
        ));
        $this->handle = fopen($path, 'xb');
        if ($this->handle === false) {
            throw new RuntimeException('Could not create the BT-03E-02 validation loss spool.');
        }
    }

    /** @param array<string,array<string,?float>> $losses */
    public function append(array $losses): void
    {
        if ($this->handle === null || $this->sealed) {
            throw new RuntimeException('BT-03E-02 validation loss spool was not writable.');
        }
        $values = [];
        foreach (Bt03e02Contract::LAMBDA_GRID as $lambda) {
            $key = self::lambdaKey($lambda);
            foreach (Bt03e02Contract::CHANNELS as $channel) {
                $value = in_array($key, $this->availableLambdaKeys, true)
                    ? ($losses[$key][$channel] ?? null)
                    : null;
                if ($value !== null && ! is_finite($value)) {
                    throw new RuntimeException('BT-03E-02 validation loss was not finite.');
                }
                $values[] = $value ?? NAN;
            }
        }
        if (count($values) !== self::VALUE_COUNT) {
            throw new RuntimeException('BT-03E-02 validation loss vector was invalid.');
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
            throw new RuntimeException('Could not seal the BT-03E-02 validation loss spool.');
        }
        fclose($this->handle);
        $this->handle = null;
        $this->sealed = true;
    }

    public function raceCount(): int
    {
        return $this->raceCount;
    }

    /** @return list<string> */
    public function availableLambdaKeys(): array
    {
        return $this->availableLambdaKeys;
    }

    /** @return \Generator<int,list<?float>> */
    public function records(): \Generator
    {
        if (! $this->sealed) {
            throw new RuntimeException('BT-03E-02 validation loss spool was not sealed.');
        }
        $handle = fopen($this->path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Could not replay the BT-03E-02 validation loss spool.');
        }
        $count = 0;
        try {
            while (($record = fread($handle, self::RECORD_BYTES)) !== '') {
                if (strlen($record) !== self::RECORD_BYTES) {
                    throw new RuntimeException('BT-03E-02 validation loss record was incomplete.');
                }
                $values = unpack('E*', $record);
                if ($values === false || count($values) !== self::VALUE_COUNT) {
                    throw new RuntimeException('BT-03E-02 validation loss record was invalid.');
                }
                $count++;
                yield array_map(static fn (float $value): ?float => is_nan($value) ? null : $value, array_values($values));
            }
            if (! feof($handle) || $count !== $this->raceCount) {
                throw new RuntimeException('BT-03E-02 validation loss count drifted.');
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

    public static function lambdaKey(float $lambda): string
    {
        return sprintf('%.17g', $lambda);
    }

    private function write(string $bytes): void
    {
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $written = fwrite($this->handle, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Could not write the BT-03E-02 validation loss spool.');
            }
            $offset += $written;
        }
    }
}
