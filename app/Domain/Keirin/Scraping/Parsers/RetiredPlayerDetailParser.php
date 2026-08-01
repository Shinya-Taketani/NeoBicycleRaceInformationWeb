<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Parsers;

use App\Domain\Keirin\Scraping\DTO\RetiredPlayerDetailDto;
use App\Domain\Keirin\Scraping\Exceptions\ParserException;
use App\Domain\Keirin\Scraping\Exceptions\RetiredPlayerProfileNotRetiredException;
use App\Domain\Keirin\Scraping\Support\GradeNormalizer;
use App\Domain\Keirin\Scraping\Support\HtmlTextNormalizer;
use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use DOMElement;
use DOMXPath;

class RetiredPlayerDetailParser
{
    private const REQUIRED_HEADERS = ['氏名', '府県', '年齢', '期別', '級班', '登録番号'];

    public function parse(string $html, string $sourceUrl, string $expectedExternalPlayerId): RetiredPlayerDetailDto
    {
        if (preg_match('/^\d{6}$/', $expectedExternalPlayerId) !== 1) {
            throw new ParserException('Expected retired player ID was invalid.');
        }

        $xpath = $this->xpath($html);
        $retiredOn = $this->retiredOn($xpath);
        [$profileTable, $headers, $values] = $this->profileRows($xpath);
        $valueByHeader = [];
        foreach ($headers as $index => $header) {
            $valueByHeader[$header] = $values[$index] ?? null;
        }

        $name = HtmlTextNormalizer::normalize($valueByHeader['氏名'] ?? null);
        $registrationNumber = HtmlTextNormalizer::normalize($valueByHeader['登録番号'] ?? null);
        if ($name === null) {
            throw new ParserException('Retired player name was missing.');
        }
        if ($registrationNumber === null) {
            throw new ParserException('Retired player registration number was missing.');
        }
        if (preg_match('/^\d{6}$/', $registrationNumber) !== 1) {
            throw new ParserException('Retired player registration number was invalid.');
        }
        if ($registrationNumber !== $expectedExternalPlayerId) {
            throw new ParserException("Retired player registration number {$registrationNumber} did not match expected ID {$expectedExternalPlayerId}.");
        }

        return new RetiredPlayerDetailDto(
            externalPlayerId: $expectedExternalPlayerId,
            registrationNumber: $registrationNumber,
            name: $name,
            prefecture: HtmlTextNormalizer::normalize($valueByHeader['府県'] ?? null),
            age: $this->age($valueByHeader['年齢'] ?? null),
            graduationPeriod: $this->graduationPeriod($valueByHeader['期別'] ?? null),
            grade: GradeNormalizer::normalize($valueByHeader['級班'] ?? null),
            retiredOn: $retiredOn,
            sourceUpdatedAt: $this->sourceUpdatedAt($xpath, $profileTable),
            sourceUrl: $sourceUrl,
        );
    }

    private function xpath(string $html): DOMXPath
    {
        if (trim($html) === '') {
            throw new ParserException('Retired player profile HTML was empty.');
        }

        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $dom->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if ($loaded !== true) {
            throw new ParserException('Retired player profile HTML could not be parsed.');
        }

        return new DOMXPath($dom);
    }

    /**
     * @return array{0:DOMElement,1:list<string>,2:list<string|null>}
     */
    private function profileRows(DOMXPath $xpath): array
    {
        $candidates = [];
        foreach ($xpath->query('//table') ?: [] as $table) {
            if (! $table instanceof DOMElement) {
                continue;
            }
            $rows = $this->directRows($xpath, $table);
            foreach ($rows as $rowIndex => $row) {
                if (count(array_intersect(self::REQUIRED_HEADERS, $row)) !== count(self::REQUIRED_HEADERS)) {
                    continue;
                }
                $candidates[] = [$table, $rows, $rowIndex];
                break;
            }
        }

        if ($candidates === []) {
            throw new ParserException('Retired player profile table was not found.');
        }
        if (count($candidates) !== 1) {
            throw new ParserException('Multiple retired player profile tables were found.');
        }

        [$table, $rows, $headerRowIndex] = $candidates[0];
        $headers = $rows[$headerRowIndex];
        foreach (self::REQUIRED_HEADERS as $requiredHeader) {
            if (count(array_keys($headers, $requiredHeader, true)) !== 1) {
                throw new ParserException("Retired player profile header {$requiredHeader} was duplicated.");
            }
        }

        $values = null;
        foreach (array_slice($rows, $headerRowIndex + 1) as $row) {
            if (array_filter($row, fn (?string $value): bool => $value !== null) !== []) {
                $values = $row;
                break;
            }
        }
        if (! is_array($values)) {
            throw new ParserException('Retired player profile data row was missing.');
        }

        return [$table, $headers, $values];
    }

    /**
     * @return list<list<string|null>>
     */
    private function directRows(DOMXPath $xpath, DOMElement $table): array
    {
        $rows = [];
        foreach ($xpath->query('./tr | ./thead/tr | ./tbody/tr | ./tfoot/tr', $table) ?: [] as $row) {
            $cells = [];
            foreach ($xpath->query('./th | ./td', $row) ?: [] as $cell) {
                $cells[] = HtmlTextNormalizer::normalize($cell->textContent);
            }
            $rows[] = $cells;
        }

        return $rows;
    }

    private function age(?string $value): ?int
    {
        $normalized = HtmlTextNormalizer::normalize($value);
        if ($normalized === null) {
            return null;
        }
        $normalized = mb_convert_kana($normalized, 'n', 'UTF-8');
        if (preg_match('/^(\d+)歳$/u', $normalized, $matches) !== 1) {
            throw new ParserException('Retired player age was invalid.');
        }

        return (int) $matches[1];
    }

    private function graduationPeriod(?string $value): ?string
    {
        $normalized = HtmlTextNormalizer::normalize($value);
        if ($normalized === null) {
            return null;
        }
        $normalized = mb_convert_kana($normalized, 'n', 'UTF-8');
        if (preg_match('/^(\d+)期$/u', $normalized, $matches) !== 1) {
            throw new ParserException('Retired player graduation period was invalid.');
        }

        return $matches[1];
    }

    private function retiredOn(DOMXPath $xpath): DateTimeImmutable
    {
        $dates = [];
        foreach ($this->normalizedElementTexts($xpath) as $text) {
            if (preg_match('/^本選手は、(\d{4})年(\d{2})月(\d{2})日に引退しました。$/u', $text, $matches) === 1) {
                $dates[] = "{$matches[1]}-{$matches[2]}-{$matches[3]}";
            }
        }
        $dates = array_values(array_unique($dates));
        if ($dates === []) {
            throw new RetiredPlayerProfileNotRetiredException('Retired player message was not found.');
        }
        if (count($dates) !== 1) {
            throw new ParserException('Multiple retired player dates were found.');
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dates[0]);
        if (! $date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $dates[0]) {
            throw new ParserException('Retired player date was invalid.');
        }

        return $date;
    }

    private function sourceUpdatedAt(DOMXPath $xpath, DOMElement $profileTable): ?DateTimeImmutable
    {
        $timestamps = [];
        foreach ($this->profileSectionElements($xpath, $profileTable) as $element) {
            $text = HtmlTextNormalizer::normalize($element->textContent);
            if ($text !== null
                && preg_match('/^(\d{4}\/\d{2}\/\d{2}\s+\d{2}:\d{2})\s*更新$/u', $text, $matches) === 1) {
                $timestamps[$matches[1]] = true;
            }
        }
        if ($timestamps === []) {
            return null;
        }

        $timezone = new DateTimeZone('Asia/Tokyo');
        $updatedTimestamps = [];
        foreach (array_keys($timestamps) as $timestamp) {
            $updatedAt = DateTimeImmutable::createFromFormat('!Y/m/d H:i', $timestamp, $timezone);
            if (! $updatedAt instanceof DateTimeImmutable || $updatedAt->format('Y/m/d H:i') !== $timestamp) {
                throw new ParserException('Retired player profile update timestamp was invalid.');
            }
            $updatedTimestamps[] = $updatedAt;
        }
        if (count($updatedTimestamps) !== 1) {
            throw new ParserException('Multiple retired player profile update timestamps were found.');
        }

        return $updatedTimestamps[0];
    }

    /**
     * @return list<DOMElement>
     */
    private function profileSectionElements(DOMXPath $xpath, DOMElement $profileTable): array
    {
        $branch = $profileTable;
        while ($branch->parentNode instanceof DOMElement) {
            for ($sibling = $branch->previousElementSibling; $sibling instanceof DOMElement; $sibling = $sibling->previousElementSibling) {
                if (! $this->hasClass($sibling, 'midasi1_fsz')) {
                    continue;
                }
                if (HtmlTextNormalizer::normalize($sibling->textContent) !== 'プロフィール') {
                    throw new ParserException('Retired player profile section heading was not found.');
                }

                $elements = [];
                for ($sectionNode = $sibling; $sectionNode instanceof DOMElement; $sectionNode = $sectionNode->nextElementSibling) {
                    $elements[] = $sectionNode;
                    foreach ($xpath->query('.//*', $sectionNode) ?: [] as $descendant) {
                        if ($descendant instanceof DOMElement) {
                            $elements[] = $descendant;
                        }
                    }
                    if ($sectionNode->isSameNode($branch)) {
                        return $elements;
                    }
                }
            }

            $branch = $branch->parentNode;
        }

        throw new ParserException('Retired player profile section heading was not found.');
    }

    private function hasClass(DOMElement $element, string $class): bool
    {
        return in_array($class, preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [], true);
    }

    /**
     * @return list<string>
     */
    private function normalizedElementTexts(DOMXPath $xpath): array
    {
        $texts = [];
        foreach ($xpath->query('//*') ?: [] as $element) {
            $text = HtmlTextNormalizer::normalize($element->textContent);
            if ($text !== null) {
                $texts[] = $text;
            }
        }

        return $texts;
    }
}
