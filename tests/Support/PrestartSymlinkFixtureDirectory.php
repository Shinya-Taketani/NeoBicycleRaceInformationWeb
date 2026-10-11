<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Keirin\Scraping\PrestartEvidence\EvidenceFiles;
use DateTimeImmutable;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/** Only flat, recorded artificial directory links; never a general-purpose cleanup helper. */
class PrestartSymlinkFixtureDirectory
{
    private readonly array $identity;

    private readonly string $creationRecord;

    private array $links = [];

    private bool $retired = false;

    protected function __construct(private readonly CompositionResultTemporaryDirectory $directory)
    {
        $this->identity = EvidenceFiles::directory($this->path());
        $this->creationRecord = EvidenceFiles::read($this->path(), 'CREATED.json');
    }

    public static function create(): static
    {
        return new static(CompositionResultTemporaryDirectory::create());
    }

    public function path(): string
    {
        return $this->directory->path();
    }

    public function retired(): bool
    {
        return $this->retired;
    }

    public function createLink(string $name, CompositionResultTemporaryDirectory $target): void
    {
        $this->directory->assertRecordedPath($this->path());
        if ($this->retired) {
            throw new RuntimeException('Fixture already retired.');
        }
        $this->verifySource($this->path());
        if (preg_match('/^[a-f0-9]{32}$/D', $name) !== 1) {
            throw new RuntimeException('Only a synthetic capture-ID link name is allowed.');
        }
        $target->assertRecordedPath($target->path());
        $targetIdentity = EvidenceFiles::directory($target->path());
        // Read only its own creation metadata before creating any link, never through a symlink.
        $targetCreation = EvidenceFiles::object(EvidenceFiles::read($target->path(), 'CREATED.json'));
        if (($targetCreation['source'] ?? null) !== $target->path() || ($targetCreation['process_id'] ?? null) !== getmypid()
            || ($targetCreation['device'] ?? null) !== $targetIdentity['dev'] || ($targetCreation['inode'] ?? null) !== $targetIdentity['ino']) {
            throw new RuntimeException('Artificial target does not match its creation record.');
        }
        if ($target->path() === $this->path() || $targetIdentity['dev'] !== $this->identity['dev']) {
            throw new RuntimeException('Only a separate owned artificial directory on the same device is allowed.');
        }
        $path = $this->path().'/'.$name;
        if (EvidenceFiles::stat($path) !== null || ! symlink($target->path(), $path)) {
            throw new RuntimeException('Exclusive synthetic link creation failed.');
        }
        $this->links[$name] = ['identity' => $this->linkIdentity($path), 'target' => $target,
            'target_identity' => $targetIdentity, 'target_path' => $target->path()];
        $this->verifySource($this->path());
    }

    public function retire(string $path, string $reason): array
    {
        $this->directory->assertRecordedPath($path);
        if ($this->retired || ! in_array($reason, ['TEST_COMPLETE', 'TEST_FAILURE'], true)) {
            throw new RuntimeException('Fixture already retired or cleanup reason unknown.');
        }
        $this->verifySource($path);
        $trash = EvidenceFiles::directory(CompositionResultTemporaryDirectory::TRASH);
        if ($trash['dev'] !== $this->identity['dev']) {
            throw new RuntimeException('Cross-device fixture retirement forbidden; source retained.');
        }
        $container = CompositionResultTemporaryDirectory::TRASH.'/prestart-symlink-'.bin2hex(random_bytes(16));
        if (EvidenceFiles::stat($container) !== null || ! mkdir($container, 0700)) {
            throw new RuntimeException('Exclusive fixture Trash destination unavailable.');
        }
        $containerIdentity = EvidenceFiles::directory($container);
        $destination = $container.'/directory';
        $record = ['source' => $path, 'destination' => $destination, 'attempted_at' => (new DateTimeImmutable)->format(DATE_ATOM),
            'reason' => $reason, 'device' => $this->identity['dev'], 'inode' => $this->identity['ino'],
            'creation_record_sha256' => hash('sha256', $this->creationRecord), 'links' => array_keys($this->links)];
        EvidenceFiles::writeNew($container, 'ATTEMPT.json', EvidenceFiles::json($record));
        try {
            $this->verifySource($path);
            if (EvidenceFiles::directory(CompositionResultTemporaryDirectory::TRASH) !== $trash
                || EvidenceFiles::directory($container) !== $containerIdentity
                || $containerIdentity['dev'] !== $this->identity['dev'] || EvidenceFiles::stat($destination) !== null) {
                throw new RuntimeException('Fixture destination changed; source retained.');
            }
            $this->renameOnly($path, $destination);
            if (EvidenceFiles::stat($path) !== null) {
                throw new RuntimeException('Fixture source still exists after retirement.');
            }
            $this->verifySource($destination);
            $this->retired = true;
        } catch (Throwable $error) {
            EvidenceFiles::writeNew($container, 'FAILED.json', EvidenceFiles::json([
                ...$record, 'failed_at' => (new DateTimeImmutable)->format(DATE_ATOM), 'state' => 'FAILED', 'exception' => $error::class,
            ]));
            throw $error;
        }
        $record['moved_at'] = (new DateTimeImmutable)->format(DATE_ATOM);
        $record['state'] = 'MOVED';
        EvidenceFiles::writeNew($container, 'MOVED.json', EvidenceFiles::json($record));

        return $record;
    }

    protected function renameOnly(string $source, string $destination): void
    {
        $process = new Process(['/usr/bin/mv', '--no-copy', '--update=none-fail', '--no-target-directory', '--', $source, $destination]);
        $process->setTimeout(10);
        $process->run();
        if ($process->getExitCode() !== 0) {
            throw new RuntimeException('Fixture rename failed; no copy/delete fallback.');
        }
    }

    protected function mountPoints(): array
    {
        $lines = file('/proc/self/mountinfo', FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new RuntimeException('Cannot verify fixture mount boundaries.');
        }

        return array_map(static function (string $line): string {
            $fields = explode(' ', $line);
            if (count($fields) < 6) {
                throw new RuntimeException('Invalid fixture mount metadata.');
            }

            return strtr($fields[4], ['\\040' => ' ', '\\011' => "\t", '\\012' => "\n", '\\134' => '\\']);
        }, $lines);
    }

    private function verifySource(string $path): void
    {
        if (EvidenceFiles::directory($path) !== $this->identity) {
            throw new RuntimeException('Recorded fixture directory identity changed.');
        }
        foreach ($this->mountPoints() as $mount) {
            if ($mount === $path || str_starts_with($mount, $path.'/')) {
                throw new RuntimeException('Fixture mount boundary forbidden.');
            }
        }
        $names = array_values(array_diff(scandir($path) ?: throw new RuntimeException('Cannot inspect fixture.'), ['.', '..']));
        $expected = ['CREATED.json', ...array_keys($this->links)];
        sort($names);
        sort($expected);
        if ($names !== $expected || EvidenceFiles::read($path, 'CREATED.json') !== $this->creationRecord) {
            throw new RuntimeException('Unrecorded fixture contents or creation marker changed.');
        }
        foreach ($this->links as $name => $link) {
            // lstat/readlink inspect the link itself; no contents or descendants of its target are opened.
            if ($this->linkIdentity($path.'/'.$name) !== $link['identity'] || readlink($path.'/'.$name) !== $link['target_path']) {
                throw new RuntimeException('Unrecorded or replaced fixture link.');
            }
            $link['target']->assertRecordedPath($link['target_path']);
            if (EvidenceFiles::directory($link['target_path']) !== $link['target_identity']) {
                throw new RuntimeException('Owned artificial link target changed.');
            }
        }
    }

    private function linkIdentity(string $path): array
    {
        $stat = EvidenceFiles::stat($path);
        if ($stat === null || ($stat['mode'] & 0170000) !== 0120000 || $stat['dev'] !== $this->identity['dev']
            || $stat['uid'] !== posix_geteuid() || $stat['nlink'] !== 1) {
            throw new RuntimeException('Recorded artificial symlink required.');
        }

        return ['dev' => $stat['dev'], 'ino' => $stat['ino']];
    }
}
