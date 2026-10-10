<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\CompositionResultTemporaryDirectory as Directory;

final class CompositionResultTemporaryDirectoryTest extends TestCase
{
    /** @var list<Directory> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        try {
            foreach ($this->cleanup as $directory) {
                $directory->retire($directory->path(), 'TEST_COMPLETE');
            }
        } finally {
            parent::tearDown();
        }
    }

    public static function invalidPaths(): array
    {
        return [[''], ['.'], ['..'], ['relative/path'], ['/tmp/../tmp'], ['/tmp//directory'],
            ['/tmp/'], ["/tmp/invalid\0path"], ['/home/shinya'], [Directory::TRASH],
            [Directory::TRASH.'/existing'], ['/tmp/composition-result-not-created-'.str_repeat('f', 32)]];
    }

    #[DataProvider('invalidPaths')]
    public function test_invalid_unrecorded_and_protected_paths_are_rejected_before_any_move(string $path): void
    {
        $directory = $this->owned();
        $before = $this->snapshot($directory->path());
        $trash = scandir(Directory::TRASH);

        $this->reject(fn () => $directory->retire($path, 'TEST_COMPLETE'));

        $this->assertSame($before, $this->snapshot($directory->path()));
        $this->assertSame($trash, scandir(Directory::TRASH));
    }

    public function test_original_request_id_parent_expression_is_rejected_without_touching_synthetic_repository(): void
    {
        $directory = $this->owned();
        $repository = $directory->path().'/synthetic-repository';
        mkdir($repository, 0700);
        file_put_contents($repository.'/sentinel.txt', 'artificial repository sentinel');
        $before = $this->snapshot($directory->path());
        $trash = scandir(Directory::TRASH);

        $path = dirname(dirname('dev-composition-2025-r37750-validation-fix-01'));
        $this->assertSame('.', $path);
        $this->reject(fn () => $directory->retire($path, 'TEST_COMPLETE'));
        $this->reject(fn () => $directory->retire($repository, 'TEST_COMPLETE'));

        $this->assertSame($before, $this->snapshot($directory->path()));
        $this->assertSame($trash, scandir(Directory::TRASH));
    }

    public function test_protected_repository_paths_are_rejected_by_validation_without_calling_move(): void
    {
        $directory = $this->owned();
        $repository = dirname(__DIR__, 2);
        $before = $this->snapshot($directory->path());
        $trash = scandir(Directory::TRASH);
        foreach ([$repository, dirname($repository), dirname(dirname($repository)),
            '/home/shinya', Directory::TRASH] as $path) {
            // Validation only: never pass real repository/data paths to a move operation.
            try {
                $directory->assertRecordedPath($path);
                $this->fail('Protected path accepted.');
            } catch (RuntimeException $error) {
                $this->assertSame('Protected directory cannot be retired.', $error->getMessage());
            }
        }
        $this->assertSame($before, $this->snapshot($directory->path()));
        $this->assertSame($trash, scandir(Directory::TRASH));
    }

    public function test_only_the_directory_created_and_recorded_by_this_owner_is_moved(): void
    {
        $directory = Directory::create();
        $other = $this->owned();
        $source = $directory->path();
        mkdir($source.'/nested', 0700);
        file_put_contents($source.'/nested/input.txt', 'synthetic credential content, never logged');
        $before = $this->snapshot($source);
        $otherBefore = $this->snapshot($other->path());

        $this->reject(fn () => $directory->retire($other->path(), 'TEST_COMPLETE'));
        $record = $directory->retire($source, 'TEST_COMPLETE');

        $this->assertDirectoryDoesNotExist($source);
        $this->assertStringStartsWith(Directory::TRASH.'/composition-result-', $record['destination']);
        $this->assertSame($before, $this->snapshot($record['destination']));
        $this->assertSame($otherBefore, $this->snapshot($other->path()));
        $this->assertSame($source, $record['source']);
        $this->assertSame('TEST_COMPLETE', $record['reason']);
        $this->assertSame('MOVED', $record['state']);
        $this->assertNotEmpty($record['attempted_at']);
        $this->assertNotEmpty($record['moved_at']);
        $container = dirname($record['destination']);
        $this->assertSame(0700, fileperms($container) & 0777);
        $this->assertSame(0600, fileperms($container.'/MOVED.json') & 0777);
        $this->assertSame($record, json_decode(file_get_contents($container.'/MOVED.json'), true, flags: JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('synthetic credential content', file_get_contents($container.'/MOVED.json'));
        $this->assertFileExists($container.'/ATTEMPT.json');

        $trash = scandir(Directory::TRASH);
        $this->reject(fn () => $directory->retire($source, 'TEST_COMPLETE'));
        $this->assertSame($trash, scandir(Directory::TRASH));
    }

    public function test_destinations_are_unique_and_previous_trash_records_are_unchanged(): void
    {
        $first = Directory::create();
        $second = Directory::create();
        $one = $first->retire($first->path(), 'PROCESS_COMPLETE');
        $before = $this->snapshot(dirname($one['destination']));
        $two = $second->retire($second->path(), 'PROCESS_COMPLETE');

        $this->assertNotSame($one['destination'], $two['destination']);
        $this->assertSame($before, $this->snapshot(dirname($one['destination'])));
    }

    public function test_arbitrary_reason_cannot_leak_into_cleanup_evidence(): void
    {
        $directory = $this->owned();
        $before = $this->snapshot($directory->path());
        $trash = scandir(Directory::TRASH);
        $this->reject(fn () => $directory->retire($directory->path(), 'unapproved free-form content'));
        $this->assertSame($before, $this->snapshot($directory->path()));
        $this->assertSame($trash, scandir(Directory::TRASH));
    }

    public static function linkKinds(): array
    {
        return [['file'], ['directory'], ['broken']];
    }

    #[DataProvider('linkKinds')]
    public function test_symlinks_are_not_followed_or_moved(string $kind): void
    {
        // Unsafe evidence is deliberately retained, never unlinked to make cleanup pass.
        $directory = Directory::create();
        $other = $this->owned();
        file_put_contents($other->path().'/sentinel.txt', 'synthetic link target');
        $target = match ($kind) {
            'file' => $other->path().'/sentinel.txt',
            'directory' => $other->path(),
            'broken' => $other->path().'/absent',
        };
        symlink($target, $directory->path().'/link');
        $sourceBefore = lstat($directory->path());
        $otherBefore = $this->snapshot($other->path());
        $trash = scandir(Directory::TRASH);

        $this->reject(fn () => $directory->retire($directory->path(), 'TEST_COMPLETE'));

        $this->assertSame($sourceBefore['ino'], lstat($directory->path())['ino']);
        $this->assertTrue(is_link($directory->path().'/link'));
        $this->assertSame($target, readlink($directory->path().'/link'));
        $this->assertSame($otherBefore, $this->snapshot($other->path()));
        $this->assertSame($trash, scandir(Directory::TRASH));
        $this->reject(fn () => Directory::create($directory->path().'/link'));
    }

    public function test_hard_link_is_rejected_without_touching_its_artificial_target(): void
    {
        $directory = Directory::create();
        $other = Directory::create();
        file_put_contents($other->path().'/sentinel.txt', 'artificial hard-link target');
        link($other->path().'/sentinel.txt', $directory->path().'/linked.txt');
        $trash = scandir(Directory::TRASH);

        $this->reject(fn () => $directory->retire($directory->path(), 'TEST_COMPLETE'));
        $this->assertSame('artificial hard-link target', file_get_contents($other->path().'/sentinel.txt'));
        $this->assertSame(2, lstat($other->path().'/sentinel.txt')['nlink']);
        $this->assertSame($trash, scandir(Directory::TRASH));
    }

    public function test_mount_boundary_is_rejected_even_on_the_same_device(): void
    {
        $directory = SyntheticMountTemporaryDirectory::create();
        mkdir($directory->path().'/synthetic-mount', 0700);
        file_put_contents($directory->path().'/synthetic-mount/sentinel.txt', 'not a real mount');
        $directory->mount = $directory->path().'/synthetic-mount';
        $before = $this->snapshot($directory->path());
        $trash = scandir(Directory::TRASH);

        $this->reject(fn () => $directory->retire($directory->path(), 'TEST_COMPLETE'));
        $this->assertSame($before, $this->snapshot($directory->path()));
        $this->assertSame($trash, scandir(Directory::TRASH));
        $directory->mount = null;
        $this->cleanup[] = $directory;
    }

    public function test_cross_filesystem_source_is_retained_without_copy_or_trash_creation(): void
    {
        $directory = Directory::create('/tmp');
        $this->assertNotSame(stat($directory->path())['dev'], stat(Directory::TRASH)['dev'], 'This test requires the actual /tmp and Trash filesystem boundary.');
        file_put_contents($directory->path().'/sentinel.txt', 'artificial cross-filesystem source');
        $before = $this->snapshot($directory->path());
        $trash = scandir(Directory::TRASH);

        $this->reject(fn () => $directory->retire($directory->path(), 'TEST_COMPLETE'));
        $this->assertSame($before, $this->snapshot($directory->path()));
        $this->assertSame($trash, scandir(Directory::TRASH));
    }

    public function test_creation_record_tampering_is_rejected_without_moving_or_deleting(): void
    {
        $directory = Directory::create();
        file_put_contents($directory->path().'/CREATED.json', '{}');
        $trash = scandir(Directory::TRASH);
        $this->reject(fn () => $directory->retire($directory->path(), 'TEST_COMPLETE'));
        $this->assertSame('{}', file_get_contents($directory->path().'/CREATED.json'));
        $this->assertSame($trash, scandir(Directory::TRASH));
    }

    public function test_move_failure_preserves_source_and_failure_evidence(): void
    {
        $directory = FailingMoveTemporaryDirectory::create();
        $before = $this->snapshot($directory->path());
        $this->reject(fn () => $directory->retire($directory->path(), 'TEST_FAILURE'));
        $this->assertSame($before, $this->snapshot($directory->path()));
        $container = dirname($directory->attemptedDestination);
        $this->assertFileExists($container.'/ATTEMPT.json');
        $this->assertFileExists($container.'/FAILED.json');
        $this->assertFileDoesNotExist($container.'/MOVED.json');
        $this->assertDirectoryDoesNotExist($directory->attemptedDestination);
    }

    public function test_destination_collision_is_not_overwritten_and_source_is_retained(): void
    {
        $directory = CollisionTemporaryDirectory::create();
        $before = $this->snapshot($directory->path());
        $this->reject(fn () => $directory->retire($directory->path(), 'TEST_FAILURE'));
        $this->assertSame($before, $this->snapshot($directory->path()));
        $this->assertSame('artificial destination collision', file_get_contents($directory->attemptedDestination));
        $this->assertFileExists(dirname($directory->attemptedDestination).'/FAILED.json');
    }

    public function test_exception_finally_retires_only_the_recorded_temporary_directory(): void
    {
        $directory = Directory::create();
        $record = null;
        try {
            try {
                throw new RuntimeException('Artificial operation failure.');
            } finally {
                $record = $directory->retire($directory->path(), 'PROCESS_FAILURE');
            }
        } catch (RuntimeException $error) {
            $this->assertSame('Artificial operation failure.', $error->getMessage());
        }
        $this->assertDirectoryDoesNotExist($directory->path());
        $this->assertDirectoryExists($record['destination']);
        $this->assertSame('PROCESS_FAILURE', $record['reason']);
    }

    private function owned(): Directory
    {
        $directory = Directory::create();
        $this->cleanup[] = $directory;

        return $directory;
    }

    private function reject(callable $operation): void
    {
        try {
            $operation();
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);

            return;
        }
        $this->fail('Unsafe cleanup was accepted.');
    }

    private function snapshot(string $path): array
    {
        $snapshot = [];
        $pending = [$path];
        while ($pending !== []) {
            $directory = array_pop($pending);
            foreach (scandir($directory) as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $child = $directory.'/'.$name;
                $relative = substr($child, strlen($path) + 1);
                $stat = lstat($child);
                $snapshot[$relative] = ['ino' => $stat['ino'], 'mode' => $stat['mode']];
                if (($stat['mode'] & 0170000) === 0040000) {
                    $pending[] = $child;
                } else {
                    $snapshot[$relative]['sha256'] = hash_file('sha256', $child);
                }
            }
        }
        ksort($snapshot);

        return $snapshot;
    }
}

final class SyntheticMountTemporaryDirectory extends Directory
{
    public ?string $mount = null;

    protected function mountPoints(): array
    {
        return [...parent::mountPoints(), ...($this->mount === null ? [] : [$this->mount])];
    }
}

class FailingMoveTemporaryDirectory extends Directory
{
    public string $attemptedDestination;

    protected function renameOnly(string $source, string $destination): void
    {
        $this->attemptedDestination = $destination;
        throw new RuntimeException('Artificial move failure.');
    }
}

final class CollisionTemporaryDirectory extends FailingMoveTemporaryDirectory
{
    protected function renameOnly(string $source, string $destination): void
    {
        $this->attemptedDestination = $destination;
        $handle = fopen($destination, 'xb');
        fwrite($handle, 'artificial destination collision');
        fclose($handle);
        Directory::renameOnly($source, $destination);
    }
}
