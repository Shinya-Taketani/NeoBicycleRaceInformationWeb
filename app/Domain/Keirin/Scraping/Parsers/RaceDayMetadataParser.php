<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Parsers;

use App\Domain\Keirin\Scraping\DTO\RaceDayMetadataPageDto;
use App\Domain\Keirin\Scraping\DTO\RaceDayParameterDto;
use App\Domain\Keirin\Scraping\DTO\RaceParameterDto;
use App\Domain\Keirin\Scraping\Exceptions\ParserException;
use App\Domain\Keirin\Scraping\Support\HtmlTextNormalizer;
use DateTimeImmutable;
use JsonException;

class RaceDayMetadataParser
{
    public function __construct(private readonly EmbeddedJsonExtractor $embeddedJson) {}

    public function parse(string $source): RaceDayMetadataPageDto
    {
        $root = $this->root($source);
        if (array_key_exists('resultCd', $root) && (int) $root['resultCd'] !== 0) {
            throw new ParserException('JSJ001 result code was invalid.');
        }
        $data = $root['C0201data'] ?? null;
        if (! is_array($data)) {
            throw new ParserException('JSJ001 C0201data was missing.');
        }

        $selectedDate = $this->requiredDigits($data, 'selKaisai', 8);
        $trackCode = $this->requiredDigits($data, 'selKjyoCd');
        $selectedRaceNumber = $this->requiredInt($data, 'selRaceNo');
        $selected = DateTimeImmutable::createFromFormat('!Ymd', $selectedDate);
        if (! $selected instanceof DateTimeImmutable || $selected->format('Ymd') !== $selectedDate) {
            throw new ParserException('JSJ001 selected date was invalid.');
        }

        $rawDays = $data['C0201kaisai'] ?? null;
        $rawRaces = $data['C0201race'] ?? null;
        if (! is_array($rawDays) || $rawDays === [] || ! is_array($rawRaces) || $rawRaces === []) {
            throw new ParserException('JSJ001 day or race metadata was missing.');
        }

        $days = [];
        $seenDates = [];
        foreach ($rawDays as $rawDay) {
            if (! is_array($rawDay)) {
                throw new ParserException('JSJ001 day metadata was invalid.');
            }
            $date = $this->resolveDayDate($selected, $this->requiredString($rawDay, 'txtEventDate'));
            if (isset($seenDates[$date])) {
                throw new ParserException("JSJ001 contained duplicate race date {$date}.");
            }
            $seenDates[$date] = true;
            $days[] = new RaceDayParameterDto(
                raceDate: $date,
                dayLabel: HtmlTextNormalizer::normalize($this->nullableString($rawDay, 'txtDaily')),
                encryptedParameter: $this->requiredString($rawDay, 'encParaK'),
            );
        }

        $races = [];
        foreach ($rawRaces as $rawRace) {
            if (! is_array($rawRace)) {
                throw new ParserException('JSJ001 race metadata was invalid.');
            }
            $races[] = new RaceParameterDto(
                encryptedParameter: $this->requiredString($rawRace, 'encParaR'),
                raceEnded: $this->boolean($rawRace['flgRaceEnd'] ?? false),
                resultAvailable: (string) ($rawRace['rcvKekka'] ?? '') === '1',
            );
        }

        return new RaceDayMetadataPageDto(
            selectedDate: $selectedDate,
            trackCode: $trackCode,
            selectedRaceNumber: $selectedRaceNumber,
            meetingName: HtmlTextNormalizer::normalize($this->nullableString($data, 'raceName')),
            trackName: HtmlTextNormalizer::normalize($this->nullableString($data, 'joName')),
            grade: HtmlTextNormalizer::normalize($this->nullableString($data, 'imgGradeAlt')),
            days: $days,
            races: $races,
        );
    }

    private function root(string $source): array
    {
        try {
            $decoded = json_decode($source, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                return $decoded;
            }
        } catch (JsonException) {
        }

        return $this->embeddedJson->extract($source, 'PC0201');
    }

    private function resolveDayDate(DateTimeImmutable $selected, string $monthDay): string
    {
        if (preg_match('#^(\d{2})/(\d{2})$#', $monthDay, $match) !== 1) {
            throw new ParserException("JSJ001 race date {$monthDay} was invalid.");
        }

        $candidate = DateTimeImmutable::createFromFormat('!Y-m-d', $selected->format('Y').'-'.$match[1].'-'.$match[2]);
        if (! $candidate instanceof DateTimeImmutable || $candidate->format('m/d') !== $monthDay) {
            throw new ParserException("JSJ001 race date {$monthDay} was invalid.");
        }
        $difference = (int) $selected->diff($candidate)->format('%r%a');
        if ($difference > 180) {
            $candidate = $candidate->modify('-1 year');
        } elseif ($difference < -180) {
            $candidate = $candidate->modify('+1 year');
        }

        return $candidate->format('Ymd');
    }

    private function requiredString(array $data, string $key): string
    {
        $value = $this->nullableString($data, $key);
        if ($value === null || trim($value) === '') {
            throw new ParserException("JSJ001 {$key} was missing.");
        }

        return $value;
    }

    private function nullableString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) || is_int($value) ? (string) $value : null;
    }

    private function requiredDigits(array $data, string $key, ?int $length = null): string
    {
        $value = $this->requiredString($data, $key);
        if (preg_match('/^\d+$/', $value) !== 1 || ($length !== null && strlen($value) !== $length)) {
            throw new ParserException("JSJ001 {$key} was invalid.");
        }

        return $value;
    }

    private function requiredInt(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (! is_int($value) && (! is_string($value) || preg_match('/^\d+$/', $value) !== 1)) {
            throw new ParserException("JSJ001 {$key} was invalid.");
        }

        return (int) $value;
    }

    private function boolean(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1';
    }
}
