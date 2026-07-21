<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Parsers;

use App\Domain\Keirin\Scraping\DTO\RaceDayMetadataPageDto;
use App\Domain\Keirin\Scraping\DTO\RaceDayParameterDto;
use App\Domain\Keirin\Scraping\DTO\RaceParameterDto;
use App\Domain\Keirin\Scraping\Exceptions\ParserException;
use App\Domain\Keirin\Scraping\Exceptions\RaceDayMetadataUnavailableException;
use App\Domain\Keirin\Scraping\Support\HtmlTextNormalizer;
use DateTimeImmutable;
use JsonException;

class RaceDayMetadataParser
{
    public function __construct(private readonly EmbeddedJsonExtractor $embeddedJson) {}

    public function parse(string $source): RaceDayMetadataPageDto
    {
        $root = $this->root($source);
        if (array_key_exists('resultCd', $root) && ! $this->isZero($root['resultCd'])) {
            throw new ParserException('JSJ001 result code was invalid.');
        }
        $data = $root['C0201data'] ?? null;
        if (! is_array($data)) {
            throw new ParserException('JSJ001 C0201data was missing.');
        }

        $this->throwIfMeetingUnavailable($root, $data);

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
            $decodedObject = json_decode($source, false, 512, JSON_THROW_ON_ERROR);
            $decoded = json_decode($source, true, 512, JSON_THROW_ON_ERROR);
            if (is_object($decodedObject) && is_array($decoded)) {
                return $decoded;
            }
        } catch (JsonException) {
        }

        return $this->embeddedJson->extract($source, 'PC0201');
    }

    /**
     * @param  array<string, mixed>  $root
     * @param  array<string, mixed>  $data
     */
    private function throwIfMeetingUnavailable(array $root, array $data): void
    {
        $message = HtmlTextNormalizer::normalize($this->nullableString($data, 'hhMessage'));
        $cancellationSignalled = $this->boolean($data['flgRaceCancel'] ?? false)
            || $this->boolean($data['flgSectionCancel'] ?? false)
            || ($message !== null && str_contains($message, '中止'));
        if (! $cancellationSignalled) {
            return;
        }

        $evidence = $this->cancelledMeetingEvidence($root, $data, $message);
        if ($evidence === null) {
            throw new ParserException('PJ0301 race meeting cancellation metadata was invalid.');
        }

        throw new RaceDayMetadataUnavailableException(
            reason: RaceDayMetadataUnavailableException::REASON_RACE_MEETING_CANCELLED,
            message: $message,
            evidence: $evidence,
        );
    }

    /**
     * @param  array<string, mixed>  $root
     * @param  array<string, mixed>  $data
     * @return null|array<string, mixed>
     */
    private function cancelledMeetingEvidence(array $root, array $data, ?string $message): ?array
    {
        $selectedDate = $this->digitText($data['selKaisai'] ?? null, 8);
        $trackCode = $this->digitText($data['selKjyoCd'] ?? null);
        $rawDays = $data['C0201kaisai'] ?? null;
        $rawRaces = $data['C0201race'] ?? null;
        if (! $this->isZero($root['resultCd'] ?? null)
            || ! $this->boolean($data['flgRaceCancel'] ?? false)
            || ! $this->isDisabled($data['flgSectionCancel'] ?? null)
            || ! in_array($message, ['中止となりました。', '中止となりました'], true)
            || ! $this->isZero($data['selRaceNo'] ?? null)
            || ! $this->isZero($data['cntRace'] ?? null)
            || ! is_array($rawDays)
            || $rawDays === []
            || (array_key_exists('C0201race', $data) && $rawRaces !== null && $rawRaces !== [])
            || $selectedDate === null
            || $trackCode === null) {
            return null;
        }

        $selected = DateTimeImmutable::createFromFormat('!Ymd', $selectedDate);
        if (! $selected instanceof DateTimeImmutable || $selected->format('Ymd') !== $selectedDate) {
            return null;
        }

        $dates = [];
        foreach ($rawDays as $rawDay) {
            if (! is_array($rawDay)) {
                return null;
            }
            $monthDay = $this->nullableString($rawDay, 'txtEventDate');
            $encryptedParameter = $rawDay['encParaK'] ?? null;
            if ($monthDay === null
                || preg_match('#^\d{2}/\d{2}$#', $monthDay) !== 1
                || ! is_string($encryptedParameter)
                || trim($encryptedParameter) === '') {
                return null;
            }
            try {
                $dates[] = $this->resolveDayDate($selected, $monthDay);
            } catch (ParserException) {
                return null;
            }
        }

        return [
            'resultCd' => $root['resultCd'],
            'selKaisai' => $selectedDate,
            'selKjyoCd' => $trackCode,
            'selRaceNo' => $data['selRaceNo'],
            'hhMessage' => $message,
            'flgRaceCancel' => $data['flgRaceCancel'],
            'flgSectionCancel' => $data['flgSectionCancel'],
            'cntRace' => $data['cntRace'],
            'raceDates' => $dates,
            'raceDayCount' => count($dates),
            'raceInfoState' => $this->raceInfoState($data),
        ];
    }

    /** @param array<string, mixed> $data */
    private function raceInfoState(array $data): string
    {
        if (! array_key_exists('C0201race', $data)) {
            return 'missing';
        }
        if ($data['C0201race'] === null) {
            return 'null';
        }
        if ($data['C0201race'] === []) {
            return 'empty_array';
        }

        return is_array($data['C0201race']) ? 'populated_array' : 'invalid_type';
    }

    private function digitText(mixed $value, ?int $length = null): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }
        $text = (string) $value;

        return preg_match('/^\d+$/', $text) === 1
            && ($length === null || strlen($text) === $length)
            ? $text
            : null;
    }

    private function isZero(mixed $value): bool
    {
        return $value === 0 || $value === '0';
    }

    private function isDisabled(mixed $value): bool
    {
        return in_array($value, [false, 0, '0'], true);
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
