<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\PrestartEvidence;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class ObservationProjector
{
    public const TIMING_VERSION = 'C1-RACECARD-PRESTART-TIMING-v2';

    /** Projects only race identity, schedule and riding style; never outcome fields. */
    public function project(string $source, string $raw): array
    {
        $decoder = new RawObservationDecoder;
        if ($source === 'JSJ017') {
            $data = $decoder->object($raw);
            $request = (array) ($data['reqprm'] ?? []);
            if (! in_array($data['resultCd'] ?? null, [0, '0'], true)
                || ($request['bkcd'] ?? null) !== ($data['keirinCd'] ?? null)
                || ($request['kday'] ?? null) !== ($data['kaisaihi'] ?? null)) {
                throw new RuntimeException('Raw response context invalid.');
            }
            $races = $this->rows($data['rInfo'] ?? null);
            $projected = [];
            foreach ($races as $index => $race) {
                $projected[] = $this->race($data['kaisaihi'] ?? null, $data['keirinCd'] ?? null,
                    $race['raceNo'] ?? null, $race, 'stTime', $race['sInfo'] ?? null,
                    'senNo', 'kyaku', '/rInfo/'.$index);
            }
        } elseif ($source === 'PJ0315') {
            $pc = $decoder->embedded($raw, 'PC0201');
            $page = $decoder->embedded($raw, 'PJ0315');
            $context = (array) ($pc['C0201data'] ?? []);
            $detail = (array) ($context['C0201racedtl'] ?? []);
            $projected = [$this->race($context['selKaisai'] ?? null, $context['selKjyoCd'] ?? null,
                $context['selRaceNo'] ?? null, $detail, 'aftStartTime', $page['sensyuTypeInfo'] ?? null,
                'sensyuRegistNo', 'kyakusitu', '/PJ0315/sensyuTypeInfo')];
            $summary = [];
            foreach ($this->rows($detail['C0201sensyu'] ?? null) as $entry) {
                $bike = $this->bike($entry['carNum'] ?? null);
                if (isset($summary[$bike])) {
                    throw new RuntimeException('Duplicate summary bike.');
                }
                $summary[$bike] = $this->registration($entry['numPlayer'] ?? null);
            }
            if ($this->identities($projected[0]['entries']) !== $this->sorted($summary)) {
                throw new RuntimeException('Raw entrant tables disagree.');
            }
            $projected[0]['schedule_pointer'] = '/PC0201/C0201data/C0201racedtl/aftStartTime';
        } else {
            throw new RuntimeException('Unsupported evidence source.');
        }
        $numbers = array_column($projected, 'race_number');
        if (count($numbers) !== count(array_unique($numbers))) {
            throw new RuntimeException('Duplicate raw race.');
        }

        return $projected;
    }

    public function classify(array $observations, array $targets, array $capture, array $source): array
    {
        $classified = [];
        foreach ($targets as $target) {
            $matching = array_values(array_filter($observations, fn (array $race): bool => $race['race_date'] === $target['race_date'] && $race['track_code'] === $target['track_code']
                && $race['race_number'] === $target['race_number']));
            $observation = count($matching) === 1 ? $matching[0] : null;
            $identity = $observation !== null && $this->identities($observation['entries']) === $this->identities($target['entries']);
            $fetched = $this->instant($capture['fetched_at']);
            $scheduled = null;
            $time = $observation['schedule']['value'] ?? null;
            if (is_string($time) && preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $time) === 1
                && $capture['schedule_timezone'] === 'Asia/Tokyo' && $capture['clock_evidence'] === 'SYNTHETIC_CLOCK_CONFIRMED') {
                $scheduled = DateTimeImmutable::createFromFormat('!Ymd H:i', $target['race_date'].' '.$time, new DateTimeZone('Asia/Tokyo'));
            }
            $timing = $fetched === null || $scheduled === null || $scheduled === false
                || $capture['clock_evidence'] !== 'SYNTHETIC_CLOCK_CONFIRMED'
                ? 'TIMING_UNKNOWN'
                : ($fetched < $scheduled ? 'PRESTART_EVIDENCE_CANDIDATE' : 'POSTSTART_CAPTURE');
            $timingReason = $timing === 'POSTSTART_CAPTURE'
                ? ($fetched == $scheduled ? 'AT_SCHEDULED_START' : 'AFTER_SCHEDULED_START') : $timing;
            $rawSaved = $this->instant($capture['raw_saved_at'] ?? null);
            $saveStatus = match (true) {
                $rawSaved === null, ! $scheduled instanceof DateTimeImmutable => 'RAW_SAVE_TIMING_UNKNOWN',
                $rawSaved < $scheduled => 'RAW_SAVED_BEFORE_SCHEDULED_START',
                $rawSaved == $scheduled => 'RAW_SAVED_AT_SCHEDULED_START',
                default => 'RAW_SAVED_AFTER_SCHEDULED_START',
            };
            $saveReason = match (true) {
                $rawSaved === null => 'MISSING_OR_INVALID_RAW_SAVED_AT',
                $capture['clock_evidence'] !== 'SYNTHETIC_CLOCK_CONFIRMED' => 'UNKNOWN_CLOCK_EVIDENCE',
                ! $scheduled instanceof DateTimeImmutable => 'UNKNOWN_SCHEDULE_TIME_OR_TIMEZONE',
                $rawSaved < $scheduled => 'BEFORE_SCHEDULED_START',
                $rawSaved == $scheduled => 'AT_SCHEDULED_START',
                default => 'AFTER_SCHEDULED_START',
            };
            $httpFailed = in_array('HTTP_3XX_REJECTED', $source['reasons'], true)
                || in_array('HTTP_RESPONSE_NOT_OK', $source['reasons'], true);
            $state = $httpFailed ? 'FETCH_FAILED' : ($source['status'] !== 'VERIFIED' ? 'VALIDATION_FAILED'
                : (! $identity ? 'IDENTITY_MISMATCH' : $timing));
            $classified[] = ['target' => $target, 'observation' => $observation,
                'identity_status' => $identity ? 'VERIFIED' : 'IDENTITY_MISMATCH',
                'timing_version' => self::TIMING_VERSION,
                'fetched_at' => $capture['fetched_at'], 'raw_saved_at' => $capture['raw_saved_at'] ?? null,
                'timing_status' => $timing, 'timing_reason' => $timingReason,
                'raw_save_timing_status' => $saveStatus, 'raw_save_timing_reason' => $saveReason,
                'observed_scheduled_start_at' => $scheduled instanceof DateTimeImmutable ? $scheduled->format(DATE_ATOM) : null,
                'state' => $state, 'prediction_use' => 'NOT_AUTHORIZED'];
        }

        return $classified;
    }

    private function race(mixed $date, mixed $track, mixed $number, array $race, string $timeKey,
        mixed $rows, string $registrationKey, string $styleKey, string $pointer): array
    {
        $entries = [];
        foreach ($this->rows($rows) as $index => $entry) {
            $style = $this->field($entry, $styleKey);
            $entries[] = ['bike_number' => $this->bike($entry['syaban'] ?? null),
                'external_player_id' => $this->registration($entry[$registrationKey] ?? null),
                'riding_style' => $style, 'attribute_status' => $this->attributeStatus($style),
                'attribute_pointer' => $pointer.($styleKey === 'kyaku' ? '/sInfo' : '').'/'.$index.'/'.$styleKey];
        }
        $identities = $this->identities($entries);
        $number = $this->integer($number, 1, 99);
        // Reuse target validation for dates, supported entrant counts and registration uniqueness.
        new RaceTarget(is_string($date) ? $date : '', is_string($track) ? $track : '', $number, $identities);

        return ['race_date' => $date, 'track_code' => $track, 'race_number' => $number,
            'schedule' => $this->field($race, $timeKey), 'schedule_pointer' => $pointer.'/'.$timeKey, 'entries' => $entries];
    }

    private function field(array $row, string $key): array
    {
        $present = array_key_exists($key, $row);
        $value = $present ? $row[$key] : null;

        return ['presence' => $present ? 'PRESENT' : 'MISSING',
            'type' => $present ? (is_object($value) ? 'object' : get_debug_type($value)) : 'missing', 'value' => $value];
    }

    private function attributeStatus(array $field): string
    {
        return match (true) {
            $field['presence'] === 'MISSING' => 'MISSING',
            $field['value'] === null => 'NULL',
            ! is_string($field['value']) => 'INVALID_TYPE',
            $field['value'] === '' => 'EMPTY',
            in_array($field['value'], ["\u{9003}", "\u{8FFD}", "\u{4E21}"], true) => 'KNOWN_VALUE',
            default => 'UNKNOWN',
        };
    }

    private function rows(mixed $rows): array
    {
        if (! is_array($rows) || ! array_is_list($rows) || $rows === []) {
            throw new RuntimeException('Nonempty raw list required.');
        }
        foreach ($rows as $index => $row) {
            if (! $row instanceof \stdClass) {
                throw new RuntimeException('Raw row object required.');
            }
            $rows[$index] = (array) $row;
        }

        return $rows;
    }

    private function identities(array $entries): array
    {
        $map = [];
        foreach ($entries as $entry) {
            $bike = $this->bike($entry['bike_number'] ?? null);
            if (isset($map[$bike])) {
                throw new RuntimeException('Duplicate raw bike.');
            }
            $map[$bike] = $this->registration($entry['external_player_id'] ?? null);
        }

        return $this->sorted($map);
    }

    private function sorted(array $map): array
    {
        ksort($map);

        return $map;
    }

    private function bike(mixed $bike): int
    {
        return $this->integer($bike, 1, 9);
    }

    private function integer(mixed $value, int $minimum, int $maximum): int
    {
        if ((! is_int($value) && ! is_string($value)) || preg_match('/^[0-9]+$/D', (string) $value) !== 1
            || (int) $value < $minimum || (int) $value > $maximum) {
            throw new RuntimeException('Invalid raw identity number.');
        }

        return (int) $value;
    }

    private function registration(mixed $value): string
    {
        if (! is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1) {
            throw new RuntimeException('Invalid raw registration.');
        }

        return $value;
    }

    private function instant(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:Z|[+-](?:0[0-9]|1[0-4]):[0-5][0-9])$/D', $value) !== 1) {
            return null;
        }
        $instant = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $value);
        $errors = DateTimeImmutable::getLastErrors();

        return $instant !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) ? $instant : null;
    }
}
