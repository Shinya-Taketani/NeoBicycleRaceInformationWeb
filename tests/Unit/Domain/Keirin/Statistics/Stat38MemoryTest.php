<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Statistics;

use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;
use Tests\Support\CompositionResultTemporaryDirectory;
use Tests\Support\MemoryLimitedTestProcess;

final class Stat38MemoryTest extends TestCase
{
    public function test_real_raw_import_and_result_verification_in_an_independent_128m_process(): void
    {
        $temporary = CompositionResultTemporaryDirectory::create();
        $root = $temporary->path();
        $reason = 'TEST_FAILURE';
        $base = dirname(__DIR__, 5);
        $methods = ['test_actual_saved_raw_for_each_development_year_preserves_parser_values_and_source_seal',
            'test_actual_saved_results_verify_null_db_links_and_distinct_acquisition_processing_times',
            'test_real_history_results_confirm_retrospective_starts_but_never_pre_cutoff_experience'];
        try {
            $execution = MemoryLimitedTestProcess::launch([PHP_BINARY, '-d', 'memory_limit=128M', $base.'/vendor/bin/phpunit',
                '--configuration', $base.'/phpunit.xml', '--bootstrap', $base.'/tests/Support/stat38-memory-bootstrap.php',
                $base.'/tests/Feature/Stat38DistanceMvpTest.php', '--filter', '/::('.implode('|', $methods).')$/D',
                '--do-not-cache-result', '--display-all-issues', '--log-junit', $root.'/junit.xml',
                '--log-events-text', $root.'/events.log', '--fail-on-warning', '--fail-on-risky', '--fail-on-skipped',
                '--fail-on-incomplete'], $root, [
                    'HOME' => getenv('HOME'), 'TMPDIR' => getenv('TMPDIR') ?: '/tmp',
                    'STAT38_TEST_FIXTURE' => getenv('STAT38_TEST_FIXTURE'), 'STAT38_RESULT_FIXTURE' => getenv('STAT38_RESULT_FIXTURE'),
                    'STAT38_MEMORY_DIRECTORY' => $root, 'STAT38_MEMORY_PARENT_PID' => (string) getmypid(),
                    'APP_PACKAGES_CACHE' => $root.'/packages.php', 'APP_SERVICES_CACHE' => $root.'/services.php',
                ]);
            $this->assertNull($execution['error']);
            $this->assertSame(0, $execution['exit_code'], 'Child evidence: '.$root);
            $doc = new DOMDocument;
            $this->assertTrue($doc->load($root.'/junit.xml', LIBXML_NONET));
            $xpath = new DOMXPath($doc);
            $this->assertSame(3, $xpath->query('//testcase')->length);
            $this->assertSame(0, $xpath->query('//failure|//error|//skipped')->length);
            $names = [];
            foreach ($xpath->query('//testcase') as $case) {
                $names[] = $case->getAttribute('name');
                $this->assertSame('Tests\Feature\Stat38DistanceMvpTest', $case->getAttribute('class'));
                $this->assertGreaterThan(0, (int) $case->getAttribute('assertions'));
            }
            $this->assertSame($methods, $names);
            $measurement = json_decode(file_get_contents($root.'/measurement.json'), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('128M', $measurement['memory_limit']);
            $this->assertSame(getmypid(), $measurement['parent_pid']);
            $this->assertNotSame(getmypid(), $measurement['child_pid']);
            $this->assertSame($execution['child_pid'], $measurement['child_pid']);
            $this->assertGreaterThan(0, $measurement['peak_memory_bytes']);
            $this->assertLessThanOrEqual(128 * 1024 * 1024, $measurement['peak_memory_bytes']);
            $reason = 'TEST_COMPLETE';
        } finally {
            $temporary->retire($temporary->path(), $reason);
        }
    }
}
