<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Scraping;

use App\Domain\Keirin\Scraping\Enums\RaceResultStatus;
use App\Domain\Keirin\Scraping\Support\RaceResultStatusPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RaceResultStatusPolicyTest extends TestCase
{
    #[DataProvider('statusCases')]
    public function test_it_requires_explicit_evidence_for_each_result_status(
        array $context,
        array $result,
        ?RaceResultStatus $expected,
    ): void {
        $decision = (new RaceResultStatusPolicy)->decide($context, $result);

        $this->assertSame($expected, $decision->status);
        $this->assertNotSame('', $decision->evidence);
    }

    public static function statusCases(): array
    {
        return [
            'race cancelled' => [['flgRaceCancel' => true], [], RaceResultStatus::Cancelled],
            'section cancelled' => [['flgSectionCancel' => true], [], RaceResultStatus::Cancelled],
            'under review' => [[], ['statusMessage' => '審議中'], RaceResultStatus::UnderReview],
            'provisional' => [[], ['statusMessage' => '暫定'], RaceResultStatus::Provisional],
            'explicit confirmed' => [[], ['statusMessage' => '確定'], RaceResultStatus::Confirmed],
            'corrected' => [[], ['statusMessage' => '結果訂正'], RaceResultStatus::Corrected],
            'unavailable' => [self::contextWithRace(false, '0', '0'), ['tyakujyunDispFlg' => false], RaceResultStatus::Unavailable],
            'actual confirmed flags' => [self::contextWithRace(true, '1', '1'), [
                'resultCd' => 0,
                'tyakujyunDispFlg' => true,
                'haraiGakuDispFlg' => true,
            ], RaceResultStatus::Confirmed],
            'display flag alone is unknown' => [[], ['tyakujyunDispFlg' => true], null],
        ];
    }

    private static function contextWithRace(bool $ended, string $result, string $refund): array
    {
        return [
            'selRaceNo' => 1,
            'flgRaceCancel' => false,
            'flgSectionCancel' => false,
            'C0201race' => [[
                'raceNo' => 1,
                'flgRaceEnd' => $ended,
                'rcvKekka' => $result,
                'rcvRefund' => $refund,
            ]],
        ];
    }
}
