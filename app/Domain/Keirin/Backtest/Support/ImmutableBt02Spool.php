<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Support;

use App\Domain\Keirin\Backtest\DTO\Bt02SpoolMetadataDto;
use App\Domain\Keirin\Backtest\DTO\LogisticTrainingRowDto;
use RuntimeException;

class ImmutableBt02Spool
{
    public const FORMAT_VERSION = 'BT02-LOGISTIC-JSONL-v1';

    /** @var resource|null */
    private $handle;

    private ?\HashContext $hashContext;

    private int $rowCount = 0;

    private int $byteCount = 0;

    private ?Bt02SpoolMetadataDto $metadata = null;

    private string $path;

    public function __construct(?string $directory = null)
    {
        $directory ??= sys_get_temp_dir();
        $path = tempnam($directory, 'bt02-spool-');
        if ($path === false) {
            throw new RuntimeException('Could not create the BT-02 spool path.');
        }
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            @unlink($path);
            throw new RuntimeException('Could not open the BT-02 spool.');
        }
        $this->path = $path;
        $this->handle = $handle;
        $this->hashContext = hash_init('sha256');
    }

    public function append(LogisticTrainingRowDto $row): void
    {
        $this->assertWritable();
        if ($row->features === [] || ! in_array($row->label, [0, 1], true)) {
            throw new RuntimeException('BT-02 spool row was invalid.');
        }
        $features = [];
        foreach ($row->features as $feature) {
            if (! is_float($feature) || ! is_finite($feature)) {
                throw new RuntimeException('BT-02 spool feature was not a finite float.');
            }
            $features[] = sprintf('%.17g', $feature);
        }
        $line = json_encode([
            'format_version' => self::FORMAT_VERSION,
            'features' => $features,
            'label' => $row->label,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)."\n";

        try {
            $this->write($line);
            hash_update($this->hashContext, $line);
            $this->rowCount++;
            $this->byteCount += strlen($line);
        } catch (\Throwable $throwable) {
            $this->cleanup();
            throw $throwable;
        }
    }

    public function seal(): Bt02SpoolMetadataDto
    {
        $this->assertWritable();
        if ($this->rowCount === 0) {
            $this->cleanup();
            throw new RuntimeException('BT-02 spool cannot seal without rows.');
        }
        try {
            if (! fflush($this->handle)) {
                throw new RuntimeException('Could not flush the BT-02 spool.');
            }
            if (function_exists('fsync') && ! fsync($this->handle)) {
                throw new RuntimeException('Could not fsync the BT-02 spool.');
            }
            fclose($this->handle);
            $this->handle = null;
            $sha256 = hash_final($this->hashContext);
            $this->hashContext = null;
            $this->metadata = new Bt02SpoolMetadataDto(
                $this->rowCount,
                $this->byteCount,
                $sha256,
                self::FORMAT_VERSION,
            );

            return $this->metadata;
        } catch (\Throwable $throwable) {
            $this->cleanup();
            throw $throwable;
        }
    }

    public function metadata(): Bt02SpoolMetadataDto
    {
        return $this->metadata ?? throw new RuntimeException('BT-02 spool has not been sealed.');
    }

    public function path(): string
    {
        return $this->path;
    }

    public function isSealed(): bool
    {
        return $this->metadata !== null;
    }

    public function cleanup(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
        $this->handle = null;
        $this->hashContext = null;
        if (isset($this->path) && is_file($this->path)) {
            @unlink($this->path);
        }
    }

    public function __destruct()
    {
        $this->cleanup();
    }

    private function assertWritable(): void
    {
        if ($this->metadata !== null || ! is_resource($this->handle) || $this->hashContext === null) {
            throw new RuntimeException('BT-02 spool is not writable.');
        }
    }

    private function write(string $bytes): void
    {
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $written = fwrite($this->handle, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Could not write the BT-02 spool.');
            }
            $offset += $written;
        }
    }
}
