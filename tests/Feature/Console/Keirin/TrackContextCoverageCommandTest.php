<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Keirin;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class TrackContextCoverageCommandTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/track-coverage-test-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        Http::preventStrayRequests();
        DB::shouldReceive('connection')->never();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    public function test_fixed_real_master_is_offline_deterministic_and_never_overwrites_output(): void
    {
        $targets = [['source' => 'keirin_jp', 'external_track_id' => '26', 'race_date' => '2022-06-28'],
            ['source' => 'keirin_jp', 'external_track_id' => '87', 'race_date' => '2024-07-20']];
        file_put_contents($this->directory.'/targets.json', json_encode($targets, JSON_THROW_ON_ERROR));
        $options = ['--master-version' => 'v1', '--targets' => $this->directory.'/targets.json', '--output' => $this->directory.'/report.json'];
        $this->artisan('keirin:track-context:coverage', $options)->assertSuccessful();
        $first = file_get_contents($options['--output']);
        $report = json_decode($first, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(2, $report['target_days']);
        self::assertSame(1, $report['distance_resolved_days']);
        self::assertSame(['RESOLVED' => 1, 'UNKNOWN_LAYOUT_VERSION' => 1, 'SOURCE_CONFLICT' => 0], $report['status_counts']);
        $this->artisan('keirin:track-context:coverage', $options)->assertFailed();
        self::assertSame($first, file_get_contents($options['--output']));
        $options['--output'] = $this->directory.'/repeat.json';
        $this->artisan('keirin:track-context:coverage', $options)->assertSuccessful();
        self::assertSame($first, file_get_contents($options['--output']));
        Http::assertNothingSent();
    }

    public function test_rejects_unpinned_version_without_publishing(): void
    {
        file_put_contents($this->directory.'/targets.json', '[]');
        $this->artisan('keirin:track-context:coverage', ['--master-version' => 'latest', '--targets' => $this->directory.'/targets.json', '--output' => $this->directory.'/report.json'])->assertFailed();
        self::assertFileDoesNotExist($this->directory.'/report.json');
    }

    public function test_v2_runs_offline_preserves_gaps_and_rejects_2026_duplicates_and_overwrite(): void
    {
        $targets = array_map(fn ($date) => ['source' => 'keirin_jp', 'external_track_id' => '35', 'race_date' => $date],
            ['2024-11-04', '2024-11-07', '2024-11-08']);
        $input = $this->directory.'/targets.json';
        file_put_contents($input, json_encode($targets, JSON_THROW_ON_ERROR));
        $options = ['--master-version' => 'v2', '--targets' => $input, '--output' => $this->directory.'/v2.json'];
        $this->artisan('keirin:track-context:coverage', $options)->assertSuccessful();
        $bytes = file_get_contents($options['--output']);
        self::assertSame(2, json_decode($bytes, true, 512, JSON_THROW_ON_ERROR)['distance_resolved_days']);
        $this->artisan('keirin:track-context:coverage', $options)->assertFailed();
        self::assertSame($bytes, file_get_contents($options['--output']));
        $options['--output'] = $this->directory.'/rejected.json';
        foreach ([[...$targets, $targets[0]], [['source' => 'keirin_jp', 'external_track_id' => '35', 'race_date' => '2026-01-01']]] as $invalid) {
            file_put_contents($input, json_encode($invalid, JSON_THROW_ON_ERROR));
            $this->artisan('keirin:track-context:coverage', $options)->assertFailed();
            self::assertFileDoesNotExist($options['--output']);
        }
        Http::assertNothingSent();
    }
}
