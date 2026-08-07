<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Statistics;

use App\Domain\Keirin\Statistics\Enums\HistoricalResultState;
use App\Domain\Keirin\Statistics\Support\HistoricalResultStateNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HistoricalResultStateNormalizerTest extends TestCase
{
    #[DataProvider('states')]
    public function test_it_normalizes_existing_result_states(string $source, HistoricalResultState $expected, bool $tied, bool $started): void
    {
        $result = (new HistoricalResultStateNormalizer)->normalize($source);

        $this->assertSame($expected, $result->state);
        $this->assertSame($tied, $result->tied);
        $this->assertSame($started, $result->state->started());
    }

    public static function states(): array
    {
        return [
            ['FINISHED', HistoricalResultState::NormalFinish, false, true],
            ['TIED', HistoricalResultState::NormalFinish, true, true],
            ['DISQUALIFIED', HistoricalResultState::Disqualified, false, true],
            ['CRASHED', HistoricalResultState::FallDnf, false, true],
            ['DID_NOT_FINISH', HistoricalResultState::OtherDnf, false, true],
            ['DID_NOT_START', HistoricalResultState::DidNotStart, false, false],
            ['WITHDRAWN', HistoricalResultState::Withdrawn, false, false],
            ['FUTURE_UNKNOWN', HistoricalResultState::UnknownAbnormal, false, true],
        ];
    }
}
