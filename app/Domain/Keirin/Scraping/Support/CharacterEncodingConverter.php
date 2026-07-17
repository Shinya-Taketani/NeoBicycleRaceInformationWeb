<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Support;

use App\Domain\Keirin\Scraping\Exceptions\CharacterEncodingConversionException;

final class CharacterEncodingConverter
{
    /**
     * @return array{0:string,1:string}
     */
    public function convertToUtf8(string $body, ?string $contentType = null): array
    {
        $encoding = $this->detectEncoding($body, $contentType);

        if (strtoupper($encoding) === 'UTF-8') {
            return [$body, $encoding];
        }

        $converted = @mb_convert_encoding($body, 'UTF-8', $encoding);

        if (! is_string($converted) || $converted === '') {
            throw new CharacterEncodingConversionException("Failed to convert response from {$encoding} to UTF-8.");
        }

        return [$converted, $encoding];
    }

    private function detectEncoding(string $body, ?string $contentType): string
    {
        if ($contentType !== null && preg_match('/charset=([a-zA-Z0-9_\-]+)/i', $contentType, $matches) === 1) {
            return $this->normalizeEncoding($matches[1]);
        }

        if (preg_match('/<meta[^>]+charset=["\']?([a-zA-Z0-9_\-]+)/iu', $body, $matches) === 1) {
            return $this->normalizeEncoding($matches[1]);
        }

        $detected = mb_detect_encoding($body, ['UTF-8', 'SJIS-win', 'CP932', 'Windows-31J', 'Shift_JIS'], true);

        return $detected !== false ? $this->normalizeEncoding($detected) : 'UTF-8';
    }

    private function normalizeEncoding(string $encoding): string
    {
        $upper = strtoupper($encoding);

        return match ($upper) {
            'SHIFT_JIS', 'SHIFT-JIS', 'SJIS', 'SJIS-WIN', 'WINDOWS-31J', 'CP932' => 'CP932',
            default => $encoding,
        };
    }
}
