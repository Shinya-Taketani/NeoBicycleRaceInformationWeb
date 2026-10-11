<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Scraping;

use App\Domain\Keirin\Scraping\PrestartEvidence\CaptureStore;
use App\Domain\Keirin\Scraping\PrestartEvidence\CaptureVerifier;
use App\Domain\Keirin\Scraping\PrestartEvidence\EvidenceFiles;
use App\Domain\Keirin\Scraping\PrestartEvidence\ObservationProjector;
use App\Domain\Keirin\Scraping\PrestartEvidence\RaceTarget;
use App\Domain\Keirin\Scraping\PrestartEvidence\SyntheticCapture;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\CompositionResultTemporaryDirectory as Directory;
use Tests\Support\PrestartSymlinkFixtureDirectory as LinkDirectory;

/** All bytes, targets, URLs, clocks and registrations below are synthetic; no real Raw or DB is read. */
final class PrestartEvidenceTest extends TestCase
{
    /** @var list<Directory> */
    private array $owned = [];

    /** @var list<LinkDirectory> */
    private array $linkFixtures = [];

    protected function tearDown(): void
    {
        try {
            $reason = $this->status()->isSuccess() ? 'TEST_COMPLETE' : 'TEST_FAILURE';
            // Retire links first while their separately owned artificial targets still exist.
            foreach ($this->linkFixtures as $directory) {
                if (! $directory->retired()) {
                    $directory->retire($directory->path(), $reason);
                }
            }
            foreach ($this->owned as $directory) {
                $directory->retire($directory->path(), $reason);
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_synthetic_jsj017_shares_one_raw_across_multiple_races_and_verifies_offline(): void
    {
        $saved = $this->store()->save($this->input());
        $verified = (new CaptureVerifier)->verify($saved->path, $saved->manifestSha256);

        $this->assertSame($saved->validation, $verified);
        $this->assertSame('COMPLETE', $verified['publication_state']);
        $this->assertCount(2, $verified['races']);
        $this->assertCount(1, glob($saved->path.'/*.bin'));
        foreach ($verified['races'] as $race) {
            $this->assertSame('PRESTART_EVIDENCE_CANDIDATE', $race['state']);
            $this->assertSame('NOT_AUTHORIZED', $race['prediction_use']);
            $this->assertSame('000001', $race['observation']['entries'][0]['external_player_id']);
            $this->assertSame([1, 2, 3, 4, 6], array_column($race['observation']['entries'], 'bike_number'));
        }
        $this->assertFalse($verified['historical_as_of_available']);
        $this->assertSame(0600, fileperms($saved->path.'/raw.bin') & 0777);
        $this->assertSame(0700, fileperms($saved->path) & 0777);
    }

    public function test_synthetic_pj0315_checks_both_entrant_tables_and_preserves_field_pointers(): void
    {
        $saved = $this->store('PJ0315')->save($this->input(source: 'PJ0315'));
        $report = (new CaptureVerifier)->verify($saved->path, $saved->manifestSha256);

        $this->assertSame('COMPLETE', $report['publication_state']);
        $this->assertCount(1, $report['races']);
        $race = $report['races'][0];
        $this->assertSame('PRESTART_EVIDENCE_CANDIDATE', $race['state']);
        $this->assertSame('/PC0201/C0201data/C0201racedtl/aftStartTime', $race['observation']['schedule_pointer']);
        $this->assertSame('/PJ0315/sensyuTypeInfo/0/kyakusitu', $race['observation']['entries'][0]['attribute_pointer']);
    }

    public static function timingCases(): array
    {
        return [
            'before' => ['2025-12-31T09:59:59+09:00', 'Asia/Tokyo', 'SYNTHETIC_CLOCK_CONFIRMED', 'PRESTART_EVIDENCE_CANDIDATE', 'PRESTART_EVIDENCE_CANDIDATE'],
            'equal' => ['2025-12-31T10:00:00+09:00', 'Asia/Tokyo', 'SYNTHETIC_CLOCK_CONFIRMED', 'POSTSTART_CAPTURE', 'AT_SCHEDULED_START'],
            'after' => ['2025-12-31T10:00:01+09:00', 'Asia/Tokyo', 'SYNTHETIC_CLOCK_CONFIRMED', 'POSTSTART_CAPTURE', 'AFTER_SCHEDULED_START'],
            'same instant UTC' => ['2025-12-31T00:59:59Z', 'Asia/Tokyo', 'SYNTHETIC_CLOCK_CONFIRMED', 'PRESTART_EVIDENCE_CANDIDATE', 'PRESTART_EVIDENCE_CANDIDATE'],
            'missing fetch' => [null, 'Asia/Tokyo', 'SYNTHETIC_CLOCK_CONFIRMED', 'TIMING_UNKNOWN', 'TIMING_UNKNOWN'],
            'fetch without offset' => ['2025-12-31T09:59:59', 'Asia/Tokyo', 'SYNTHETIC_CLOCK_CONFIRMED', 'TIMING_UNKNOWN', 'TIMING_UNKNOWN'],
            'invalid fetch date' => ['2025-02-30T09:59:59+09:00', 'Asia/Tokyo', 'SYNTHETIC_CLOCK_CONFIRMED', 'TIMING_UNKNOWN', 'TIMING_UNKNOWN'],
            'invalid UTC offset' => ['2025-12-31T09:59:59+99:99', 'Asia/Tokyo', 'SYNTHETIC_CLOCK_CONFIRMED', 'TIMING_UNKNOWN', 'TIMING_UNKNOWN'],
            'unknown timezone' => ['2025-12-31T09:59:59+09:00', null, 'SYNTHETIC_CLOCK_CONFIRMED', 'TIMING_UNKNOWN', 'TIMING_UNKNOWN'],
            'unknown clock' => ['2025-12-31T09:59:59+09:00', 'Asia/Tokyo', null, 'TIMING_UNKNOWN', 'TIMING_UNKNOWN'],
        ];
    }

    #[DataProvider('timingCases')]
    public function test_timing_is_strict_and_unknown_values_never_become_candidates(?string $fetched, ?string $timezone,
        ?string $clock, string $state, string $reason): void
    {
        $saved = $this->store()->save($this->input(overrides: ['fetchedAt' => $fetched, 'scheduleTimezone' => $timezone, 'clockEvidence' => $clock]));
        $report = (new CaptureVerifier)->verify($saved->path, $saved->manifestSha256);
        $this->assertSame($state, $report['races'][0]['state']);
        $this->assertSame($reason, $report['races'][0]['timing_reason']);
        $this->assertSame('NOT_AUTHORIZED', $report['prediction_use']);
    }

    public static function savedTimingCases(): array
    {
        $before = '2025-12-31T09:59:59+09:00';
        $after = '2025-12-31T10:00:02+09:00';

        return [
            'both before' => [$before, $before, 'Asia/Tokyo', 'SYNTHETIC_CLOCK_CONFIRMED', 'PRESTART_EVIDENCE_CANDIDATE', 'RAW_SAVED_BEFORE_SCHEDULED_START'],
            'received before saved at start' => [$before, '2025-12-31T10:00:00+09:00', 'Asia/Tokyo', 'SYNTHETIC_CLOCK_CONFIRMED', 'PRESTART_EVIDENCE_CANDIDATE', 'RAW_SAVED_AT_SCHEDULED_START'],
            'received before saved after' => [$before, $after, 'Asia/Tokyo', 'SYNTHETIC_CLOCK_CONFIRMED', 'PRESTART_EVIDENCE_CANDIDATE', 'RAW_SAVED_AFTER_SCHEDULED_START'],
            'both after' => [$after, $after, 'Asia/Tokyo', 'SYNTHETIC_CLOCK_CONFIRMED', 'POSTSTART_CAPTURE', 'RAW_SAVED_AFTER_SCHEDULED_START'],
            'null saved time' => [$before, null, 'Asia/Tokyo', 'SYNTHETIC_CLOCK_CONFIRMED', 'PRESTART_EVIDENCE_CANDIDATE', 'RAW_SAVE_TIMING_UNKNOWN'],
            'invalid saved time' => [$before, 'invalid', 'Asia/Tokyo', 'SYNTHETIC_CLOCK_CONFIRMED', 'PRESTART_EVIDENCE_CANDIDATE', 'RAW_SAVE_TIMING_UNKNOWN'],
            'invalid saved date' => [$before, '2025-02-30T09:59:59+09:00', 'Asia/Tokyo', 'SYNTHETIC_CLOCK_CONFIRMED', 'PRESTART_EVIDENCE_CANDIDATE', 'RAW_SAVE_TIMING_UNKNOWN'],
            'saved offset absent' => [$before, '2025-12-31T09:59:59', 'Asia/Tokyo', 'SYNTHETIC_CLOCK_CONFIRMED', 'PRESTART_EVIDENCE_CANDIDATE', 'RAW_SAVE_TIMING_UNKNOWN'],
            'saved invalid type' => [$before, 123, 'Asia/Tokyo', 'SYNTHETIC_CLOCK_CONFIRMED', 'PRESTART_EVIDENCE_CANDIDATE', 'RAW_SAVE_TIMING_UNKNOWN'],
            'unknown receipt saved after' => [null, $after, 'Asia/Tokyo', 'SYNTHETIC_CLOCK_CONFIRMED', 'TIMING_UNKNOWN', 'RAW_SAVED_AFTER_SCHEDULED_START'],
            'same instant UTC' => ['2025-12-31T01:00:00Z', '2025-12-31T01:00:00Z', 'Asia/Tokyo', 'SYNTHETIC_CLOCK_CONFIRMED', 'POSTSTART_CAPTURE', 'RAW_SAVED_AT_SCHEDULED_START'],
            'timezone unknown' => [$before, $after, null, 'SYNTHETIC_CLOCK_CONFIRMED', 'TIMING_UNKNOWN', 'RAW_SAVE_TIMING_UNKNOWN'],
            'clock unknown' => [$before, $after, 'Asia/Tokyo', null, 'TIMING_UNKNOWN', 'RAW_SAVE_TIMING_UNKNOWN'],
        ];
    }

    #[DataProvider('savedTimingCases')]
    public function test_receipt_and_raw_save_timing_are_independent(?string $fetched, mixed $saved, ?string $timezone,
        ?string $clock, string $receiptStatus, string $saveStatus): void
    {
        $projector = new ObservationProjector;
        $capture = ['fetched_at' => $fetched, 'raw_saved_at' => $saved, 'schedule_timezone' => $timezone, 'clock_evidence' => $clock];
        $race = $projector->classify($projector->project('JSJ017', EvidenceFiles::json($this->jsj017())),
            [$this->input()->targets[0]->toArray()], $capture, ['status' => 'VERIFIED', 'reasons' => []])[0];
        $this->assertSame($receiptStatus, $race['timing_status']);
        $this->assertSame($receiptStatus, $race['state']);
        $this->assertSame($saveStatus, $race['raw_save_timing_status']);
        $this->assertSame($receiptStatus === 'POSTSTART_CAPTURE'
            ? ($fetched === '2025-12-31T01:00:00Z' ? 'AT_SCHEDULED_START' : 'AFTER_SCHEDULED_START')
            : $receiptStatus, $race['timing_reason']);
        $this->assertSame(match ($saveStatus) {
            'RAW_SAVED_BEFORE_SCHEDULED_START' => 'BEFORE_SCHEDULED_START',
            'RAW_SAVED_AT_SCHEDULED_START' => 'AT_SCHEDULED_START',
            'RAW_SAVED_AFTER_SCHEDULED_START' => 'AFTER_SCHEDULED_START',
            default => $clock === null ? 'UNKNOWN_CLOCK_EVIDENCE'
                : ($timezone === null ? 'UNKNOWN_SCHEDULE_TIME_OR_TIMEZONE' : 'MISSING_OR_INVALID_RAW_SAVED_AT'),
        }, $race['raw_save_timing_reason']);
        $this->assertSame(ObservationProjector::TIMING_VERSION, $race['timing_version']);
        $this->assertSame($fetched, $race['fetched_at']);
        $this->assertSame($saved, $race['raw_saved_at']);
        $this->assertSame($timezone !== null && $clock !== null ? '2025-12-31T10:00:00+09:00' : null, $race['observed_scheduled_start_at']);
        $this->assertSame('NOT_AUTHORIZED', $race['prediction_use']);
    }

    public function test_equivalent_offsets_give_identical_receipt_and_save_classifications(): void
    {
        $projector = new ObservationProjector;
        $observations = $projector->project('JSJ017', EvidenceFiles::json($this->jsj017()));
        $targets = [$this->input()->targets[0]->toArray()];
        $results = [];
        foreach (['2025-12-31T10:00:00+09:00', '2025-12-31T01:00:00Z', '2025-12-30T20:00:00-05:00'] as $time) {
            $race = $projector->classify($observations, $targets,
                ['fetched_at' => $time, 'raw_saved_at' => $time, 'schedule_timezone' => 'Asia/Tokyo',
                    'clock_evidence' => 'SYNTHETIC_CLOCK_CONFIRMED'], ['status' => 'VERIFIED', 'reasons' => []])[0];
            $results[] = array_intersect_key($race, array_flip(['timing_status', 'timing_reason', 'raw_save_timing_status', 'raw_save_timing_reason', 'state']));
        }
        $this->assertSame($results[0], $results[1]);
        $this->assertSame($results[0], $results[2]);
    }

    public function test_actual_save_time_is_verified_without_overwriting_receipt_candidate_or_bundle(): void
    {
        $beforeSave = new \DateTimeImmutable;
        $saved = $this->store()->save($this->input());
        $afterSave = new \DateTimeImmutable;
        $original = $this->snapshot($saved->path);
        $report = (new CaptureVerifier)->verify($saved->path, $saved->manifestSha256);
        $capture = EvidenceFiles::object(EvidenceFiles::read($saved->path, 'capture.json'));
        $saveInstant = new \DateTimeImmutable($capture['raw_saved_at']);
        // Capture timestamps have second precision; bound them without supplying a fake save clock.
        $this->assertGreaterThanOrEqual($beforeSave->getTimestamp(), $saveInstant->getTimestamp());
        $this->assertLessThanOrEqual($afterSave->getTimestamp(), $saveInstant->getTimestamp());
        $this->assertSame('COMPLETE', $report['publication_state']);
        foreach ($report['races'] as $race) {
            $this->assertSame('PRESTART_EVIDENCE_CANDIDATE', $race['state']);
            $this->assertSame('RAW_SAVED_AFTER_SCHEDULED_START', $race['raw_save_timing_status']);
            $this->assertSame('AFTER_SCHEDULED_START', $race['raw_save_timing_reason']);
            $this->assertSame($capture['raw_saved_at'], $race['raw_saved_at']);
        }
        $this->assertSame($saved->validation, $report);
        $this->assertSame(EvidenceFiles::json($report), EvidenceFiles::read($saved->path, 'validation.json'));
        $this->assertSame($original, $this->snapshot($saved->path));
    }

    public static function sourceCases(): array
    {
        return [
            '301' => [['httpStatus' => 301], 'HTTP_3XX_REJECTED'],
            '302' => [['httpStatus' => 302], 'HTTP_3XX_REJECTED'],
            '303' => [['httpStatus' => 303], 'HTTP_3XX_REJECTED'],
            '307' => [['httpStatus' => 307], 'HTTP_3XX_REJECTED'],
            '308' => [['httpStatus' => 308], 'HTTP_3XX_REJECTED'],
            'other 3xx' => [['httpStatus' => 304], 'HTTP_3XX_REJECTED'],
            'redirect despite 200' => [['redirectDetected' => true], 'REDIRECT_REFUSED'],
            'unknown redirect' => [['redirectDetected' => null], 'REDIRECT_UNKNOWN'],
            'unknown URI' => [['effectiveUri' => null], 'SOURCE_UNVERIFIED'],
            'unapproved host' => [['effectiveUri' => 'https://unapproved.invalid/card?encp=artificial-secret&type=JSJ017'], 'SOURCE_NOT_ALLOWED'],
            'unapproved path' => [['effectiveUri' => 'https://synthetic.invalid/other?encp=artificial-secret&type=JSJ017'], 'SOURCE_NOT_ALLOWED'],
            'URI mismatch' => [['effectiveUri' => 'https://synthetic.invalid/card?encp=another-secret&type=JSJ017'], 'SOURCE_URI_MISMATCH'],
            'bad method' => [['method' => 'POST'], 'SOURCE_PARAMETERS_INVALID'],
            'wrong selector' => [['parameters' => ['encp' => 'artificial-secret', 'type' => 'PJ0326']], 'SOURCE_PARAMETERS_INVALID'],
        ];
    }

    #[DataProvider('sourceCases')]
    public function test_valid_race_raw_never_overrides_invalid_or_unknown_source(array $overrides, string $reason): void
    {
        $saved = $this->store()->save($this->input(overrides: $overrides));
        $report = (new CaptureVerifier)->verify($saved->path, $saved->manifestSha256);
        $this->assertSame('FAILED', $report['publication_state']);
        $this->assertContains($reason, $report['source_validation']['reasons']);
        foreach ($report['races'] as $race) {
            $this->assertSame('VERIFIED', $race['identity_status']);
            $this->assertNotSame('PRESTART_EVIDENCE_CANDIDATE', $race['state']);
        }
        $this->assertFileExists($saved->path.'/raw.bin');
        $this->assertFileDoesNotExist($saved->path.'/COMPLETE.json');
    }

    public static function identityCases(): array
    {
        return ['date' => ['date'], 'track' => ['track'], 'race' => ['race'], 'bike' => ['bike'], 'registration' => ['registration']];
    }

    #[DataProvider('identityCases')]
    public function test_race_bike_and_registration_mismatches_are_not_accepted(string $change): void
    {
        $raw = $this->jsj017();
        match ($change) {
            'date' => $raw['kaisaihi'] = $raw['reqprm']['kday'] = '20251230',
            'track' => $raw['keirinCd'] = $raw['reqprm']['bkcd'] = '23',
            'race' => $raw['rInfo'][0]['raceNo'] = 3,
            'bike' => $raw['rInfo'][0]['sInfo'][4]['syaban'] = 7,
            'registration' => $raw['rInfo'][0]['sInfo'][0]['senNo'] = '999999',
        };
        $saved = $this->store()->save($this->input(raw: EvidenceFiles::json($raw)));
        $report = (new CaptureVerifier)->verify($saved->path, $saved->manifestSha256);
        $this->assertSame('FAILED', $report['publication_state']);
        $this->assertSame('IDENTITY_MISMATCH', $report['races'][0]['state']);
    }

    public function test_pj0315_disagreeing_tables_are_preserved_as_failed_evidence(): void
    {
        $raw = $this->pj0315(summaryRegistration: '999999');
        $saved = $this->store('PJ0315')->save($this->input(source: 'PJ0315', raw: $raw));
        $this->assertSame('RAW_SCHEMA_INVALID', $saved->validation['schema_status']);
        $this->assertSame([], $saved->validation['races']);
        $this->assertSame($raw, EvidenceFiles::read($saved->path, 'raw.bin'));
        $this->assertSame($saved->validation, (new CaptureVerifier)->verify($saved->path, $saved->manifestSha256));
    }

    public function test_reacquiring_the_same_bytes_creates_distinct_immutable_captures(): void
    {
        $store = $this->store();
        $first = $store->save($this->input());
        $original = $this->snapshot($first->path);
        $second = $store->save($this->input(overrides: ['fetchedAt' => '2025-12-31T09:55:00+09:00']));
        $this->assertNotSame($first->captureId, $second->captureId);
        $this->assertSame(EvidenceFiles::read($first->path, 'raw.bin'), EvidenceFiles::read($second->path, 'raw.bin'));
        $this->assertSame($original, $this->snapshot($first->path));
        $this->assertNotSame(EvidenceFiles::read($first->path, 'capture.json'), EvidenceFiles::read($second->path, 'capture.json'));
    }

    public function test_existing_capture_is_not_overwritten_even_for_identical_input(): void
    {
        $store = $this->store();
        $input = $this->input();
        $saved = $store->save($input);
        $before = $this->snapshot($saved->path);
        $this->reject(fn () => $store->save($input));
        $this->assertSame($before, $this->snapshot($saved->path));
        $this->reject(fn () => EvidenceFiles::writeNew($saved->path, 'raw.bin', 'replacement'));
        $this->assertSame($before, $this->snapshot($saved->path));
    }

    public static function tamperCases(): array
    {
        return ['one-byte Raw' => ['raw'], 'size' => ['size'], 'SHA' => ['sha'], 'manifest' => ['manifest'],
            'target' => ['target'], 'marker' => ['marker'], 'contradictory marker' => ['extra']];
    }

    #[DataProvider('tamperCases')]
    public function test_saved_evidence_modification_is_rejected(string $change): void
    {
        $saved = $this->store()->save($this->input());
        if ($change === 'raw') {
            $bytes = EvidenceFiles::read($saved->path, 'raw.bin');
            file_put_contents($saved->path.'/raw.bin', substr_replace($bytes, ' ', 0, 1));
        } elseif ($change === 'size' || $change === 'sha') {
            $capture = EvidenceFiles::object(EvidenceFiles::read($saved->path, 'capture.json'));
            $capture['raw_seal'][$change === 'size' ? 'bytes' : 'sha256'] = $change === 'size' ? 1 : str_repeat('0', 64);
            file_put_contents($saved->path.'/capture.json', EvidenceFiles::json($capture));
            $this->reject(fn () => (new CaptureVerifier)->evaluate($saved->path));
        } elseif ($change === 'manifest') {
            file_put_contents($saved->path.'/manifest.json', '{}');
        } elseif ($change === 'target') {
            file_put_contents($saved->path.'/targets.json', '{}');
        } elseif ($change === 'marker') {
            file_put_contents($saved->path.'/COMPLETE.json', '{}');
        } else {
            EvidenceFiles::writeNew($saved->path, 'FAILED.json', '{}');
        }
        $this->reject(fn () => (new CaptureVerifier)->verify($saved->path, $saved->manifestSha256));
    }

    public function test_missing_raw_is_rejected_without_deleting_any_file(): void
    {
        $saved = $this->store()->save($this->input());
        rename($saved->path.'/raw.bin', $saved->path.'/retained-raw.bin');
        $this->reject(fn () => (new CaptureVerifier)->verify($saved->path, $saved->manifestSha256));
        $this->assertFileExists($saved->path.'/retained-raw.bin');
    }

    public function test_missing_or_conflicting_marker_is_never_treated_as_published(): void
    {
        $saved = $this->store()->save($this->input());
        rename($saved->path.'/COMPLETE.json', $saved->path.'/retained-COMPLETE.json');
        $this->reject(fn () => (new CaptureVerifier)->verify($saved->path, $saved->manifestSha256));
        $this->reject(fn () => (new CaptureVerifier)->verifyBeforePublication($saved->path, $saved->manifestSha256));
        $this->assertFileExists($saved->path.'/retained-COMPLETE.json');
    }

    public function test_secret_uris_and_parameters_are_only_in_private_evidence(): void
    {
        $saved = $this->store()->save($this->input());
        foreach (['manifest.json', 'capture.json', 'validation.json', 'targets.json', 'COMPLETE.json'] as $file) {
            $bytes = EvidenceFiles::read($saved->path, $file);
            $this->assertStringNotContainsString('artificial-secret', $bytes);
            $this->assertStringNotContainsString('https://', $bytes);
        }
        $this->assertStringContainsString('artificial-secret', EvidenceFiles::read($saved->path, 'private-source.json'));
        $this->assertSame(0600, fileperms($saved->path.'/private-source.json') & 0777);
    }

    public function test_riding_style_presence_type_and_original_value_are_not_normalized(): void
    {
        $raw = $this->jsj017();
        unset($raw['rInfo'][0]['sInfo'][0]['kyaku']);
        $raw['rInfo'][0]['sInfo'][1]['kyaku'] = null;
        $raw['rInfo'][0]['sInfo'][2]['kyaku'] = '';
        $raw['rInfo'][0]['sInfo'][3]['kyaku'] = [];
        $raw['rInfo'][0]['sInfo'][4]['kyaku'] = ' unknown ';
        $saved = $this->store()->save($this->input(raw: EvidenceFiles::json($raw)));
        $fields = array_column($saved->validation['races'][0]['observation']['entries'], 'riding_style');
        $this->assertSame(['missing', 'null', 'string', 'array', 'string'], array_column($fields, 'type'));
        $this->assertSame(['MISSING', 'PRESENT', 'PRESENT', 'PRESENT', 'PRESENT'], array_column($fields, 'presence'));
        $this->assertSame([null, null, '', [], ' unknown '], array_column($fields, 'value'));
        $this->assertSame(['MISSING', 'NULL', 'EMPTY', 'INVALID_TYPE', 'UNKNOWN'],
            array_column($saved->validation['races'][0]['observation']['entries'], 'attribute_status'));
    }

    public static function sources(): array
    {
        return [['JSJ017'], ['PJ0315']];
    }

    #[DataProvider('sources')]
    public function test_optional_object_and_list_fields_keep_their_original_json_types(string $source): void
    {
        if ($source === 'JSJ017') {
            $raw = $this->jsj017();
            $raw['rInfo'][0]['sInfo'][0]['kyaku'] = new \stdClass;
            $raw['rInfo'][0]['sInfo'][1]['kyaku'] = [];
            $bytes = EvidenceFiles::json($raw);
        } else {
            $bytes = $this->pj0315(style: new \stdClass);
        }
        $saved = $this->store($source)->save($this->input(source: $source, raw: $bytes));
        $fields = array_column($saved->validation['races'][0]['observation']['entries'], 'riding_style');
        $this->assertSame('object', $fields[0]['type']);
        $this->assertInstanceOf(\stdClass::class, $fields[0]['value']);
        if ($source === 'JSJ017') {
            $this->assertSame('array', $fields[1]['type']);
            $this->assertSame([], $fields[1]['value']);
        }
        $this->assertSame(EvidenceFiles::json($saved->validation),
            EvidenceFiles::json((new CaptureVerifier)->verify($saved->path, $saved->manifestSha256)));
        $this->assertSame($bytes, EvidenceFiles::read($saved->path, 'raw.bin'));
    }

    public function test_missing_or_invalid_raw_schedule_never_uses_another_races_time(): void
    {
        foreach ([null, '', '25:00', 1000] as $time) {
            $raw = $this->jsj017();
            $raw['rInfo'][0]['stTime'] = $time;
            $saved = $this->store()->save($this->input(raw: EvidenceFiles::json($raw)));
            $this->assertSame('TIMING_UNKNOWN', $saved->validation['races'][0]['state']);
            $this->assertSame('PRESTART_EVIDENCE_CANDIDATE', $saved->validation['races'][1]['state']);
        }
        $raw = $this->jsj017();
        unset($raw['rInfo'][0]['stTime']);
        $saved = $this->store()->save($this->input(raw: EvidenceFiles::json($raw)));
        $this->assertSame('TIMING_UNKNOWN', $saved->validation['races'][0]['state']);
        $this->assertSame('MISSING', $saved->validation['races'][0]['observation']['schedule']['presence']);
    }

    public function test_uri_digests_must_match_the_private_source_record(): void
    {
        $saved = $this->store()->save($this->input());
        $capture = EvidenceFiles::object(EvidenceFiles::read($saved->path, 'capture.json'));
        $capture['request_uri_sha256'] = str_repeat('0', 64);
        file_put_contents($saved->path.'/capture.json', EvidenceFiles::json($capture));
        $this->reject(fn () => (new CaptureVerifier)->evaluate($saved->path));
    }

    public function test_json_embedded_in_html_preserves_braces_and_quotes_inside_string_values(): void
    {
        $style = 'unknown } ] " \\ { value';
        $saved = $this->store('PJ0315')->save($this->input(source: 'PJ0315', raw: $this->pj0315(style: $style)));
        $this->assertSame($style, $saved->validation['races'][0]['observation']['entries'][0]['riding_style']['value']);
    }

    public function test_invalid_json_retains_raw_and_failed_marker_without_normal_observations(): void
    {
        $saved = $this->store()->save($this->input(raw: '{invalid synthetic JSON'));
        $this->assertSame('FAILED', $saved->validation['publication_state']);
        $this->assertSame([], $saved->validation['races']);
        $this->assertSame($saved->validation, (new CaptureVerifier)->verify($saved->path, $saved->manifestSha256));
    }

    public function test_2026_synthetic_target_is_rejected_before_any_evidence_write(): void
    {
        $directory = $this->directory();
        $before = scandir($directory->path());
        try {
            new RaceTarget('20260101', '22', 1, $this->registrations());
            $this->fail('Forbidden year accepted.');
        } catch (InvalidArgumentException) {
            $this->assertSame($before, scandir($directory->path()));
        }
    }

    public function test_invalid_relative_and_protected_roots_are_rejected_before_write(): void
    {
        foreach (['', '.', '..', 'relative', dirname(__DIR__, 5), '/home/shinya', Directory::TRASH] as $root) {
            $this->reject(fn () => new CaptureStore($root, $this->policy()));
        }
    }

    public function test_symlink_capture_path_is_rejected_and_retained_without_following_it(): void
    {
        $directory = $this->linkDirectory();
        $target = $this->directory();
        $input = $this->input();
        $store = new CaptureStore($directory->path(), $this->policy());
        $directory->createLink($input->captureId, $target);
        $before = $this->snapshot($target->path());
        $link = lstat($directory->path().'/'.$input->captureId);
        $this->reject(fn () => $store->save($input));
        $this->assertSame($before, $this->snapshot($target->path()));
        $this->assertTrue(is_link($directory->path().'/'.$input->captureId));
        $record = $directory->retire($directory->path(), 'TEST_COMPLETE');
        $this->assertDirectoryDoesNotExist($directory->path());
        $this->assertTrue(is_link($record['destination'].'/'.$input->captureId));
        $this->assertSame($link['ino'], lstat($record['destination'].'/'.$input->captureId)['ino']);
        $this->assertSame($target->path(), readlink($record['destination'].'/'.$input->captureId));
        $this->assertSame($before, $this->snapshot($target->path()));
        $this->assertSame($directory->path(), $record['source']);
        $this->assertSame('TEST_COMPLETE', $record['reason']);
        $this->assertSame('MOVED', $record['state']);
        $this->assertSame($record, EvidenceFiles::object(EvidenceFiles::read(dirname($record['destination']), 'MOVED.json')));
        $this->assertFileExists(dirname($record['destination']).'/ATTEMPT.json');
        $this->assertNotEmpty($record['moved_at']);
        $this->reject(fn () => $directory->retire($directory->path(), 'TEST_COMPLETE'));
    }

    public function test_unexpected_symlink_name_is_rejected_without_touching_its_target(): void
    {
        $directory = $this->linkDirectory();
        $target = $this->directory();
        $name = str_repeat('a', 32);
        $directory->createLink($name, $target);
        $before = $this->snapshot($target->path());
        $trash = scandir(Directory::TRASH);
        $linkPath = $directory->path().'/'.$name;
        $unexpected = $directory->path().'/unexpected-link';
        $move = new Process(['/usr/bin/mv', '--no-copy', '--update=none-fail', '--no-target-directory', '--', $linkPath, $unexpected]);
        $move->mustRun();
        try {
            $this->reject(fn () => $directory->retire($directory->path(), 'TEST_FAILURE'));
            $this->assertTrue(is_link($unexpected));
            $this->assertSame($before, $this->snapshot($target->path()));
            $this->assertSame($trash, scandir(Directory::TRASH));
        } finally {
            // Restore only this recorded artificial link's test-induced name change, without unlinking it.
            (new Process(['/usr/bin/mv', '--no-copy', '--update=none-fail', '--no-target-directory', '--', $unexpected, $linkPath]))->mustRun();
        }
    }

    public function test_link_target_must_match_its_recorded_artificial_creation_identity(): void
    {
        $directory = $this->linkDirectory();
        $target = $this->directory();
        $original = EvidenceFiles::read($target->path(), 'CREATED.json');
        $changed = EvidenceFiles::object($original);
        $changed['inode']++;
        file_put_contents($target->path().'/CREATED.json', EvidenceFiles::json($changed));
        $before = scandir($directory->path());
        try {
            $this->reject(fn () => $directory->createLink(str_repeat('a', 32), $target));
            $this->assertSame($before, scandir($directory->path()));
        } finally {
            file_put_contents($target->path().'/CREATED.json', $original);
        }
    }

    public function test_link_fixture_refuses_mounts_and_protected_or_unrecorded_paths_before_move(): void
    {
        $directory = $this->linkDirectory(SyntheticMountLinkFixture::class);
        $target = $this->directory();
        $directory->createLink(str_repeat('a', 32), $target);
        $trash = scandir(Directory::TRASH);
        foreach (['', '.', '..', 'relative', dirname(__DIR__, 5), '/home/shinya', Directory::TRASH, $target->path()] as $path) {
            $this->reject(fn () => $directory->retire($path, 'TEST_COMPLETE'));
        }
        $directory->mount = $directory->path().'/synthetic-mount';
        try {
            $this->reject(fn () => $directory->retire($directory->path(), 'TEST_COMPLETE'));
            $this->assertDirectoryExists($directory->path());
            $this->assertSame($trash, scandir(Directory::TRASH));
        } finally {
            $directory->mount = null;
        }
    }

    public function test_link_fixture_move_failure_preserves_source_and_records_failure_without_fallback(): void
    {
        $directory = $this->linkDirectory(FailingMoveLinkFixture::class);
        $target = $this->directory();
        $name = str_repeat('a', 32);
        $directory->createLink($name, $target);
        $before = $this->snapshot($target->path());
        $inode = lstat($directory->path())['ino'];
        try {
            $this->reject(fn () => $directory->retire($directory->path(), 'TEST_FAILURE'));
            $this->assertSame($inode, lstat($directory->path())['ino']);
            $this->assertTrue(is_link($directory->path().'/'.$name));
            $this->assertSame($before, $this->snapshot($target->path()));
            $container = dirname($directory->attemptedDestination);
            $this->assertFileExists($container.'/ATTEMPT.json');
            $this->assertFileExists($container.'/FAILED.json');
            $this->assertFileDoesNotExist($container.'/MOVED.json');
            $this->assertDirectoryDoesNotExist($directory->attemptedDestination);
        } finally {
            $directory->failMove = false;
        }
    }

    public function test_artificial_link_fixture_is_retired_after_an_operation_exception(): void
    {
        $directory = $this->linkDirectory();
        $target = $this->directory();
        $name = str_repeat('a', 32);
        $directory->createLink($name, $target);
        $before = $this->snapshot($target->path());
        try {
            try {
                throw new RuntimeException('Synthetic operation failure.');
            } finally {
                $record = $directory->retire($directory->path(), 'TEST_FAILURE');
            }
        } catch (RuntimeException $error) {
            $this->assertSame('Synthetic operation failure.', $error->getMessage());
        }
        $this->assertDirectoryDoesNotExist($directory->path());
        $this->assertTrue(is_link($record['destination'].'/'.$name));
        $this->assertSame($before, $this->snapshot($target->path()));
        $this->assertSame('TEST_FAILURE', $record['reason']);
    }

    private function linkDirectory(string $class = LinkDirectory::class): LinkDirectory
    {
        $directory = $class::create();
        $this->linkFixtures[] = $directory;

        return $directory;
    }

    public function test_independent_php_process_verifies_saved_inputs_under_actual_128m_limit(): void
    {
        $raw = $this->jsj017();
        $raw['synthetic_unused_padding'] = str_repeat('x', 1024 * 1024);
        $saved = $this->store()->save($this->input(raw: EvidenceFiles::json($raw)));
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=128M', '-r',
            'require $argv[1]; $r = (new App\\Domain\\Keirin\\Scraping\\PrestartEvidence\\CaptureVerifier)->verify($argv[2], $argv[3]); echo json_encode([ini_get("memory_limit"), $r]);',
            dirname(__DIR__, 5).'/vendor/autoload.php', $saved->path, $saved->manifestSha256]);
        $process->setTimeout(20);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame('', $process->getErrorOutput());
        [$limit, $report] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('128M', $limit);
        $this->assertSame($saved->validation, $report);
    }

    private function directory(): Directory
    {
        $directory = Directory::create();
        $this->owned[] = $directory;

        return $directory;
    }

    private function store(string $source = 'JSJ017'): CaptureStore
    {
        return new CaptureStore($this->directory()->path(), $this->policy($source));
    }

    private function policy(string $source = 'JSJ017'): array
    {
        return ['route' => ['scheme' => 'https', 'host' => 'synthetic.invalid', 'port' => 443, 'path' => '/card'],
            'method' => $source === 'JSJ017' ? 'GET' : 'POST'];
    }

    private function registrations(): array
    {
        return [1 => '000001', 2 => '000002', 3 => '000003', 4 => '000004', 6 => '000006'];
    }

    private function input(string $source = 'JSJ017', ?string $raw = null, array $overrides = []): SyntheticCapture
    {
        $uri = 'https://synthetic.invalid/card'.($source === 'JSJ017' ? '?encp=artificial-secret&type=JSJ017' : '');
        $arguments = ['source' => $source, 'raw' => $raw ?? ($source === 'JSJ017' ? EvidenceFiles::json($this->jsj017()) : $this->pj0315()),
            'targets' => array_map(fn (int $number): RaceTarget => new RaceTarget('20251231', '22', $number, $this->registrations(), 100 + $number),
                $source === 'JSJ017' ? [1, 2] : [1]),
            'requestUri' => $uri, 'effectiveUri' => $uri, 'method' => $source === 'JSJ017' ? 'GET' : 'POST',
            'parameters' => ['encp' => 'artificial-secret', $source === 'JSJ017' ? 'type' : 'disp' => $source],
            'httpStatus' => 200, 'redirectDetected' => false, 'fetchedAt' => '2025-12-31T09:50:00+09:00',
            'scheduleTimezone' => 'Asia/Tokyo', 'clockEvidence' => 'SYNTHETIC_CLOCK_CONFIRMED'];

        return new SyntheticCapture(...array_replace($arguments, $overrides));
    }

    private function jsj017(): array
    {
        $entries = [];
        foreach ($this->registrations() as $bike => $registration) {
            $entries[] = ['syaban' => $bike, 'senNo' => $registration, 'kyaku' => "\u{9003}"];
        }

        return ['resultCd' => 0, 'keirinCd' => '22', 'kaisaihi' => '20251231',
            'reqprm' => ['bkcd' => '22', 'kday' => '20251231'],
            'rInfo' => [['raceNo' => 1, 'stTime' => '10:00', 'sInfo' => $entries],
                ['raceNo' => 2, 'stTime' => '10:30', 'sInfo' => $entries]]];
    }

    private function pj0315(?string $summaryRegistration = null, mixed $style = "\u{8FFD}"): string
    {
        $summary = $entries = [];
        foreach ($this->registrations() as $bike => $registration) {
            $summary[] = ['carNum' => $bike, 'numPlayer' => $summaryRegistration ?? $registration];
            $entries[] = ['syaban' => $bike, 'sensyuRegistNo' => $registration, 'kyakusitu' => $style];
        }
        $context = ['C0201data' => ['selKaisai' => '20251231', 'selKjyoCd' => '22', 'selRaceNo' => 1,
            'C0201racedtl' => ['aftStartTime' => '10:00', 'C0201sensyu' => $summary]]];

        return '<html><script>jsonData["PC0201"] = '.EvidenceFiles::json($context)
            .'; jsonData["PJ0315"] = '.EvidenceFiles::json(['sensyuTypeInfo' => $entries]).';</script></html>';
    }

    private function snapshot(string $path): array
    {
        $seals = [];
        foreach (scandir($path) as $name) {
            if ($name !== '.' && $name !== '..') {
                $seals[$name] = hash_file('sha256', $path.'/'.$name);
            }
        }

        return $seals;
    }

    private function reject(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Unsafe evidence operation accepted.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
    }
}

final class SyntheticMountLinkFixture extends LinkDirectory
{
    public ?string $mount = null;

    protected function mountPoints(): array
    {
        return [...parent::mountPoints(), ...($this->mount === null ? [] : [$this->mount])];
    }
}

final class FailingMoveLinkFixture extends LinkDirectory
{
    public bool $failMove = true;

    public string $attemptedDestination;

    protected function renameOnly(string $source, string $destination): void
    {
        $this->attemptedDestination = $destination;
        if ($this->failMove) {
            throw new RuntimeException('Synthetic rename failure.');
        }
        parent::renameOnly($source, $destination);
    }
}
