<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Scraping;

use App\Domain\Keirin\Scraping\Support\CharacterEncodingConverter;
use PHPUnit\Framework\TestCase;

class CharacterEncodingConverterTest extends TestCase
{
    public function test_it_keeps_utf8_html(): void
    {
        [$html, $encoding] = (new CharacterEncodingConverter)->convertToUtf8('<html><meta charset="utf-8">競輪</html>', 'text/html; charset=UTF-8');

        $this->assertSame('<html><meta charset="utf-8">競輪</html>', $html);
        $this->assertSame('UTF-8', $encoding);
    }

    public function test_it_converts_cp932_html(): void
    {
        $source = mb_convert_encoding('<html><meta charset="Shift_JIS">競輪</html>', 'CP932', 'UTF-8');

        [$html, $encoding] = (new CharacterEncodingConverter)->convertToUtf8($source, 'text/html; charset=Shift_JIS');

        $this->assertStringContainsString('競輪', $html);
        $this->assertSame('CP932', $encoding);
    }
}
