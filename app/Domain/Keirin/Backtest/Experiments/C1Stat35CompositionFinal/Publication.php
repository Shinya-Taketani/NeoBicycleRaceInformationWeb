<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact as Jsonl;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class Publication
{
    public function destination(string $path, array $sources = []): string
    {
        $parent = realpath(dirname($path));
        $name = basename($path);
        if ($path === '' || $parent === false || in_array($name, ['', '.', '..'], true)) {
            throw new RuntimeException('Publication requires an existing parent and a new destination.');
        }
        $destination = $parent.'/'.$name;
        // The already-normalized destination is checked without accessing other bundles.
        $root = rtrim(Contract::ROOT, '/');
        $test = app()->environment('testing') && str_starts_with($destination, sys_get_temp_dir().'/');
        if ((! $test && ! str_starts_with($destination, $root.'/'))
            || str_starts_with($destination, base_path().'/')) {
            throw new RuntimeException('Publication outside the agreed root.');
        }
        foreach ($sources as $source) {
            $source = realpath($source);
            if ($source === false || $destination === $source || str_starts_with($destination.'/', $source.'/')
                || str_starts_with($source.'/', $destination.'/')) {
                throw new RuntimeException('Publication source/output overlap or missing source.');
            }
        }
        $this->absent($destination);

        return $destination;
    }

    public function acquire(string $destination): mixed
    {
        $path = dirname($destination).'/.composition-lock-'.hash('sha256', $destination);
        if (is_link($path)) {
            throw new RuntimeException('Publication lock symlink forbidden.');
        }
        $handle = fopen($path, 'c+b');
        if ($handle === false) {
            throw new RuntimeException('Could not open publication lock.');
        }
        $stat = fstat($handle);
        $disk = lstat($path);
        if ($stat['ino'] !== $disk['ino'] || $stat['dev'] !== $disk['dev'] || ($stat['mode'] & 0170000) !== 0100000
            || ! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new RuntimeException('Publication conflict: destination is locked.');
        }
        try {
            $this->absent($destination);
        } catch (Throwable $e) {
            fclose($handle);
            throw $e;
        }

        return $handle;
    }

    public function stage(string $destination): string
    {
        return Files::directory($destination.'.inprogress-'.bin2hex(random_bytes(12)));
    }

    public function json(string $path, array $value): void
    {
        Jsonl::json($path, $value);
    }

    public function rows(string $path, iterable $rows): array
    {
        return Jsonl::write($path, $rows);
    }

    public function seal(string $stage, string $destination, string $kind, array $packages, array $evidence = []): void
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($stage, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                $files[substr($file->getPathname(), strlen($stage) + 1)] = Files::identity($file->getPathname());
            }
        }
        ksort($files, SORT_STRING);
        foreach ($packages as $package) {
            if (! isset($files[$package])) {
                throw new RuntimeException('Missing publication package owner.');
            }
        }
        $this->json($stage.'/publication.json', ['version' => Contract::PUBLICATION_VERSION, 'state' => 'COMMITTED',
            'kind' => $kind, 'destination' => $destination, 'packages' => $packages, 'files' => $files, 'evidence' => $evidence]);
        $this->json($stage.'/COMPLETE.json', Files::identity($stage.'/publication.json'));
        foreach ($files as $name => $seal) {
            Files::verify(Package::safe($stage, $name), $seal);
        }
        Files::same(Files::identity($stage.'/publication.json'), Files::json($stage.'/COMPLETE.json'), 'prepared publication seal');
    }

    public function commit(string $stage, string $destination): void
    {
        $this->absent($destination);
        if (stat($stage)['dev'] !== stat(dirname($destination))['dev']) {
            throw new RuntimeException('Cross-filesystem publication forbidden.');
        }
        $this->rename($stage, $destination);
    }

    protected function rename(string $stage, string $destination): void
    {
        // GNU mv uses renameat2(RENAME_NOREPLACE); --no-copy forbids its EXDEV fallback.
        $process = new Process(['/usr/bin/mv', '--no-copy', '--update=none-fail', '--no-target-directory', '--', $stage, $destination]);
        $process->run();
        if ($process->getExitCode() !== 0 || is_dir($stage)) {
            throw new RuntimeException('Atomic no-replace publication failed: '.$process->getErrorOutput());
        }
    }

    public function failed(string $stage, Throwable $error): void
    {
        if (! is_dir($stage)) {
            return; // Never relabel or recreate a bundle after its atomic commit.
        }
        try {
            Jsonl::json($stage.'/FAILED.json', ['state' => 'FAILED', 'status' => 'FAILED_NOT_PUBLISHED',
                'exception' => $error::class, 'error' => $error->getMessage()]);
        } catch (Throwable) {
            // The original error and the incomplete stage remain the evidence.
        }
    }

    public function wasCommitted(string $stage, string $destination, ?array $completion): bool
    {
        if ($stage === '' || is_dir($stage) || $completion === null || ! is_dir($destination)) {
            return false;
        }
        try {
            return Files::identity($destination.'/COMPLETE.json') === $completion;
        } catch (Throwable) {
            return false;
        }
    }

    private function absent(string $path): void
    {
        clearstatcache(true, $path);
        if (file_exists($path) || is_link($path)) {
            throw new RuntimeException('Publication conflict: destination already exists.');
        }
    }
}
