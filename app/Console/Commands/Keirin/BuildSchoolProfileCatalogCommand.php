<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Statistics\Services\SchoolProfileCatalogService;
use Illuminate\Console\Command;
use Throwable;

final class BuildSchoolProfileCatalogCommand extends Command
{
    protected $signature = 'keirin:statistics:build-school-profile-catalog
        {--source-manifest= : Sealed existing-data review manifest}
        {--source-manifest-sha256= : Expected source manifest SHA}
        {--output= : New private catalog directory}
        {--dry-run : Validate without catalog, selection or database writes}';

    protected $description = 'Retain all saved school projection versions without cohort or prediction inference.';

    public function handle(SchoolProfileCatalogService $service): int
    {
        try {
            $out = $service->build((string) $this->option('source-manifest'), (string) $this->option('source-manifest-sha256'),
                (string) $this->option('output'), (bool) $this->option('dry-run'));
            $this->line(json_encode($out, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
