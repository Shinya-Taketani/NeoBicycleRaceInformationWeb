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
        if ($body === '') {
            throw new CharacterEncodingConversionException('Response body is empty; character encoding cannot be detected.');
        }

        $encoding = $this->detectEncoding($body, $contentType);

        if (strtoupper($encoding) === 'UTF-8') {
            if (! mb_check_encoding($body, 'UTF-8')) {
                throw new CharacterEncodingConversionException('Response body is not valid UTF-8.');
            }

            return [$body, $encoding];
        }

        $converted = @mb_convert_encoding($body, 'UTF-8', $encoding);

        if (! is_string($converted) || $converted === '' || ! mb_check_encoding($converted, 'UTF-8')) {
            throw new CharacterEncodingConversionException("Failed to convert response from {$encoding} to UTF-8.");
        }

        return [$converted, $encoding];
    }

    private function detectEncoding(string $body, ?string $contentType): string
    {
        if ($contentType !== null && preg_match('/charset=([a-zA-Z0-9_\-]+)/i', $contentType, $matches) === 1) {
            return $this->validatedEncoding($body, $this->normalizeEncoding($matches[1]));
        }

        $asciiHead = substr($body, 0, 4096);
        if (preg_match('/<meta[^>]+charset=["\']?([a-zA-Z0-9_\-]+)/i', $asciiHead, $matches) === 1) {
            return $this->validatedEncoding($body, $this->normalizeEncoding($matches[1]));
        }

        $detected = mb_detect_encoding($body, ['UTF-8', 'SJIS-win', 'CP932', 'Windows-31J', 'Shift_JIS'], true);

        if ($detected !== false) {
            return $this->validatedEncoding($body, $this->normalizeEncoding($detected));
        }

        if (mb_check_encoding($body, 'UTF-8')) {
            return 'UTF-8';
        }

        throw new CharacterEncodingConversionException('Unable to detect response character encoding.');
    }

    private function normalizeEncoding(string $encoding): string
    {
        $upper = strtoupper($encoding);

        return match ($upper) {
            'SHIFT_JIS', 'SHIFT-JIS', 'SJIS', 'SJIS-WIN', 'WINDOWS-31J', 'CP932' => 'CP932',
            default => $encoding,
        };
    }

    private function validatedEncoding(string $body, string $encoding): string
    {
        if (strtoupper($encoding) === 'UTF-8' && ! mb_check_encoding($body, 'UTF-8')) {
            throw new CharacterEncodingConversionException('Declared charset is UTF-8, but response bytes are invalid UTF-8.');
        }

        return $encoding;
    }
}
