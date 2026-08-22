<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use RuntimeException;

class Bt03eArtifactFilesystem
{
    public function ensureDirectory(string $path): void
    {
        if (! is_dir($path) && ! mkdir($path, 0775, true) && ! is_dir($path)) {
            throw new RuntimeException('Could not create the BT-03E artifact output directory.');
        }
    }

    public function bundleName(): string
    {
        return 'bt03e-historical-forward-scoring-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(16));
    }

    public function exists(string $path): bool
    {
        return file_exists($path);
    }

    public function createDirectory(string $path): void
    {
        if (! mkdir($path, 0775)) {
            throw new RuntimeException('Could not create the BT-03E temporary artifact bundle.');
        }
    }

    public function writeExact(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents, LOCK_EX) !== strlen($contents)) {
            throw new RuntimeException('Could not write the complete BT-03E JSON artifact.');
        }
    }

    /** @return resource */
    public function openExclusive(string $path)
    {
        $handle = fopen($path, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Could not create the BT-03E CSV artifact.');
        }

        return $handle;
    }

    /** @param resource $handle @param list<int|float|string|null> $row */
    public function writeCsvRow($handle, array $row): void
    {
        if (fputcsv($handle, $row, ',', '"', '') === false) {
            throw new RuntimeException('Could not write a complete BT-03E CSV row.');
        }
    }

    /** @param resource $handle */
    public function flushAndSync($handle): void
    {
        if (! fflush($handle)) {
            throw new RuntimeException('Could not flush the BT-03E CSV artifact.');
        }
        if (function_exists('fsync') && ! fsync($handle)) {
            throw new RuntimeException('Could not sync the BT-03E CSV artifact.');
        }
    }

    /** @param resource $handle */
    public function close($handle): void
    {
        if (! fclose($handle)) {
            throw new RuntimeException('Could not close the BT-03E CSV artifact.');
        }
    }

    public function size(string $path): int
    {
        $size = filesize($path);
        if ($size === false) {
            throw new RuntimeException('Could not inspect the BT-03E artifact size.');
        }

        return $size;
    }

    public function publish(string $temporaryDirectory, string $finalDirectory): void
    {
        if ($this->exists($finalDirectory) || ! rename($temporaryDirectory, $finalDirectory)) {
            throw new RuntimeException('Could not atomically publish the BT-03E artifact bundle.');
        }
    }

    public function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        $children = scandir($path);
        if ($children === false) {
            throw new RuntimeException('Could not inspect the BT-03E temporary artifact bundle.');
        }
        foreach ($children as $child) {
            if ($child === '.' || $child === '..') {
                continue;
            }
            $childPath = $path.'/'.$child;
            if (is_dir($childPath)) {
                $this->removeDirectory($childPath);
            } elseif (! unlink($childPath)) {
                throw new RuntimeException('Could not remove a BT-03E temporary artifact file.');
            }
        }
        if (! rmdir($path)) {
            throw new RuntimeException('Could not remove the BT-03E temporary artifact bundle.');
        }
    }
}
