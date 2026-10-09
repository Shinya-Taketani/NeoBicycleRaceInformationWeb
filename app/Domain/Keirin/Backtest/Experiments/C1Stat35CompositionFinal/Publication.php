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
        $proof = ['version' => $kind === 'REPACKAGE' ? Contract::REPACKAGE_PUBLICATION_VERSION : Contract::PUBLICATION_VERSION,
            'state' => $kind === 'REPACKAGE' ? 'PREPARED' : 'COMMITTED',
            'kind' => $kind, 'destination' => $destination, 'packages' => $packages, 'files' => $files, 'evidence' => $evidence];
        if ($kind === 'REPACKAGE') {
            $proof['publication_id'] = $evidence['publication_id'];
        }
        $this->json($stage.'/publication.json', $proof);
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

    /** Capture the identity of this invocation's stage before the no-replace move. */
    public function repackageAttempt(string $stage, string $destination): array
    {
        $proof = Files::json(Package::safe($stage, 'publication.json'));
        if (! str_contains($stage, '.inprogress-') || ($proof['destination'] ?? null) !== $destination
            || file_exists($stage.'/RELEASE_COMMITTED.json') || is_link($stage.'/RELEASE_COMMITTED.json')) {
            throw new RuntimeException('Invalid prepared repackage attempt.');
        }
        self::verifyPreparedRepackage($stage, $proof);
        $stat = lstat($stage);

        return ['publication_id' => $proof['publication_id'], 'destination' => $destination,
            'device' => $stat['dev'], 'inode' => $stat['ino'],
            'artifact' => Files::identity($stage.'/artifact.json'), 'prepared' => Files::identity($stage.'/publication.json')];
    }

    public function ownsRepackage(string $destination, ?array $attempt): bool
    {
        clearstatcache();
        if ($attempt === null || $destination !== $attempt['destination'] || is_link($destination) || ! is_dir($destination)) {
            return false;
        }
        try {
            $stat = lstat($destination);

            return $stat['dev'] === $attempt['device'] && $stat['ino'] === $attempt['inode']
                && Files::identity(Package::safe($destination, 'artifact.json')) === $attempt['artifact']
                && Files::identity(Package::safe($destination, 'publication.json')) === $attempt['prepared'];
        } catch (Throwable) {
            return false;
        }
    }

    /** Only Repackage calls this, while holding its destination lock, after placement. */
    public function releaseRepackage(string $destination, array $attempt): void
    {
        if (! $this->ownsRepackage($destination, $attempt)) {
            throw new RuntimeException('Placed repackage does not belong to this attempt.');
        }
        $proof = Files::json($destination.'/publication.json');
        self::verifyPreparedRepackage($destination, $proof);
        if ($proof['publication_id'] !== $attempt['publication_id'] || $proof['destination'] !== $destination) {
            throw new RuntimeException('Repackage placement identity mismatch.');
        }
        $receipt = self::receipt($proof, $attempt['artifact'], $attempt['prepared'],
            ['destination' => $destination, 'device' => $attempt['device'], 'inode' => $attempt['inode']], gmdate('Y-m-d\TH:i:s\Z'));
        $temporary = $destination.'/.release-'.$attempt['publication_id'].'.json';
        $this->writeReceipt($temporary, $receipt);
        Files::same($receipt, Files::json(Package::safe($destination, basename($temporary))), 'complete release receipt');
        if (! $this->ownsRepackage($destination, $attempt)) {
            throw new RuntimeException('Repackage ownership drift before receipt publication.');
        }
        $this->publishReceipt($temporary, $destination.'/RELEASE_COMMITTED.json');
        self::verifyRepackageReceipt($destination);
    }

    protected function writeReceipt(string $temporary, array $receipt): void
    {
        // Jsonl uses exclusive partial creation, full write, fflush and fsync.
        Jsonl::json($temporary, $receipt);
    }

    protected function publishReceipt(string $temporary, string $destination): void
    {
        $this->absent($destination);
        $this->rename($temporary, $destination);
    }

    public function wasRepackageCommitted(string $stage, string $destination, ?array $attempt): bool
    {
        if (is_dir($stage) || ! $this->ownsRepackage($destination, $attempt)) {
            return false;
        }
        try {
            self::verifyRepackageReceipt($destination);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public static function verifyRepackageReceipt(string $root): void
    {
        $proof = Files::json(Package::safe($root, 'publication.json'));
        self::verifyPreparedRepackage($root, $proof);
        $receipt = Files::json(Package::safe($root, 'RELEASE_COMMITTED.json'));
        $placement = $receipt['placement'] ?? null;
        $time = $receipt['committed_at'] ?? null;
        if (! is_array($placement) || array_keys($placement) !== ['destination', 'device', 'inode']
            || $placement['destination'] !== $proof['destination'] || ! is_int($placement['device']) || $placement['device'] < 0
            || ! is_int($placement['inode']) || $placement['inode'] <= 0
            || ! is_string($time) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $time)) {
            throw new RuntimeException('Invalid repackage placement receipt.');
        }
        Files::same(self::receipt($proof, Files::identity($root.'/artifact.json'), Files::identity($root.'/publication.json'), $placement, $time),
            $receipt, 'committed repackage receipt');
    }

    private static function verifyPreparedRepackage(string $root, array $proof): void
    {
        $artifact = Files::json(Package::safe($root, 'artifact.json'));
        $id = $proof['publication_id'] ?? null;
        if (($proof['version'] ?? null) !== Contract::REPACKAGE_PUBLICATION_VERSION || ($proof['state'] ?? null) !== 'PREPARED'
            || ($proof['kind'] ?? null) !== 'REPACKAGE' || ! is_string($id) || ! preg_match('/^[a-f0-9]{48}$/D', $id)
            || ($artifact['publication_version'] ?? null) !== Contract::REPACKAGE_PUBLICATION_VERSION
            || ($artifact['publication_id'] ?? null) !== $id || ($proof['evidence']['publication_id'] ?? null) !== $id
            || ($proof['packages'] ?? null) !== ['artifact.json'] || ! is_array($proof['files'] ?? null)
            || ($proof['files']['artifact.json'] ?? null) !== Files::identity($root.'/artifact.json')
            || ! is_string($proof['destination'] ?? null) || ! is_array($proof['evidence']['runtime_code'] ?? null)) {
            throw new RuntimeException('Invalid prepared repackage proof.');
        }
        foreach ($proof['files'] as $name => $seal) {
            Files::verify(Package::safe($root, $name), $seal);
        }
        Files::same(Files::identity($root.'/publication.json'), Files::json(Package::safe($root, 'COMPLETE.json')), 'prepared content seal');
    }

    private static function receipt(array $proof, array $artifact, array $prepared, array $placement, string $time): array
    {
        return ['version' => Contract::REPACKAGE_PUBLICATION_VERSION, 'state' => 'COMMITTED',
            'publication_id' => $proof['publication_id'], 'artifact' => $artifact, 'prepared_publication' => $prepared,
            'runtime_code_sha256' => hash('sha256', Files::canonical($proof['evidence']['runtime_code'])),
            'placement' => $placement, 'committed_after_placement' => true, 'committed_at' => $time];
    }

    private function absent(string $path): void
    {
        clearstatcache(true, $path);
        if (file_exists($path) || is_link($path)) {
            throw new RuntimeException('Publication conflict: destination already exists.');
        }
    }
}
