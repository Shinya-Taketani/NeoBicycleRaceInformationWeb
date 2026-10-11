<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\Stat35Trend6P1;

use DateTimeImmutable;
use RuntimeException;

final class RetainedSpoolDirectory
{
    private readonly array $identity;

    private readonly string $creationRecord;

    public function __construct(private readonly string $path)
    {
        $this->identity = self::validate($path);
        $marker = $path.'/CREATED.json';
        $stat = self::regularFile($marker, $this->identity['dev']);
        $handle = fopen($marker, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Cannot read recorded spool directory identity.');
        }
        try {
            if (self::identity(fstat($handle)) !== self::identity($stat)) {
                throw new RuntimeException('Recorded spool marker identity changed.');
            }
            $record = stream_get_contents($handle, 8193);
        } finally {
            fclose($handle);
        }
        if ($record === false || strlen($record) > 8192) {
            throw new RuntimeException('Invalid spool directory creation record.');
        }
        $data = json_decode($record, true, flags: JSON_THROW_ON_ERROR);
        if (($data['source'] ?? null) !== $path || ($data['device'] ?? null) !== $this->identity['dev']
            || ($data['inode'] ?? null) !== $this->identity['ino'] || ($data['process_id'] ?? null) !== getmypid()) {
            throw new RuntimeException('Spool directory was not recorded by this process.');
        }
        $this->creationRecord = $record;
    }

    public static function recordCreation(string $path): void
    {
        $identity = self::validate($path);
        self::writeNew($path.'/CREATED.json', ['source' => $path, 'device' => $identity['dev'], 'inode' => $identity['ino'],
            'owner_uid' => posix_geteuid(), 'mode' => '0700', 'process_id' => getmypid(),
            'created_at' => (new DateTimeImmutable)->format(DATE_ATOM)], $identity['dev']);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function create(string $kind): array
    {
        if (! in_array($kind, ['input', 'sorted'], true)) {
            throw new RuntimeException('Unknown retained spool kind.');
        }
        $this->verifyDirectory();
        $path = $this->path.'/bt02-bin-'.$kind.'-'.bin2hex(random_bytes(16));
        $handle = self::exclusive($path, $this->identity['dev']);
        $identity = self::identity(fstat($handle));
        try {
            self::writeNew($path.'.CREATED.json', ['path' => $path, 'kind' => $kind, ...$identity,
                'owner_uid' => posix_geteuid(), 'mode' => '0600', 'process_id' => getmypid(),
                'directory_creation_sha256' => hash('sha256', $this->creationRecord),
                'created_at' => (new DateTimeImmutable)->format(DATE_ATOM)], $this->identity['dev']);
        } catch (\Throwable $error) {
            fclose($handle);
            throw $error;
        }

        return ['path' => $path, 'identity' => $identity, 'handle' => $handle];
    }

    public function verify(array $file): void
    {
        $this->verifyDirectory();
        if (dirname($file['path']) !== $this->path
            || self::identity(self::regularFile($file['path'], $this->identity['dev'])) !== $file['identity']) {
            throw new RuntimeException('Recorded spool path or identity changed.');
        }
        if (is_resource($file['handle']) && self::identity(fstat($file['handle'])) !== $file['identity']) {
            throw new RuntimeException('Recorded spool handle identity changed.');
        }
    }

    public function seal(array $file): void
    {
        $this->verify($file);
        self::writeNew($file['path'].'.SEALED.json', ['path' => $file['path'], ...$file['identity'],
            'bytes' => filesize($file['path']), 'sha256' => hash_file('sha256', $file['path'])], $this->identity['dev']);
    }

    private function verifyDirectory(): void
    {
        if (self::validate($this->path) !== $this->identity) {
            throw new RuntimeException('Recorded spool directory changed.');
        }
        self::regularFile($this->path.'/CREATED.json', $this->identity['dev']);
        if (file_get_contents($this->path.'/CREATED.json') !== $this->creationRecord) {
            throw new RuntimeException('Spool directory creation record changed.');
        }
    }

    private static function validate(string $path): array
    {
        if ($path === '' || ! str_starts_with($path, '/') || str_contains($path, "\0") || str_contains($path, '\\')
            || preg_match('~(?:^|/)(?:\.|\.\.)(?:/|$)|//|/$~', $path) === 1) {
            throw new RuntimeException('Canonical absolute spool directory required.');
        }
        $repository = dirname(__DIR__, 6);
        if (in_array($path, ['/', '/tmp', '/home', '/home/shinya', '/home/shinya/Desktop'], true)
            || $path === $repository || str_starts_with($repository, $path.'/') || str_starts_with($path, $repository.'/')
            || $path === '/home/shinya/Desktop/Trash' || str_starts_with($path, '/home/shinya/Desktop/Trash/')) {
            throw new RuntimeException('Protected path cannot be a spool directory.');
        }
        $prefix = '';
        foreach (explode('/', substr($path, 1)) as $part) {
            $prefix .= '/'.$part;
            if ((self::stat($prefix)['mode'] & 0170000) !== 0040000) {
                throw new RuntimeException('Spool directory contains a link or nondirectory.');
            }
        }
        $stat = self::stat($path);
        if (realpath($path) !== $path || $stat['uid'] !== posix_geteuid() || ($stat['mode'] & 07777) !== 0700
            || ! is_writable($path) || $stat['dev'] !== self::stat(dirname($path))['dev']) {
            throw new RuntimeException('Spool directory must be owned, private, writable and on its parent filesystem.');
        }
        $mounts = file('/proc/self/mountinfo', FILE_IGNORE_NEW_LINES);
        if ($mounts === false) {
            throw new RuntimeException('Cannot verify spool mount boundaries.');
        }
        self::assertNoMounts($path, $mounts);

        return self::identity($stat);
    }

    private static function assertNoMounts(string $path, array $mounts): void
    {
        foreach ($mounts as $line) {
            $fields = explode(' ', $line);
            if (count($fields) < 6) {
                throw new RuntimeException('Invalid spool mount metadata.');
            }
            $mount = strtr($fields[4], ['\\040' => ' ', '\\011' => "\t", '\\012' => "\n", '\\134' => '\\']);
            if ($mount === $path || str_starts_with($mount, $path.'/')) {
                throw new RuntimeException('Mount inside spool directory forbidden.');
            }
        }
    }

    private static function stat(string $path): array
    {
        clearstatcache(true, $path);
        if (! file_exists($path) && ! is_link($path)) {
            throw new RuntimeException('Spool path does not exist.');
        }
        $stat = lstat($path);
        if ($stat === false) {
            throw new RuntimeException('Spool metadata unavailable.');
        }

        return $stat;
    }

    private static function regularFile(string $path, int $device): array
    {
        $stat = self::stat($path);
        if (($stat['mode'] & 0170000) !== 0100000 || $stat['uid'] !== posix_geteuid()
            || $stat['nlink'] !== 1 || $stat['dev'] !== $device || ($stat['mode'] & 07777) !== 0600) {
            throw new RuntimeException('Private owned regular spool file required; links forbidden.');
        }

        return $stat;
    }

    private static function identity(array|false $stat): array
    {
        if ($stat === false) {
            throw new RuntimeException('Spool identity unavailable.');
        }

        return ['dev' => $stat['dev'], 'ino' => $stat['ino']];
    }

    private static function exclusive(string $path, int $device)
    {
        if (file_exists($path) || is_link($path)) {
            throw new RuntimeException('Retained spool path already exists.');
        }
        $mask = umask(0077);
        try {
            $handle = fopen($path, 'x+b');
        } finally {
            umask($mask);
        }
        if ($handle === false) {
            throw new RuntimeException('Could not exclusively create retained spool; no fallback.');
        }
        try {
            $stat = self::regularFile($path, $device);
            if (self::identity(fstat($handle)) !== self::identity($stat)) {
                throw new RuntimeException('Exclusive spool identity changed.');
            }
        } catch (\Throwable $error) {
            fclose($handle);
            throw $error;
        }

        return $handle;
    }

    private static function writeNew(string $path, array $record, int $device): void
    {
        $handle = self::exclusive($path, $device);
        try {
            $bytes = json_encode($record, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
            if (fwrite($handle, $bytes) !== strlen($bytes) || ! fflush($handle)) {
                throw new RuntimeException('Could not persist retained spool evidence.');
            }
        } finally {
            fclose($handle);
        }
    }
}
