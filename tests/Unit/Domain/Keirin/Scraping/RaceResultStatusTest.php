<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Scraping;

use App\Domain\Keirin\Scraping\Enums\RaceResultStatus;
use PHPUnit\Framework\TestCase;

class RaceResultStatusTest extends TestCase
{
    public function test_transition_matrix_is_fixed(): void
    {
        $allowed = [
            'UNAVAILABLE' => ['UNAVAILABLE', 'UNDER_REVIEW', 'PROVISIONAL', 'CONFIRMED', 'CORRECTED', 'CANCELLED'],
            'UNDER_REVIEW' => ['UNDER_REVIEW', 'PROVISIONAL', 'CONFIRMED', 'CORRECTED', 'CANCELLED'],
            'PROVISIONAL' => ['PROVISIONAL', 'CONFIRMED', 'CORRECTED', 'CANCELLED'],
            'CONFIRMED' => ['CONFIRMED', 'CORRECTED'],
            'CORRECTED' => ['CORRECTED'],
            'CANCELLED' => ['CANCELLED'],
        ];

        foreach (RaceResultStatus::cases() as $current) {
            foreach (RaceResultStatus::cases() as $requested) {
                $this->assertSame(
                    in_array($requested->value, $allowed[$current->value], true),
                    $requested->canTransitionFrom($current),
                    "Unexpected transition {$current->value} -> {$requested->value}",
                );
            }
        }
    }

    public function test_final_and_regression_helpers_are_consistent(): void
    {
        $this->assertTrue(RaceResultStatus::Confirmed->isFinal());
        $this->assertTrue(RaceResultStatus::Corrected->isFinal());
        $this->assertTrue(RaceResultStatus::Cancelled->isFinal());
        $this->assertFalse(RaceResultStatus::Provisional->isFinal());
        $this->assertTrue(RaceResultStatus::UnderReview->isRegressionFrom(RaceResultStatus::Confirmed));
        $this->assertTrue(RaceResultStatus::Confirmed->isRegressionFrom(RaceResultStatus::Corrected));
        $this->assertFalse(RaceResultStatus::Corrected->isRegressionFrom(RaceResultStatus::Confirmed));
    }
}
