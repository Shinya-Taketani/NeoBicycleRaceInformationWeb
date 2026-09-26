<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\MemoryLimitedTestProcess as Child;

final class MemoryLimitedTestProcessTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $root = getenv('PR72_MEMORY_EVIDENCE') ?: sys_get_temp_dir();
        $this->directory = $root.'/pr72-helper-regression-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        // Keep requested execution evidence; remove only this test's default temporary files.
        if (! getenv('PR72_MEMORY_EVIDENCE')) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->directory);
        }
        parent::tearDown();
    }

    public static function memoryCases(): iterable
    {
        foreach (Child::CASES as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('memoryCases')]
    public function test_high_parent_peak_cannot_pollute_real_128m_child(string $case): void
    {
        $execution = Child::launch([PHP_BINARY, '-d', 'memory_limit=512M',
            Child::base().'/tests/Support/memory-process-high-parent.php', $this->directory, $case], $this->directory);
        $this->assertSame(0, $execution['exit_code'], $this->logs());
        $this->assertNull($execution['error']);
        $report = json_decode(file_get_contents($this->directory.'/stdout.log'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertNotSame(getmypid(), $report['parent_pid']);
        $this->assertSame('512M', $report['parent_limit']);
        $this->assertGreaterThan(Child::LIMIT, $report['parent_peak']);
        $this->assertSame($report['parent_pid'], $report['child']['parent_pid']);
        $this->assertNotSame($report['parent_pid'], $report['child']['child_pid']);
        $this->assertSame($case, $report['child']['case']);
        $this->assertSame('128M', $report['child']['memory_limit']);
        $this->assertSame(Child::LIMIT, $report['child']['memory_limit_bytes']);
        $this->assertLessThan(Child::LIMIT, $report['child']['peak_memory_bytes']);
        $this->assertSame(1, $report['child']['tests']);
        $this->assertGreaterThan(4, $report['child']['assertions']);
    }

    public static function failures(): array
    {
        return [['failure'], ['exit'], ['teardown'], ['oom'], ['timeout'], ['zero_tests'], ['startup']];
    }

    #[DataProvider('failures')]
    public function test_failed_or_unexecuted_child_is_rejected(string $kind): void
    {
        $command = [PHP_BINARY, '-d', 'memory_limit=128M', Child::base().'/vendor/bin/phpunit',
            '--configuration', Child::base().'/phpunit.xml', Child::base().'/tests/Support/MemoryProcessProbeTest.php',
            '--filter', $kind === 'zero_tests' ? '/^does_not_exist$/' : '/::test_probe$/',
            '--log-junit', $this->directory.'/junit.xml', '--do-not-cache-result'];
        if ($kind === 'startup') {
            $command = [$this->directory.'/nonexistent-php'];
        } elseif ($kind === 'oom') {
            // Keep PHP's fatal diagnostic visible rather than PHPUnit's premature-exit summary.
            $command = [PHP_BINARY, '-d', 'memory_limit=128M', '-r', 'str_repeat("x", 140 * 1024 * 1024);'];
        }
        $execution = Child::launch($command, $this->directory, ['PR72_PROBE_MODE' => $kind], $kind === 'timeout' ? 0.5 : 120);
        if ($kind === 'zero_tests') {
            $this->assertStringContainsString('No tests executed!', $this->logs());
            // Even a runner that reports exit 0 must not make this empty JUnit pass.
            $execution['exit_code'] = 0;
        } elseif ($kind === 'timeout') {
            $this->assertStringContainsString('ProcessTimedOutException', $execution['error']);
            $this->assertLessThan(10, $execution['elapsed_seconds']);
        } else {
            $this->assertNotSame(0, $execution['exit_code'], $this->logs());
        }
        if ($kind === 'oom') {
            $this->assertStringContainsString('Allowed memory size', $this->logs());
        }
        if ($kind === 'teardown') {
            $this->assertStringContainsString('synthetic child teardown failure', $this->logs());
        }
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($kind === 'zero_tests' ? 'exactly one' : 'did not exit successfully');
        Child::verify(Child::CASES[3], $this->directory, $execution, getmypid());
    }

    public function test_environment_is_test_only_without_inherited_credentials_or_case_marker(): void
    {
        $before = getenv();
        $execution = Child::launch([PHP_BINARY, '-r', 'echo json_encode(getenv(), JSON_THROW_ON_ERROR);'], $this->directory);
        $this->assertSame(0, $execution['exit_code'], $this->logs());
        $env = json_decode(file_get_contents($this->directory.'/stdout.log'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('testing', $env['APP_ENV']);
        $this->assertSame('sqlite', $env['DB_CONNECTION']);
        $this->assertSame(':memory:', $env['DB_DATABASE']);
        foreach (['DB_URL', 'DB_HOST', 'DB_PORT', 'DB_USERNAME', 'DB_PASSWORD'] as $key) {
            $this->assertSame('', $env[$key]);
        }
        $this->assertArrayNotHasKey('PR72_MEMORY_CASE', $env);
        $this->assertArrayNotHasKey('PGPASSWORD', $env);
        $this->assertSame($before, getenv());
    }

    public static function invalidMarkers(): array
    {
        return [['unknown-case'], [Child::CASES[0]]];
    }

    #[DataProvider('invalidMarkers')]
    public function test_child_marker_is_allowlisted_and_must_match_target(string $marker): void
    {
        $command = [PHP_BINARY, '-d', 'memory_limit=128M', Child::base().'/vendor/bin/phpunit',
            '--configuration', Child::base().'/phpunit.xml', Child::base().'/tests/Feature/GrowthTrendScoreSourceTest.php',
            '--filter', '/^'.preg_quote(Child::CASES[3], '/').'$/D', '--log-junit', $this->directory.'/junit.xml',
            '--do-not-cache-result'];
        $execution = Child::launch($command, $this->directory, ['PR72_MEMORY_CASE' => $marker]);
        $this->assertNotSame(0, $execution['exit_code'], $this->logs());
        $this->assertFileDoesNotExist($this->directory.'/measurement.json');
        $this->assertStringContainsString($marker === 'unknown-case' ? 'Unknown memory test identifier' : 'Mismatched memory child identifier', $this->logs());
    }

    public static function invalidResults(): array
    {
        return array_map(static fn ($kind) => [$kind], ['missing_junit', 'broken_junit', 'wrong_case', 'wrong_file',
            'no_assertions', 'skipped', 'incomplete', 'suite_failure', 'missing_measurement', 'broken_measurement',
            'wrong_measurement_case', 'same_pid', 'wrong_pid', 'wrong_parent', 'wrong_limit', 'peak_at_limit']);
    }

    #[DataProvider('invalidResults')]
    public function test_missing_corrupt_or_mismatched_child_results_cannot_pass(string $kind): void
    {
        $case = Child::CASES[3];
        [$class, $method] = explode('::', $case);
        $doc = new \DOMDocument;
        $suite = $doc->appendChild($doc->createElement('testsuite'));
        foreach (['tests' => '1', 'errors' => '0', 'failures' => $kind === 'suite_failure' ? '1' : '0', 'skipped' => '0'] as $key => $value) {
            $suite->setAttribute($key, $value);
        }
        $test = $suite->appendChild($doc->createElement('testcase'));
        foreach (['class' => $class, 'name' => $kind === 'wrong_case' ? 'other' : $method,
            'file' => $kind === 'wrong_file' ? 'other.php' : Child::base().'/tests/Feature/GrowthTrendScoreSourceTest.php',
            'assertions' => $kind === 'no_assertions' ? '0' : '4'] as $key => $value) {
            $test->setAttribute($key, $value);
        }
        if (in_array($kind, ['skipped', 'incomplete'], true)) {
            $test->appendChild($doc->createElement('skipped', $kind));
        }
        if ($kind !== 'missing_junit') {
            file_put_contents($this->directory.'/junit.xml', $kind === 'broken_junit' ? '<broken' : $doc->saveXML());
        }
        $measurement = ['case' => $kind === 'wrong_measurement_case' ? Child::CASES[0] : $case,
            'parent_pid' => $kind === 'wrong_parent' ? -1 : getmypid(),
            'child_pid' => match ($kind) {
                'same_pid' => getmypid(), 'wrong_pid' => 2, default => 1
            },
            'memory_limit' => $kind === 'wrong_limit' ? '512M' : '128M', 'memory_limit_bytes' => Child::LIMIT,
            'peak_memory_bytes' => $kind === 'peak_at_limit' ? Child::LIMIT : 32 * 1024 * 1024];
        if ($kind !== 'missing_measurement') {
            file_put_contents($this->directory.'/measurement.json', $kind === 'broken_measurement' ? '{broken' : json_encode($measurement));
        }
        $this->expectException($kind === 'broken_measurement' ? \JsonException::class : RuntimeException::class);
        Child::verify($case, $this->directory, ['exit_code' => 0, 'error' => null, 'child_pid' => 1], getmypid());
    }

    private function logs(): string
    {
        return file_get_contents($this->directory.'/stdout.log')."\n".file_get_contents($this->directory.'/stderr.log');
    }
}
