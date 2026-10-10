<?php

declare(strict_types=1);

namespace Tests\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class CompositionResultTemporaryDirectory
{
    public const TRASH = '/home/shinya/Desktop/Trash';

    private const PARENTS = ['/home/shinya/Desktop', '/tmp'];

    private const REASONS = ['TEST_COMPLETE', 'TEST_FAILURE', 'PROCESS_COMPLETE', 'PROCESS_FAILURE'];

    private bool $retired = false;

    private function __construct(
        private readonly string $path,
        private readonly array $identity,
        private readonly string $creationRecord,
        private readonly int $processId,
    ) {}

    public static function create(string $parent = '/home/shinya/Desktop'): static
    {
        self::directory($parent);
        if (! in_array($parent, self::PARENTS, true)) {
            throw new RuntimeException('Unapproved temporary-directory parent.');
        }
        $path = $parent.'/composition-result-temporary-'.bin2hex(random_bytes(16));
        if (! mkdir($path, 0700)) {
            throw new RuntimeException('Could not create a dedicated temporary directory.');
        }
        $identity = self::identity($path);
        $record = self::json([
            'source' => $path,
            'created_at' => self::now(),
            'process_id' => getmypid(),
            'device' => $identity['dev'],
            'inode' => $identity['ino'],
            'token' => bin2hex(random_bytes(32)),
        ]);
        self::writeNew($path.'/CREATED.json', $record);

        return new static($path, $identity, $record, getmypid());
    }

    public function path(): string
    {
        return $this->path;
    }

    public function assertRecordedPath(string $path): void
    {
        self::absolute($path);
        self::protectedPath($path);
        if ($this->retired || getmypid() !== $this->processId || $path !== $this->path) {
            throw new RuntimeException('Only this process\'s recorded temporary directory may be retired.');
        }
    }

    public function retire(string $path, string $reason): array
    {
        $this->assertRecordedPath($path);
        if (! in_array($reason, self::REASONS, true)) {
            throw new RuntimeException('Unknown cleanup reason.');
        }
        $this->verifySource();
        $trash = self::directory(self::TRASH);
        if ($trash['dev'] !== $this->identity['dev']) {
            throw new RuntimeException('Cross-filesystem retirement forbidden; source retained.');
        }

        $container = self::TRASH.'/composition-result-'.bin2hex(random_bytes(16));
        if (! mkdir($container, 0700)) {
            throw new RuntimeException('Could not create an exclusive Trash destination; source retained.');
        }
        $destination = $container.'/directory';
        $record = [
            'source' => $this->path,
            'destination' => $destination,
            'attempted_at' => self::now(),
            'reason' => $reason,
            'creation_record_sha256' => hash('sha256', $this->creationRecord),
        ];
        self::writeNew($container.'/ATTEMPT.json', self::json($record));
        try {
            // Recheck ownership and destination just before the rename-only operation.
            $this->verifySource();
            if (self::directory(self::TRASH) !== $trash || self::directory($container)['dev'] !== $trash['dev']) {
                throw new RuntimeException('Trash changed; source retained.');
            }
            $this->renameOnly($this->path, $destination);
            if (file_exists($this->path) || self::directory($destination) !== $this->identity) {
                throw new RuntimeException('Retirement identity verification failed.');
            }
        } catch (Throwable $error) {
            self::writeNew($container.'/FAILED.json', self::json([
                ...$record, 'failed_at' => self::now(), 'state' => 'FAILED', 'exception' => $error::class,
            ]));
            throw $error;
        }
        $this->retired = true;
        $record['moved_at'] = self::now();
        $record['state'] = 'MOVED';
        self::writeNew($container.'/MOVED.json', self::json($record));

        return $record;
    }

    protected function renameOnly(string $source, string $destination): void
    {
        // Unlike PHP rename(), GNU mv --no-copy cannot fall back to copy/unlink on EXDEV.
        $move = new Process(['/usr/bin/mv', '--no-copy', '--update=none-fail', '--no-target-directory', '--', $source, $destination]);
        $move->setTimeout(10);
        $move->run();
        if ($move->getExitCode() !== 0) {
            throw new RuntimeException('Rename-only retirement failed; no delete/copy fallback.');
        }
    }

    protected function mountPoints(): array
    {
        $lines = file('/proc/self/mountinfo', FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new RuntimeException('Cannot verify mount boundaries.');
        }
        $mounts = [];
        foreach ($lines as $line) {
            $fields = explode(' ', $line);
            if (count($fields) < 6) {
                throw new RuntimeException('Invalid mount boundary metadata.');
            }
            $mounts[] = strtr($fields[4], ['\\040' => ' ', '\\011' => "\t", '\\012' => "\n", '\\134' => '\\']);
        }

        return $mounts;
    }

    private function verifySource(): void
    {
        if (self::directory($this->path) !== $this->identity) {
            throw new RuntimeException('Recorded temporary directory was replaced.');
        }
        foreach ($this->mountPoints() as $mount) {
            if ($mount === $this->path || str_starts_with($mount, $this->path.'/')) {
                throw new RuntimeException('Mount inside temporary directory forbidden.');
            }
        }
        $pending = [$this->path];
        while ($pending !== []) {
            $directory = array_pop($pending);
            if (self::directory($directory)['dev'] !== $this->identity['dev']) {
                throw new RuntimeException('Directory boundary changed.');
            }
            foreach (scandir($directory) ?: throw new RuntimeException('Cannot inspect temporary directory.') as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $child = $directory.'/'.$name;
                $stat = self::stat($child);
                $kind = $stat['mode'] & 0170000;
                if ($stat['dev'] !== $this->identity['dev'] || ! in_array($kind, [0040000, 0100000], true)
                    || ($kind === 0100000 && $stat['nlink'] !== 1)) {
                    throw new RuntimeException('Link, mount or special file in temporary directory forbidden.');
                }
                if ($kind === 0040000) {
                    $pending[] = $child;
                }
            }
        }
        $marker = $this->path.'/CREATED.json';
        if (! is_file($marker) || file_get_contents($marker) !== $this->creationRecord) {
            throw new RuntimeException('Temporary-directory creation record does not match.');
        }
    }

    private static function protectedPath(string $path): void
    {
        $repository = dirname(__DIR__, 2);
        if ($path === '/home/shinya' || $path === self::TRASH || str_starts_with($path, self::TRASH.'/')
            || $path === $repository || str_starts_with($repository, $path.'/') || str_starts_with($path, $repository.'/')) {
            throw new RuntimeException('Protected directory cannot be retired.');
        }
    }

    private static function absolute(string $path): void
    {
        if ($path === '' || ! str_starts_with($path, '/') || str_contains($path, "\0") || str_contains($path, '\\')
            || preg_match('~(?:^|/)(?:\.|\.\.)(?:/|$)|//|/$~', $path) === 1) {
            throw new RuntimeException('Canonical absolute directory path required.');
        }
    }

    private static function directory(string $path): array
    {
        self::absolute($path);
        $prefix = '';
        foreach (explode('/', substr($path, 1)) as $component) {
            $prefix .= '/'.$component;
            if ((self::stat($prefix)['mode'] & 0170000) !== 0040000) {
                throw new RuntimeException('Directory path contains a link or nondirectory.');
            }
        }
        if (realpath($path) !== $path) {
            throw new RuntimeException('Directory cannot be resolved without substitution.');
        }

        return self::identity($path);
    }

    private static function stat(string $path): array
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false) {
            throw new RuntimeException('Directory metadata unavailable.');
        }

        return $stat;
    }

    private static function identity(string $path): array
    {
        $stat = self::stat($path);

        return ['dev' => $stat['dev'], 'ino' => $stat['ino']];
    }

    private static function writeNew(string $path, string $bytes): void
    {
        $handle = @fopen($path, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Could not write exclusive cleanup evidence.');
        }
        try {
            if (! chmod($path, 0600) || fwrite($handle, $bytes) !== strlen($bytes) || ! fflush($handle)) {
                throw new RuntimeException('Could not persist cleanup evidence.');
            }
        } finally {
            fclose($handle);
        }
    }

    private static function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    }

    private static function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format(DateTimeInterface::ATOM);
    }
}
