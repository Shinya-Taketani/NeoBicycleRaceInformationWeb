<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Statistics;

use App\Domain\Keirin\Statistics\Support\SchoolProfileParser;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SchoolProfileParserTest extends TestCase
{
    public function test_saved_real_profile_projects_only_school_and_debut_fields(): void
    {
        $path = dirname(__DIR__, 4).'/Fixtures/Keirin/actual/player_detail_014934.html';
        $profile = (new SchoolProfileParser)->parse(file_get_contents($path), '014934');
        $this->assertSame(13, $profile->schoolRank);
        $this->assertSame(103, $profile->graduationPeriod);
        $this->assertSame(11.48, $profile->school200mSeconds);
        $this->assertSame('2013-07-02', $profile->debutDate?->format('Y-m-d'));
        $this->assertArrayNotHasKey('current_score', $profile->originalFields);
        $this->assertArrayNotHasKey('lifetime_total', $profile->originalFields);
    }

    public function test_wrong_player_is_rejected_not_relabelled(): void
    {
        $this->expectException(RuntimeException::class);
        (new SchoolProfileParser)->parse(file_get_contents(dirname(__DIR__, 4).'/Fixtures/Keirin/actual/player_detail_014934.html'), '000001');
    }

    public function test_missing_school_section_is_not_zero_rank_or_zero_time(): void
    {
        $html = file_get_contents(dirname(__DIR__, 4).'/Fixtures/Keirin/actual/player_detail_014934.html');
        // This altered heading is an artificial missing-input case, never a real source record.
        $profile = (new SchoolProfileParser)->parse(str_replace('■競輪学校時代', '■ARTIFICIAL_MISSING', $html), '014934');
        $this->assertNull($profile->schoolRank);
        $this->assertNull($profile->school200mSeconds);
    }

    public function test_retired_header_uses_existing_identity_parser_without_projecting_retirement(): void
    {
        $html = file_get_contents(dirname(__DIR__, 4).'/Fixtures/Keirin/synthetic/player-detail-retired.html');
        // Match the identity marker observed in the saved retired-page format; do not edit the fixture.
        $html = str_replace('<td>012345</td>', '<td id="PlayerNo">012345</td>', $html);
        $profile = (new SchoolProfileParser)->parse($html, '012345');
        $this->assertSame('012345', $profile->externalPlayerId);
        $this->assertNull($profile->schoolRank);
        $this->assertNull($profile->school200mSeconds);
        $this->assertArrayNotHasKey('retired_on', $profile->originalFields);
    }

    public function test_artificial_girls_profile_is_skipped_not_treated_as_mens_potential(): void
    {
        $html = file_get_contents(dirname(__DIR__, 4).'/Fixtures/Keirin/actual/player_detail_014934.html');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SKIPPED_UNSUPPORTED_CATEGORY');
        (new SchoolProfileParser)->parse(str_replace('<td class="al-c">男</td>', '<td class="al-c">女</td>', $html), '014934');
    }
}
