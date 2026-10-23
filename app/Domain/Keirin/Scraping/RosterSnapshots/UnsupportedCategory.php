<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\RosterSnapshots;

use App\Domain\Keirin\Scraping\Enums\RaceCategory;
use RuntimeException;

final class UnsupportedCategory extends RuntimeException
{
    public function __construct(public readonly RaceCategory $rawCategory, public readonly RaceCategory $databaseCategory)
    {
        parent::__construct($rawCategory === RaceCategory::Unknown ? 'RAW_CATEGORY_UNKNOWN' : 'UNSUPPORTED_RACE_CATEGORY');
    }

    public function result(): array
    {
        return ['status' => 'SKIPPED_UNSUPPORTED_CATEGORY', 'reason' => $this->getMessage(),
            'raw_category' => $this->rawCategory->value, 'database_category' => $this->databaseCategory->value,
            'category_policy_version' => Reader::CATEGORY_POLICY_VERSION];
    }
}
