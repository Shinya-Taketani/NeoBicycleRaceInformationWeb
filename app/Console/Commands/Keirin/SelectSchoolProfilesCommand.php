<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Statistics\Services\SchoolProfileSelectionService;
use Illuminate\Console\Command;
use Throwable;

final class SelectSchoolProfilesCommand extends Command
{
    protected $signature = 'keirin:statistics:select-school-profiles
        {--catalog= : Sealed school catalog manifest}
        {--catalog-sha256= : Expected catalog manifest SHA}
        {--output= : New private STAT44 selection JSON}
        {--dry-run : Validate without selection or database writes}';

    protected $description = 'Freeze debut-only school source selection without resolving STAT02/03 conflicts.';

    public function handle(SchoolProfileSelectionService $service): int
    {
        try {
            $plan = $service->build((string) $this->option('catalog'), (string) $this->option('catalog-sha256'),
                (string) $this->option('output'), (bool) $this->option('dry-run'));
            $this->line(json_encode(['contract' => $plan['contract'], 'summary' => $plan['summary'], 'dry_run' => (bool) $this->option('dry-run')], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
