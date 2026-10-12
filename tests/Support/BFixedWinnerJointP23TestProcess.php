<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use WeakMap;

final class BFixedWinnerJointP23TestProcess
{
    private static ?WeakMap $owners = null;

    private const CLASS_NAME = 'Tests\\Feature\\BFixedWinnerJointP23Test';

    private const METHODS = [
        'test_saved_bundle_two_independent_runs_teacher_order_and_outcome_isolation',
        'test_source_manifest_and_parent_drift_rejected',
        'test_global_race_reappearance_is_rejected_even_when_ids_are_nonmonotonic',
        'test_source_end_drift_blocks_teacher_release',
        'test_synthetic_source_guard_rejects_outside_paths_and_non_testing_environment',
    ];

    public static function temporary(): CompositionResultTemporaryDirectory
    {
        $temporary = CompositionResultTemporaryDirectory::create(self::needsIsolation() ? '/home/shinya/Desktop' : '/tmp');
        self::$owners ??= new WeakMap;
        $stat = lstat($temporary->path());
        self::$owners[$temporary] = [$stat['dev'], $stat['ino'], hash_file('sha256', $temporary->path().'/CREATED.json')];

        return $temporary;
    }

    public static function needsIsolation(): bool
    {
        return sys_get_temp_dir() !== '/tmp' || ! self::sameMount('/tmp', CompositionResultTemporaryDirectory::TRASH);
    }

    public static function delegate(TestCase $test, CompositionResultTemporaryDirectory $temporary): bool
    {
        $case = $test::class.'::'.$test->nameWithDataSet();
        self::caseName($case);
        if (! self::needsIsolation()) {
            Assert::assertStringStartsWith(sys_get_temp_dir().'/', $temporary->path());

            return false;
        }
        $report = self::run($case, $temporary);
        Assert::assertSame($case, $report['case']);
        $test->addToAssertionCount($report['assertions']);

        return true;
    }

    public static function run(string $case, CompositionResultTemporaryDirectory $temporary): array
    {
        self::caseName($case);
        $path = $temporary->path();
        $temporary->assertRecordedPath($path);
        $record = json_decode(file_get_contents($path.'/CREATED.json'), true, flags: JSON_THROW_ON_ERROR);
        $stat = lstat($path);
        if (! isset(self::$owners[$temporary]) || self::$owners[$temporary]
            !== [$stat['dev'], $stat['ino'], hash_file('sha256', $path.'/CREATED.json')]) {
            throw new RuntimeException('Recorded test-process identity changed or owner is unregistered.');
        }
        if (realpath($path) !== $path || ($stat['mode'] & 0170000) !== 0040000
            || [$record['source'], $record['process_id'], $record['device'], $record['inode']]
                !== [$path, getmypid(), $stat['dev'], $stat['ino']]) {
            throw new RuntimeException('Recorded test-process owner changed.');
        }
        $sandbox = $path.'/sandbox';
        $logs = $path.'/process';
        foreach ([$sandbox, $logs, $sandbox.'/tmp', $sandbox.'/evidence', $sandbox.'/home', $sandbox.'/home/shinya',
            $sandbox.'/home/shinya/Desktop', $sandbox.'/home/shinya/Desktop/Trash'] as $directory) {
            if (file_exists($directory) || is_link($directory) || ! mkdir($directory, 0700)) {
                throw new RuntimeException('Test-process directory must be exclusively created.');
            }
        }
        $env = fopen($sandbox.'/evidence/artificial.env', 'xb');
        if ($env === false) {
            throw new RuntimeException('Could not create artificial environment fixture.');
        }
        try {
            $text = "# Isolated test fixture. No application secrets.\n";
            if (fwrite($env, $text) !== strlen($text) || ! fflush($env)) {
                throw new RuntimeException('Artificial environment fixture write failed.');
            }
        } finally {
            fclose($env);
        }
        JsonlArtifact::json($sandbox.'/ISOLATION.json', ['case' => $case, 'owner_record_sha256' => hash_file('sha256', $path.'/CREATED.json'),
            'root' => $sandbox, 'parent_pid' => getmypid(), 'sys_temp_dir' => sys_get_temp_dir(),
            'TMPDIR' => getenv('TMPDIR') ?: null, 'APP_ENV' => getenv('APP_ENV') ?: null]);

        $base = dirname(__DIR__, 2);
        // One recorded filesystem view contains both /tmp and Trash; separate bind mounts cannot rename across one another.
        $command = ['/usr/bin/bwrap', '--die-with-parent', '--unshare-net', '--bind', $sandbox, '/'];
        foreach (['/usr', '/bin', '/lib', '/lib64', '/etc'] as $system) {
            $command = [...$command, '--ro-bind', $system, $system];
        }
        $command = [...$command, '--dev', '/dev', '--proc', '/proc', '--dir', '/run',
            '--ro-bind', $base, $base, '--ro-bind', $sandbox.'/evidence/artificial.env', $base.'/.env',
            '--tmpfs', $base.'/storage', '--tmpfs', $base.'/bootstrap/cache', '--chdir', $base,
            PHP_BINARY, '-d', 'memory_limit=128M', '-d', 'sys_temp_dir=/tmp', $base.'/vendor/bin/phpunit',
            '--configuration', $base.'/phpunit.xml', $base.'/tests/Feature/BFixedWinnerJointP23Test.php',
            '--filter', '/^'.preg_quote($case, '/').'$/D', '--do-not-cache-result', '--colors=never', '--display-all-issues',
            '--fail-on-warning', '--fail-on-risky', '--fail-on-skipped', '--fail-on-incomplete',
            '--log-junit', '/evidence/junit.xml', '--log-events-text', '/evidence/events.log'];
        $execution = MemoryLimitedTestProcess::launch($command, $logs, ['APP_CONFIG_CACHE' => '/evidence/absent-config.php']);
        if ($execution['exit_code'] !== 0 || $execution['error'] !== null) {
            throw new RuntimeException('Isolated test failed; retained evidence: '.$logs."\n"
                .file_get_contents($logs.'/stdout.log').file_get_contents($logs.'/stderr.log'));
        }
        $xml = new DOMDocument;
        if (! $xml->load($sandbox.'/evidence/junit.xml', LIBXML_NONET) || $xml->doctype !== null) {
            throw new RuntimeException('Invalid isolated JUnit.');
        }
        $xpath = new DOMXPath($xml);
        $cases = $xpath->query('//testcase');
        if ($cases->length !== 1 || $xpath->query('//failure|//error|//skipped')->length !== 0) {
            throw new RuntimeException('Isolated test must execute exactly one passing case.');
        }
        $entry = $cases->item(0);
        if ($entry->getAttribute('class').'::'.$entry->getAttribute('name') !== $case
            || $entry->getAttribute('file') !== $base.'/tests/Feature/BFixedWinnerJointP23Test.php'
            || (int) $entry->getAttribute('assertions') < 1) {
            throw new RuntimeException('Isolated JUnit case/assertions mismatch.');
        }
        $report = ['case' => $case, 'assertions' => (int) $entry->getAttribute('assertions'), 'exit_code' => 0,
            'parent_pid' => getmypid(), 'child_pid' => $execution['child_pid'], 'root' => $sandbox];
        JsonlArtifact::json($logs.'/verified.json', $report);

        return $report;
    }

    public static function environment(string $path): array
    {
        return ['APP_ENV' => getenv('APP_ENV') ?: null, 'TMPDIR' => getenv('TMPDIR') ?: null,
            'sys_get_temp_dir' => sys_get_temp_dir(), 'fixture_realpath' => realpath($path),
            'tmp_device' => stat('/tmp')['dev'], 'trash_device' => stat(CompositionResultTemporaryDirectory::TRASH)['dev'],
            'same_mount' => self::sameMount('/tmp', CompositionResultTemporaryDirectory::TRASH),
            'memory_limit' => ini_get('memory_limit'), 'peak_memory_bytes' => memory_get_peak_usage(true), 'pid' => getmypid()];
    }

    private static function sameMount(string $first, string $second): bool
    {
        if (realpath($first) !== $first || realpath($second) !== $second || stat($first)['dev'] !== stat($second)['dev']) {
            return false;
        }
        $points = [];
        foreach (file('/proc/self/mountinfo', FILE_IGNORE_NEW_LINES) ?: throw new RuntimeException('Cannot verify test mount boundaries.') as $line) {
            $fields = explode(' ', $line);
            $points[strtr($fields[4], ['\\040' => ' ', '\\011' => "\t", '\\012' => "\n", '\\134' => '\\'])] = $fields[0];
        }
        $mount = static function (string $path) use ($points): string {
            $best = '';
            foreach ($points as $point => $id) {
                if (($point === '/' || $path === $point || str_starts_with($path, $point.'/')) && strlen($point) > strlen($best)) {
                    $best = $point;
                }
            }

            return $points[$best] ?? throw new RuntimeException('Test mount metadata incomplete.');
        };

        return $mount($first) === $mount($second);
    }

    private static function caseName(string $case): void
    {
        foreach (self::METHODS as $method) {
            $name = self::CLASS_NAME.'::'.$method;
            if ($case === $name && $method !== 'test_source_manifest_and_parent_drift_rejected') {
                return;
            }
            if ($method === 'test_source_manifest_and_parent_drift_rejected' && in_array($case,
                array_map(fn ($i) => $name.' with data set #'.$i, range(0, 3)), true)) {
                return;
            }
        }
        throw new RuntimeException('Unapproved isolated joint-decoder test case.');
    }
}
