<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Statistics;

use App\Domain\Keirin\Statistics\Enums\RaceStage;
use App\Domain\Keirin\Statistics\Support\RaceStageNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RaceStageNormalizerTest extends TestCase
{
    #[DataProvider('observedStages')]
    public function test_it_normalizes_only_explicit_observed_stage_terms(?string $raw, RaceStage $expected): void
    {
        $this->assertSame($expected, (new RaceStageNormalizer)->normalize($raw));
    }

    public static function observedStages(): array
    {
        return [
            ['Ａ級決勝', RaceStage::Final],
            ['Ｓ級準決勝', RaceStage::Semifinal],
            ['Ａ級チ準決', RaceStage::Semifinal],
            ['Ｓ級一予選', RaceStage::FirstQualifying],
            ['Ｓ級二予Ａ', RaceStage::SecondQualifying],
            ['Ａ級予選', RaceStage::Qualifying],
            ['Ａ級男予１', RaceStage::Qualifying],
            ['Ａ級一般', RaceStage::General],
            ['Ｓ級特一般', RaceStage::General],
            ['Ａ級選抜', RaceStage::Selection],
            ['Ｓ級初特選', RaceStage::SpecialSelection],
            ['Ｓ級順位決', RaceStage::PlacementRace],
            ['Ｓ級ＧＰ', RaceStage::Other],
            ['', RaceStage::Unknown],
            [null, RaceStage::Unknown],
        ];
    }
}
