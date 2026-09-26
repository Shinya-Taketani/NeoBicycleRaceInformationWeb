<?php

declare(strict_types=1);

namespace Tests\Support;

use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Assert;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

final class MemoryLimitedTestProcess
{
    public const LIMIT = 128 * 1024 * 1024;

    public const CASES = [
        'Tests\\Feature\\GrowthTrendAnalysisTest::test_db_disabled_execute_reproduce_byte_exact_and_labels_can_be_withheld_until_seal',
        'Tests\\Feature\\GrowthTrendAnalysisTest::test_selected_candidate_diagnostics_match_independent_winner_gaps',
        'Tests\\Feature\\GrowthTrendScoreSourceTest::test_capture_uses_only_approved_tables_and_columns_and_verify_needs_no_db',
        'Tests\\Feature\\GrowthTrendScoreSourceTest::test_history_pagination_has_no_duplicates_or_missing_rows',
        'Tests\\Feature\\Stat35DataReadinessAuditTest::test_real_execution_path_and_db_disabled_byte_exact_reproduction',
    ];

    public static function delegate(string $case): bool
    {
        self::caseFile($case);
        $child = getenv('PR72_MEMORY_CASE');
        if ($child !== false) {
            self::caseFile($child);
            Assert::assertSame($case, $child, 'Mismatched memory child identifier.');
            Assert::assertSame(self::LIMIT, ini_parse_quantity(ini_get('memory_limit')));
            $parent = getenv('PR72_MEMORY_PARENT_PID');
            Assert::assertTrue(is_string($parent) && ctype_digit($parent) && (int) $parent > 0);
            Assert::assertNotSame((int) $parent, getmypid());
            Assert::assertSame('testing', getenv('APP_ENV'));
            Assert::assertSame('sqlite', getenv('DB_CONNECTION'));
            Assert::assertSame(':memory:', getenv('DB_DATABASE'));
            Assert::assertSame('', getenv('DB_URL'));

            return false;
        }

        $result = self::run($case);
        Assert::assertSame($case, $result['case']);

        return true;
    }

    public static function record(string $case, int $peak): void
    {
        self::caseFile($case);
        Assert::assertSame($case, getenv('PR72_MEMORY_CASE'));
        $directory = getenv('PR72_MEMORY_DIRECTORY');
        Assert::assertTrue(is_string($directory) && str_starts_with($directory, '/') && is_dir($directory));
        self::json($directory.'/measurement.json', [
            'case' => $case,
            'parent_pid' => (int) getenv('PR72_MEMORY_PARENT_PID'),
            'child_pid' => getmypid(),
            'memory_limit' => ini_get('memory_limit'),
            'memory_limit_bytes' => ini_parse_quantity(ini_get('memory_limit')),
            'peak_memory_bytes' => $peak,
        ]);
    }

    public static function run(string $case, ?string $root = null): array
    {
        $file = self::caseFile($case);
        $temporary = $root === null && ! getenv('PR72_MEMORY_EVIDENCE');
        $root ??= getenv('PR72_MEMORY_EVIDENCE') ?: sys_get_temp_dir();
        if (! str_starts_with($root, '/') || ! is_dir($root)) {
            throw new RuntimeException('Memory evidence root must be an existing absolute directory.');
        }
        $directory = $root.'/pr72-memory-'.bin2hex(random_bytes(8));
        if (! mkdir($directory, 0700)) {
            throw new RuntimeException('Cannot create memory evidence directory.');
        }
        $command = [PHP_BINARY, '-d', 'memory_limit=128M', self::base().'/vendor/bin/phpunit',
            '--configuration', self::base().'/phpunit.xml', $file,
            '--filter', '/^'.preg_quote($case, '/').'$/D', '--log-junit', $directory.'/junit.xml',
            '--do-not-cache-result', '--colors=never', '--fail-on-risky', '--fail-on-warning',
            '--fail-on-skipped', '--fail-on-incomplete'];
        $execution = self::launch($command, $directory, [
            'PR72_MEMORY_CASE' => $case,
            'PR72_MEMORY_PARENT_PID' => (string) getmypid(),
            'PR72_MEMORY_DIRECTORY' => $directory,
        ]);
        try {
            $result = self::verify($case, $directory, $execution, getmypid());
            self::json($directory.'/verified.json', $result);
            if ($temporary) {
                foreach (glob($directory.'/*') as $path) {
                    unlink($path);
                }
                rmdir($directory);
            }

            return $result;
        } catch (Throwable $e) {
            throw new RuntimeException($e->getMessage()."\nEvidence: ".$directory."\nstdout:\n"
                .file_get_contents($directory.'/stdout.log')."\nstderr:\n".file_get_contents($directory.'/stderr.log'), 0, $e);
        }
    }

    // Symfony inherits environment by default; explicitly remove it before supplying testing values.
    public static function environment(array $extra = []): array
    {
        $removed = array_fill_keys(array_keys(getenv() + $_ENV + $_SERVER), false);

        return array_replace($removed, [
            'PATH' => '/usr/bin:/bin', 'APP_ENV' => 'testing', 'APP_MAINTENANCE_DRIVER' => 'file',
            'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '',
            'DB_HOST' => '', 'DB_PORT' => '', 'DB_USERNAME' => '', 'DB_PASSWORD' => '',
            'BCRYPT_ROUNDS' => '4', 'BROADCAST_CONNECTION' => 'null', 'CACHE_STORE' => 'array',
            'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array',
            'PULSE_ENABLED' => 'false', 'TELESCOPE_ENABLED' => 'false', 'NIGHTWATCH_ENABLED' => 'false',
        ], $extra);
    }

    public static function launch(array $command, string $directory, array $extra = [], float $timeout = 120): array
    {
        $started = microtime(true);
        $execution = ['command' => $command, 'parent_pid' => getmypid(), 'child_pid' => null,
            'started_at' => date(DATE_ATOM), 'exit_code' => null, 'error' => null];
        $process = new Process($command, self::base(), self::environment($extra + [
            'APP_CONFIG_CACHE' => $directory.'/absent-config.php',
        ]), timeout: $timeout);
        $stdout = fopen($directory.'/stdout.log', 'x');
        $stderr = fopen($directory.'/stderr.log', 'x');
        try {
            $process->start(static function (string $type, string $text) use ($stdout, $stderr): void {
                fwrite($type === Process::OUT ? $stdout : $stderr, $text);
            });
            $execution['child_pid'] = $process->getPid();
            $execution['exit_code'] = $process->wait();
        } catch (Throwable $e) {
            $execution['error'] = $e::class.': '.$e->getMessage();
        } finally {
            if ($process->isRunning()) {
                $process->stop(0.2);
            }
            fclose($stdout);
            fclose($stderr);
            $execution['finished_at'] = date(DATE_ATOM);
            $execution['elapsed_seconds'] = microtime(true) - $started;
            self::json($directory.'/execution.json', $execution);
        }

        return $execution;
    }

    public static function verify(string $case, string $directory, array $execution, int $parent): array
    {
        $file = self::caseFile($case);
        if ($execution['exit_code'] !== 0 || $execution['error'] !== null) {
            throw new RuntimeException('Memory child did not exit successfully: '.json_encode($execution));
        }
        if (! is_file($directory.'/junit.xml')) {
            throw new RuntimeException('Missing child JUnit.');
        }
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->load($directory.'/junit.xml', LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (! $loaded || $document->doctype !== null || ! in_array($document->documentElement->nodeName, ['testsuites', 'testsuite'], true)) {
            throw new RuntimeException('Invalid child JUnit.');
        }
        $xpath = new DOMXPath($document);
        $cases = $xpath->query('//testcase');
        [$class, $method] = explode('::', $case);
        if ($cases->length !== 1 || $xpath->query('//failure|//error|//skipped')->length !== 0) {
            throw new RuntimeException('Child must execute exactly one passing, non-skipped test.');
        }
        $test = $cases->item(0);
        if ($test->getAttribute('class') !== $class || $test->getAttribute('name') !== $method
            || $test->getAttribute('file') !== $file || (int) $test->getAttribute('assertions') < 1) {
            throw new RuntimeException('Child JUnit identity/assertions mismatch.');
        }
        foreach ($xpath->query('//testsuite') as $suite) {
            if ($suite->getAttribute('tests') !== '1' || $suite->getAttribute('failures') !== '0'
                || $suite->getAttribute('errors') !== '0' || $suite->getAttribute('skipped') !== '0') {
                throw new RuntimeException('Child JUnit suite did not pass.');
            }
        }
        if (! is_file($directory.'/measurement.json')) {
            throw new RuntimeException('Missing child memory measurement.');
        }
        $measurement = json_decode(file_get_contents($directory.'/measurement.json'), true, flags: JSON_THROW_ON_ERROR);
        if (($measurement['case'] ?? null) !== $case || ($measurement['parent_pid'] ?? null) !== $parent
            || ! is_int($measurement['child_pid'] ?? null) || $measurement['child_pid'] <= 0
            || $measurement['child_pid'] === $parent || $measurement['child_pid'] !== $execution['child_pid']
            || ($measurement['memory_limit'] ?? null) !== '128M' || ($measurement['memory_limit_bytes'] ?? null) !== self::LIMIT
            || ! is_int($measurement['peak_memory_bytes'] ?? null)
            || $measurement['peak_memory_bytes'] <= 0 || $measurement['peak_memory_bytes'] >= self::LIMIT) {
            throw new RuntimeException('Child memory measurement/identity mismatch.');
        }

        return $measurement + ['tests' => 1, 'assertions' => (int) $test->getAttribute('assertions'),
            'failures' => 0, 'errors' => 0, 'skipped' => 0, 'exit_code' => 0];
    }

    public static function base(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function caseFile(string $case): string
    {
        if (! in_array($case, self::CASES, true)) {
            throw new RuntimeException('Unknown memory test identifier: '.$case);
        }
        [$class] = explode('::', $case);

        return self::base().'/tests/'.str_replace('\\', '/', substr($class, strlen('Tests\\'))).'.php';
    }

    private static function json(string $path, array $data): void
    {
        $stream = fopen($path, 'x');
        fwrite($stream, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        fclose($stream);
    }
}
