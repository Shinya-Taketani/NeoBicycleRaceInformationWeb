<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Support;

use RuntimeException;

class Bt02PredictionSpool
{
    public const FORMAT_VERSION = 'BT02-PAIRED-PREDICTION-JSONL-v1';

    /** @var resource|null */
    private $handle;

    private string $path;

    private \HashContext $baselineHash;

    private \HashContext $incrementalHash;

    private bool $sealed = false;

    private int $rowCount = 0;

    /** @param array<string, int|string> $identity */
    public function __construct(array $identity, ?string $directory = null)
    {
        $path = tempnam($directory ?? sys_get_temp_dir(), 'bt02-prediction-');
        if ($path === false || ($handle = fopen($path, 'wb')) === false) {
            throw new RuntimeException('Could not create the BT-02 prediction spool.');
        }
        $this->path = $path;
        $this->handle = $handle;
        $prefix = json_encode($identity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
        $this->baselineHash = hash_init('sha256');
        $this->incrementalHash = hash_init('sha256');
        hash_update($this->baselineHash, "BASELINE_MATCHED\n{$prefix}");
        hash_update($this->incrementalHash, "INCREMENTAL\n{$prefix}");
    }

    public function append(int $raceId, int $raceEntryId, int $label, float $baseline, float $incremental): void
    {
        if ($this->sealed || ! is_resource($this->handle) || ! in_array($label, [0, 1], true)
            || ! is_finite($baseline) || ! is_finite($incremental)) {
            throw new RuntimeException('BT-02 prediction spool row was invalid.');
        }
        $baselineText = sprintf('%.17g', $baseline);
        $incrementalText = sprintf('%.17g', $incremental);
        $common = "{$raceId},{$raceEntryId},{$label},";
        hash_update($this->baselineHash, $common.$baselineText."\n");
        hash_update($this->incrementalHash, $common.$incrementalText."\n");
        $line = json_encode([
            'format_version' => self::FORMAT_VERSION,
            'race_id' => $raceId,
            'race_entry_id' => $raceEntryId,
            'label' => $label,
            'baseline' => $baselineText,
            'incremental' => $incrementalText,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
        $this->write($line);
        $this->rowCount++;
    }

    /** @return array{baseline: string, incremental: string, rows: int} */
    public function seal(): array
    {
        if ($this->sealed || ! is_resource($this->handle) || $this->rowCount === 0) {
            throw new RuntimeException('BT-02 prediction spool could not be sealed.');
        }
        if (! fflush($this->handle)) {
            throw new RuntimeException('Could not flush the BT-02 prediction spool.');
        }
        fclose($this->handle);
        $this->handle = null;
        $this->sealed = true;

        return [
            'baseline' => hash_final($this->baselineHash),
            'incremental' => hash_final($this->incrementalHash),
            'rows' => $this->rowCount,
        ];
    }

    /** @return list<array{race_id: int, labels: list<int>, baseline: list<float>, incremental: list<float>}> */
    public function racePayloads(): array
    {
        if (! $this->sealed) {
            throw new RuntimeException('BT-02 prediction spool must be sealed before reading.');
        }
        $handle = fopen($this->path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Could not open the BT-02 prediction spool.');
        }
        $payloads = [];
        $current = null;
        try {
            while (($line = fgets($handle)) !== false) {
                $row = $this->decode($line);
                if ($current === null || $current['race_id'] !== $row['race_id']) {
                    if ($current !== null) {
                        $payloads[] = $current;
                    }
                    $current = ['race_id' => $row['race_id'], 'labels' => [], 'baseline' => [], 'incremental' => []];
                }
                $current['labels'][] = $row['label'];
                $current['baseline'][] = $row['baseline'];
                $current['incremental'][] = $row['incremental'];
            }
            if ($current !== null) {
                $payloads[] = $current;
            }
        } finally {
            fclose($handle);
        }

        return $payloads;
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
        if (isset($this->path) && is_file($this->path)) {
            @unlink($this->path);
        }
    }

    public function __destruct()
    {
        $this->cleanup();
    }

    /** @return array{race_id: int, label: int, baseline: float, incremental: float} */
    private function decode(string $line): array
    {
        $row = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($row) || ($row['format_version'] ?? null) !== self::FORMAT_VERSION
            || ! isset($row['race_id'], $row['race_entry_id'], $row['label'], $row['baseline'], $row['incremental'])
            || ! in_array($row['label'], [0, 1], true) || ! is_numeric($row['baseline']) || ! is_numeric($row['incremental'])) {
            throw new RuntimeException('BT-02 prediction spool data was invalid.');
        }

        return [
            'race_id' => (int) $row['race_id'],
            'label' => (int) $row['label'],
            'baseline' => (float) $row['baseline'],
            'incremental' => (float) $row['incremental'],
        ];
    }

    private function write(string $bytes): void
    {
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $written = fwrite($this->handle, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                $this->cleanup();
                throw new RuntimeException('Could not write the BT-02 prediction spool.');
            }
            $offset += $written;
        }
    }
}
