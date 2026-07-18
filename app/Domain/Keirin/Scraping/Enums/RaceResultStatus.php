<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Enums;

enum RaceResultStatus: string
{
    case Unavailable = 'UNAVAILABLE';
    case Provisional = 'PROVISIONAL';
    case UnderReview = 'UNDER_REVIEW';
    case Confirmed = 'CONFIRMED';
    case Corrected = 'CORRECTED';
    case Cancelled = 'CANCELLED';

    public function canTransitionFrom(self $current): bool
    {
        if ($this === $current) {
            return true;
        }

        if ($current === self::Cancelled) {
            return false;
        }

        if ($this === self::Cancelled) {
            return ! in_array($current, [self::Confirmed, self::Corrected], true);
        }

        return ! $this->isRegressionFrom($current);
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Confirmed, self::Corrected, self::Cancelled], true);
    }

    public function isRegressionFrom(self $current): bool
    {
        if ($this === $current) {
            return false;
        }

        if ($current === self::Cancelled) {
            return true;
        }

        if ($this === self::Cancelled) {
            return false;
        }

        return $this->rank() < $current->rank();
    }

    private function rank(): int
    {
        return match ($this) {
            self::Unavailable => 0,
            self::UnderReview => 1,
            self::Provisional => 2,
            self::Confirmed => 3,
            self::Corrected => 4,
            self::Cancelled => 5,
        };
    }
}
