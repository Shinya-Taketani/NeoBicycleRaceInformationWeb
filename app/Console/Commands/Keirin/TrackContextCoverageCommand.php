<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\TrackContext\CoverageReporter;
use App\Domain\Keirin\TrackContext\TrackContextMaster;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

final class TrackContextCoverageCommand extends Command
{
    protected $signature = 'keirin:track-context:coverage {--master-version=} {--targets=} {--output=}';

    protected $description = 'Resolve a frozen 2022-2025 track/date inventory using an explicitly versioned file master (no DB/HTTP).';

    public function handle(CoverageReporter $reporter): int
    {
        try {
            $version = (string) $this->option('master-version');
            $targetsPath = (string) $this->option('targets');
            $output = (string) $this->option('output');
            if (! is_file($targetsPath) || filesize($targetsPath) > 16 * 1024 * 1024 || $output === '') {
                throw new RuntimeException('Provide a readable bounded target inventory and a new output path.');
            }
            $bytes = file_get_contents($targetsPath);
            $targets = json_decode($bytes, true, 128, JSON_THROW_ON_ERROR);
            if (! is_array($targets) || ! array_is_list($targets)) {
                throw new RuntimeException('Target inventory must be a list.');
            }
            $report = $reporter->report(TrackContextMaster::load(resource_path('data/keirin/track-context/'.$version), $version), $targets);
            $report['targets_sha256'] = hash('sha256', $bytes);
            $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
            $handle = @fopen($output, 'xb');
            if ($handle === false) {
                throw new RuntimeException('Output must not exist and its parent must be writable.');
            }
            try {
                if (fwrite($handle, $json) !== strlen($json) || ! fflush($handle)) {
                    throw new RuntimeException('Incomplete output; retained for diagnosis.');
                }
            } finally {
                fclose($handle);
            }
            $this->line(json_encode(array_diff_key($report, ['tracks' => true]), JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
