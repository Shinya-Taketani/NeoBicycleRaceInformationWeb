<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Calculators\ExternalSortEffectBinBoundaryProvider;
use App\Domain\Keirin\Backtest\Experiments\Stat35Trend6P1\RetainedBinBoundaryProvider;
use App\Domain\Keirin\Backtest\Experiments\Stat35Trend6P1\RetainedSpoolDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Tests\Support\CompositionResultTemporaryDirectory as Directory;
use Tests\Support\MemoryLimitedTestProcess;

final class Stat35Trend6SpoolManagementTest extends TestCase
{
    private Directory $temporary;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporary = Directory::create();
    }

    protected function tearDown(): void
    {
        try {
            $this->temporary->retire($this->temporary->path(), $this->status()->isSuccess() ? 'TEST_COMPLETE' : 'TEST_FAILURE');
        } finally {
            parent::tearDown();
        }
    }

    public function test_explicit_recorded_directory_retains_numeric_spools_and_matches_frozen_bins(): void
    {
        $path = $this->temporary->path();
        $provider = new RetainedBinBoundaryProvider($path);
        $values = [...range(0, 50), 0, 0.0, 2.0, 17.25, -0.75];
        $bins = $provider->build($values);
        $sorted = glob($path.'/bt02-bin-sorted-*', GLOB_NOSORT);
        $sorted = array_values(array_filter($sorted, fn ($p) => ! str_ends_with($p, '.json')));
        $this->assertCount(1, $sorted);
        // Execute the old numerical routines only, never its deleting build()/remove() lifecycle.
        $reflection = new ReflectionClass(ExternalSortEffectBinBoundaryProvider::class);
        $frozen = $reflection->newInstanceWithoutConstructor();
        $bounds = $reflection->getMethod('type7Boundaries')->invoke($frozen, $sorted[0], count($values));
        $old = $reflection->getMethod('numericBins')->invoke($frozen, $sorted[0], $bounds);
        $this->assertSame(array_map(fn ($bin) => (array) $bin, $old), array_map(fn ($bin) => (array) $bin, $bins));
        $this->assertSame(count($values), array_sum(array_column($bins, 'trainingSampleCount')));
        $seals = [];
        foreach (glob($path.'/bt02-bin-*.SEALED.json') as $recordPath) {
            $record = json_decode(file_get_contents($recordPath), true, flags: JSON_THROW_ON_ERROR);
            $created = json_decode(file_get_contents($record['path'].'.CREATED.json'), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($path, dirname($record['path']));
            $this->assertSame($record['ino'], lstat($record['path'])['ino']);
            $this->assertSame($created['ino'], $record['ino']);
            $this->assertSame(0600, fileperms($record['path']) & 0777);
            $this->assertSame($record['bytes'], filesize($record['path']));
            $this->assertSame($record['sha256'], hash_file('sha256', $record['path']));
            $seals[$record['path']] = $record;
        }
        $this->assertCount(2, $seals);
        unset($provider);
        foreach ($seals as $file => $record) {
            $this->assertFileExists($file);
            $this->assertSame($record['sha256'], hash_file('sha256', $file));
        }
    }

    public function test_directory_argument_is_mandatory(): void
    {
        $this->expectException(\ArgumentCountError::class);
        new RetainedBinBoundaryProvider;
    }

    #[DataProvider('badPaths')]
    public function test_invalid_missing_and_protected_directories_are_rejected_without_writes(string $path): void
    {
        $before = scandir($this->temporary->path());
        try {
            new RetainedBinBoundaryProvider($path);
            $this->fail('Unsafe directory accepted.');
        } catch (RuntimeException) {
            $this->assertSame($before, scandir($this->temporary->path()));
        }
    }

    public static function badPaths(): array
    {
        return [[''], ['.'], ['..'], ['relative'], ['/tmp/../tmp'], ['/tmp//nested'], ['/tmp/'],
            ['/tmp/pr103-spool-absent-'.str_repeat('f', 32)], ['/tmp'], ['/home/shinya'], ['/home/shinya/Desktop'],
            [Directory::TRASH], [dirname(__DIR__, 5)], [dirname(__DIR__, 6)]];
    }

    public function test_unrecorded_private_directory_is_not_adopted(): void
    {
        $path = $this->temporary->path().'/unrecorded';
        mkdir($path, 0700);
        $this->expectExceptionMessage('Spool path does not exist');
        new RetainedBinBoundaryProvider($path);
    }

    public function test_recorded_command_style_directory_is_accepted_and_record_is_not_overwritten(): void
    {
        $path = $this->temporary->path().'/bin-spools-artificial';
        mkdir($path, 0700);
        RetainedSpoolDirectory::recordCreation($path);
        $before = file_get_contents($path.'/CREATED.json');
        $bins = (new RetainedBinBoundaryProvider($path))->build([0.0, 0.0, 1.0]);
        $this->assertSame([2, 1], array_column($bins, 'trainingSampleCount'));
        try {
            RetainedSpoolDirectory::recordCreation($path);
            $this->fail('Creation record overwritten.');
        } catch (RuntimeException) {
            $this->assertSame($before, file_get_contents($path.'/CREATED.json'));
        }
    }

    public function test_unwritable_directory_never_falls_back_to_system_temporary_storage(): void
    {
        $path = $this->temporary->path();
        $provider = new RetainedBinBoundaryProvider($path);
        $systemBefore = glob('/tmp/bt02-bin-*');
        $before = scandir($path);
        chmod($path, 0500);
        try {
            try {
                $provider->build(range(1, 20));
                $this->fail('Unwritable directory accepted.');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('private, writable', $error->getMessage());
            }
        } finally {
            chmod($path, 0700);
        }
        $this->assertSame($before, scandir($path));
        $this->assertSame($systemBefore, glob('/tmp/bt02-bin-*'));
    }

    public function test_symlink_and_hardlink_spools_are_rejected_without_touching_their_target(): void
    {
        $unsafe = Directory::create();
        $target = $this->temporary->path().'/sentinel';
        file_put_contents($target, 'artificial untouched target');
        symlink($this->temporary->path(), $unsafe->path().'/linked-directory');
        try {
            new RetainedBinBoundaryProvider($unsafe->path().'/linked-directory');
            $this->fail('Linked directory accepted.');
        } catch (RuntimeException) {
            $this->assertSame('artificial untouched target', file_get_contents($target));
        }
        $spools = new RetainedSpoolDirectory($unsafe->path());
        $file = $spools->create('input');
        link($file['path'], $unsafe->path().'/hard-link');
        try {
            $spools->verify($file);
            $this->fail('Hard-linked spool accepted.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('links forbidden', $error->getMessage());
        } finally {
            fclose($file['handle']);
        }
        $this->assertSame('artificial untouched target', file_get_contents($target));
        $this->assertTrue(is_link($unsafe->path().'/linked-directory'));
        // Unsafe synthetic evidence stays recorded in place; do not unlink links to make retirement pass.
        $this->assertFileExists($unsafe->path().'/CREATED.json');
    }

    public function test_record_mismatch_and_linked_creation_record_are_rejected(): void
    {
        $path = $this->temporary->path().'/wrong-record';
        mkdir($path, 0700);
        RetainedSpoolDirectory::recordCreation($path);
        $record = json_decode(file_get_contents($path.'/CREATED.json'), true, flags: JSON_THROW_ON_ERROR);
        $record['process_id']++;
        file_put_contents($path.'/CREATED.json', json_encode($record, JSON_THROW_ON_ERROR));
        try {
            new RetainedBinBoundaryProvider($path);
            $this->fail('Unowned creation record accepted.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('not recorded by this process', $error->getMessage());
        }
        $unsafe = Directory::create();
        mkdir($unsafe->path().'/linked-record', 0700);
        symlink($this->temporary->path().'/CREATED.json', $unsafe->path().'/linked-record/CREATED.json');
        try {
            new RetainedBinBoundaryProvider($unsafe->path().'/linked-record');
            $this->fail('Linked creation record accepted.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('links forbidden', $error->getMessage());
        }
    }

    public function test_sort_failure_retains_recorded_partial_spools(): void
    {
        $path = $this->temporary->path();
        try {
            (new RetainedBinBoundaryProvider($path, '/usr/bin/false'))->build(range(1, 20));
            $this->fail('Failed sort accepted.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('external effect-bin sort failed', $error->getMessage());
        }
        $this->assertCount(2, glob($path.'/bt02-bin-*.CREATED.json'));
        $this->assertCount(2, glob($path.'/bt02-bin-*.SEALED.json'));
        foreach (glob($path.'/bt02-bin-*.SEALED.json') as $recordPath) {
            $record = json_decode(file_get_contents($recordPath), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($path, dirname($record['path']));
            $this->assertFileExists($record['path']);
        }
    }

    public function test_link_created_during_training_iteration_is_rejected_before_any_spool_write(): void
    {
        $unsafe = Directory::create();
        $path = $unsafe->path();
        $values = static function () use ($path): \Generator {
            $files = array_values(array_filter(glob($path.'/bt02-bin-input-*'), fn ($file) => ! str_ends_with($file, '.json')));
            link($files[0], $path.'/unexpected-alias');
            yield 1.0;
        };
        try {
            (new RetainedBinBoundaryProvider($path))->build($values());
            $this->fail('Writing through a newly linked spool was allowed.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('links forbidden', $error->getMessage());
        }
        $this->assertSame('', file_get_contents($path.'/unexpected-alias'));
        $this->assertFileExists($path.'/CREATED.json');
        // Preserve the deliberate unsafe links and their creation evidence, without delete fallback.
    }

    public function test_streamed_numeric_sort_in_independent_128m_process(): void
    {
        $root = dirname(__DIR__, 5);
        $directory = $this->temporary->path().'/memory-probe';
        mkdir($directory, 0700);
        $execution = MemoryLimitedTestProcess::launch([PHP_BINARY, '-d', 'memory_limit=128M',
            $root.'/tests/Support/stat35-trend6-spool-memory.php', $directory], $directory);
        $this->assertSame(0, $execution['exit_code'], file_get_contents($directory.'/stderr.log'));
        $measurement = json_decode(file_get_contents($directory.'/measurement.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(128 * 1024 * 1024, $measurement['memory_limit_bytes']);
        $this->assertSame(100001, $measurement['training_support']);
        $this->assertLessThan(128 * 1024 * 1024, $measurement['peak_memory_bytes']);
        $this->assertSame('PROCESS_COMPLETE', $measurement['retirement']['reason']);
        $this->assertDirectoryExists($measurement['retirement']['destination']);
    }

    public function test_same_device_mount_metadata_is_rejected_before_creation(): void
    {
        $path = $this->temporary->path();
        $method = new \ReflectionMethod(RetainedSpoolDirectory::class, 'assertNoMounts');
        foreach ([$path, $path.'/synthetic-mount'] as $mount) {
            // The actual constructor reads /proc/self/mountinfo; only this artificial metadata is injected here.
            $line = '100 99 0:1 / '.$mount.' rw - ext4 artificial-source rw';
            try {
                $method->invoke(null, $path, [$line]);
                $this->fail('Same-device mount accepted.');
            } catch (RuntimeException $error) {
                $this->assertSame('Mount inside spool directory forbidden.', $error->getMessage());
            }
        }
        $method->invoke(null, $path, ['100 99 0:1 / '.$path.'-other rw - ext4 artificial-source rw']);
        $this->assertSame([], glob($path.'/bt02-bin-*'));
    }
}
