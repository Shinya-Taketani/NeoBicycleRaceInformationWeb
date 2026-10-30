<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Statistics\Services\SchoolProfileEvidenceService;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

final class ExtractSchoolProfileCommand extends Command
{
    protected $signature = 'keirin:statistics:extract-school-profile
        {--raw= : Saved profile Raw; never acquired by this command}
        {--source= : Private source metadata exported from the saved fetch log}
        {--source-sha256= : Expected seal of source metadata}
        {--output= : New JSON path; existing files are never overwritten}
        {--dry-run : Extract without output writes}';

    protected $description = 'Project school rank, 200m time and debut date without current performance or cohort inference.';

    public function handle(SchoolProfileEvidenceService $service): int
    {
        try {
            $file = $this->option('source');
            if (! is_string($file) || ! is_file($file) || hash_file('sha256', $file) !== $this->option('source-sha256')) {
                throw new RuntimeException('Source metadata seal mismatch.');
            }
            $projection = $service->extract((string) $this->option('raw'), json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR));
            if (! $this->option('dry-run')) {
                $service->store((string) $this->option('output'), $projection);
            }
            $this->line(json_encode($projection, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
