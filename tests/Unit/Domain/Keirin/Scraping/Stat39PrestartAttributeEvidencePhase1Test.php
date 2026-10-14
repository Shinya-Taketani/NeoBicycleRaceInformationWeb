<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Scraping;

use App\Domain\Keirin\Scraping\PrestartEvidence\Attributes\AttributeEvidenceStore;
use App\Domain\Keirin\Scraping\PrestartEvidence\Attributes\AttributeEvidenceVerifier;
use App\Domain\Keirin\Scraping\PrestartEvidence\Attributes\AttributeProjector;
use App\Domain\Keirin\Scraping\PrestartEvidence\Attributes\BundleFiles;
use App\Domain\Keirin\Scraping\PrestartEvidence\Attributes\LosslessJson;
use App\Domain\Keirin\Scraping\PrestartEvidence\Attributes\RawDocuments;
use App\Domain\Keirin\Scraping\PrestartEvidence\Attributes\ScenarioTiming;
use App\Domain\Keirin\Scraping\PrestartEvidence\Attributes\StoredAttributeEvidence;
use App\Domain\Keirin\Scraping\PrestartEvidence\CaptureStore;
use App\Domain\Keirin\Scraping\PrestartEvidence\CaptureVerifier;
use App\Domain\Keirin\Scraping\PrestartEvidence\EvidenceFiles;
use App\Domain\Keirin\Scraping\PrestartEvidence\RaceTarget;
use App\Domain\Keirin\Scraping\PrestartEvidence\StoredCapture;
use App\Domain\Keirin\Scraping\PrestartEvidence\SyntheticCapture;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\CompositionResultTemporaryDirectory as Directory;
use Tests\Support\PrestartSymlinkFixtureDirectory as LinkDirectory;

/** Entirely artificial bytes, identities, clocks and routes; no real Raw, database or HTTP. */
final class Stat39PrestartAttributeEvidencePhase1Test extends TestCase
{
    private array $owned = [];

    private array $links = [];

    protected function tearDown(): void
    {
        try {
            $this->retire($this->status()->isSuccess() ? 'TEST_COMPLETE' : 'TEST_FAILURE');
        } finally {
            parent::tearDown();
        }
    }

    public function test_both_sources_publish_offline_with_original_capture_and_old_code_seal_unchanged(): void
    {
        $oldCode = EvidenceFiles::codeSeal();
        foreach (['JSJ017' => 2, 'PJ0315' => 1] as $type => $count) {
            $source = $this->source($type);
            $before = $this->snapshot($source->path);
            $saved = $this->save($source);
            $report = $this->verify($saved, $source);
            $this->assertSame($saved->verification, $report);
            $this->assertCount($count, $report['validation']['races']);
            foreach ($report['validation']['races'] as $race) {
                $this->assertSame('PRESTART_EVIDENCE_CANDIDATE', $race['timing']['capture_status']);
                $this->assertSame('SIMULATED_TECHNICALLY_AVAILABLE', $race['timing']['cutoff_status']);
            }
            $this->assertSame('AFTER', $report['physical_publication_vs_simulated_cutoff']);
            $this->assertFalse($report['historical_as_of_available']);
            $this->assertFalse($report['formal_adoption']);
            $this->assertSame('NOT_AUTHORIZED', $report['prediction_use']);
            $this->assertSame($before, $this->snapshot($source->path));
            $this->assertSame($source->validation, (new CaptureVerifier)->verify($source->path, $source->manifestSha256));
            $this->assertSame([], glob($saved->path.'/*.bin'));
            $this->assertSame(0600, fileperms($saved->path.'/publication-receipt.json') & 0777);
            $this->assertStringNotContainsString('artificial-secret', EvidenceFiles::read($saved->path, 'manifest.json'));
            $this->assertStringNotContainsString('encp', EvidenceFiles::read($saved->path, 'source-reference.json'));
            $races = $this->rows($saved);
            $this->assertSame('000001', $races[0]['entries'][0]['external_registration_number']);
            $identity = $races[0]['race_identity_evidence'];
            $this->assertSame('"20251231"', $identity['race_date']['raw_lexeme']);
            $this->assertSame($type === 'JSJ017' ? '/kaisaihi' : '/C0201data/selKaisai', $identity['race_date']['pointer']['json_pointer']);
            $this->assertSame($type === 'JSJ017' ? 'JSJ017' : 'PC0201', $identity['race_number']['pointer']['document_key']);
            if ($type === 'PJ0315') {
                $this->assertSame('/C0201data/C0201racedtl/C0201sensyu/0/numPlayer',
                    $races[0]['entries'][0]['summary_identity_evidence']['external_registration_number']['pointer']['json_pointer']);
            }
            $frame = $races[0]['entries'][0]['attributes']['frame_number'];
            $this->assertSame($type === 'JSJ017' ? 'SOURCE_NOT_PROVIDED' : 'PRESENT', $frame['presence']);
            $this->assertSame('UNKNOWN', $frame['official_applicability']);
        }
        $this->assertSame($oldCode, EvidenceFiles::codeSeal());
    }

    public function test_lossless_tokens_use_exact_structural_paths_not_same_value_or_string_matches(): void
    {
        $raw = $this->pj(['90.10', '90.1', '"90.10"', '0', '9.010e1']);
        $source = $this->source('PJ0315', $raw);
        $saved = $this->save($source);
        $entries = $this->rows($saved)[0]['entries'];
        $documents = (new RawDocuments)->decode('PJ0315', $raw);
        foreach (['90.10', '90.1', '"90.10"', '0', '9.010e1'] as $i => $token) {
            $score = $entries[$i]['attributes']['race_score'];
            $this->assertSame($token, $score['raw_lexeme']);
            $this->assertSame('/sensyuTypeInfo/'.$i.'/heikinTokuten', $score['pointer']['json_pointer']);
            $this->assertSame($token, substr($documents['PJ0315']['bytes'], $score['pointer']['token_offset'], $score['pointer']['token_length']));
        }
        $this->assertSame(['DECIMAL', 'DECIMAL', 'STRING', 'INTEGER', 'DECIMAL'], array_map(fn ($e) => $e['attributes']['race_score']['raw_type'], $entries));
        $this->assertSame(['90.1', '90.1', '90.1', '0', null], array_map(fn ($e) => $e['attributes']['race_score']['normalized_value'], $entries));
        $this->assertFalse($entries[3]['attributes']['race_score']['stat01_usable']);
        $this->assertSame('NORMALIZATION_UNVERIFIED', $entries[4]['attributes']['race_score']['normalization_status']);
        $this->assertSame($saved->verification, $this->verify($saved, $source));
    }

    public static function attributeValues(): array
    {
        return [
            'missing' => [null, 'MISSING', null, null],
            'null' => ['null', 'NULL', 'NULL', null],
            'empty string' => ['""', 'EMPTY_STRING', 'STRING', null],
            'full width dash' => ['"－"', 'FULLWIDTH_HYPHEN', 'STRING', null],
            'integer' => ['2', 'PRESENT', 'INTEGER', 2],
            'numeric string' => ['"2"', 'PRESENT', 'STRING', 2],
            'boolean' => ['false', 'PRESENT', 'BOOLEAN', null],
            'object' => ['{}', 'PRESENT', 'OBJECT', null],
            'list' => ['[]', 'PRESENT', 'ARRAY', null],
            'unknown string' => ['"unknown"', 'PRESENT', 'STRING', null],
            'zero' => ['0', 'PRESENT', 'INTEGER', null],
        ];
    }

    #[DataProvider('attributeValues')]
    public function test_frame_presence_type_and_official_unknown_are_not_collapsed(?string $token, string $presence, ?string $type, ?int $value): void
    {
        $source = $this->source('PJ0315', $this->pj(frameToken: $token));
        $saved = $this->save($source);
        $frame = $this->rows($saved)[0]['entries'][0]['attributes']['frame_number'];
        $this->assertSame($presence, $frame['presence']);
        $this->assertSame($type, $frame['raw_type']);
        $this->assertSame($token, $frame['raw_lexeme']);
        $this->assertSame($value, $frame['normalized_value']);
        $this->assertSame('UNKNOWN', $frame['official_applicability']);
        if (in_array($type, ['BOOLEAN', 'ARRAY', 'OBJECT'], true)) {
            $this->assertSame('INVALID_TYPE', $frame['normalization_reason']);
        }
        $this->assertSame(1, $this->rows($saved)[0]['entries'][0]['attributes']['bike_number']['normalized_value']);
    }

    public function test_embedded_json_ignores_comments_and_strings_and_keeps_escaped_nested_values(): void
    {
        $raw = '<script>const decoy = '.json_encode('jsonData["PJ0315"] = { fake: true }', JSON_THROW_ON_ERROR).';'
            .'/* jsonData["PC0201"] = {} */ // jsonData["PJ0315"] = {}'."\n</script>".$this->pj();
        $docs = (new RawDocuments)->decode('PJ0315', $raw);
        $this->assertSame('90.10', $docs['PJ0315']['node']->required('sensyuTypeInfo')->items()[0]->required('heikinTokuten')->lexeme);
        $node = (new LosslessJson)->parse('{"a/b":{"~x":[{"n":90.10,"text":'.json_encode('escaped " } [ heikinTokuten', JSON_THROW_ON_ERROR).'}]}}');
        $number = $node->required('a/b')->required('~x')->items()[0]->required('n');
        $this->assertSame('/a~1b/~0x/0/n', $number->pointer);
        $this->assertSame('90.10', $number->lexeme);
    }

    public static function invalidDocuments(): array
    {
        return [
            'duplicate key' => ['{"x":1,"x":2}'],
            'escaped duplicate key' => ['{"x":1,"\\u0078":2}'],
            'malformed JSON' => ['{"x":}'],
            'document array' => ['[]'],
            'trailing data' => ['{} {}'],
        ];
    }

    #[DataProvider('invalidDocuments')]
    public function test_ambiguous_or_invalid_json_is_rejected(string $json): void
    {
        $this->reject(fn () => (new LosslessJson)->parse($json));
    }

    public function test_duplicate_document_assignment_and_nonliteral_assignment_are_rejected(): void
    {
        $this->reject(fn () => (new RawDocuments)->decode('PJ0315', $this->pj().'<script>jsonData["PJ0315"] = {};</script>'));
        $this->reject(fn () => (new RawDocuments)->decode('PJ0315', '<script>jsonData["PJ0315"] = fetchSomething();</script>'));
        $raw = str_replace('"heikinTokuten":90.10', '"heikinTokuten":90.10,"heikinTokuten":90.1', $this->pj());
        // The old decoder may accept last-key-wins; the derivative must not do so.
        $source = $this->source('PJ0315', $raw);
        $this->reject(fn () => $this->save($source));
        $this->reject(fn () => (new RawDocuments)->decode('PJ0315', str_replace('jsonData["PJ0315"] =', 'if (false) jsonData["PJ0315"] =', $this->pj())));
        $this->reject(fn () => (new RawDocuments)->decode('PJ0315', str_replace(']};</script>', ']}.other;</script>', $this->pj())));
        $this->reject(fn () => (new RawDocuments)->decode('PJ0315', '<script>const pattern = /jsonData["PJ0315"] = {}/;</script>'.$this->pj()));
    }

    public static function timingCases(): array
    {
        return [
            'normal' => [[], 'PRESTART_EVIDENCE_CANDIDATE', 'SIMULATED_TECHNICALLY_AVAILABLE', 'ALL_COMPLETIONS_AT_OR_BEFORE_CUTOFF'],
            'equal' => [['response_received_at' => '2025-12-31T10:00:00+09:00'], 'POSTSTART_CAPTURE', 'UNAVAILABLE', 'CUTOFF_RECEIPT_LATE'],
            'after' => [['response_received_at' => '2025-12-31T10:00:01+09:00'], 'POSTSTART_CAPTURE', 'UNAVAILABLE', 'CUTOFF_RECEIPT_LATE'],
            'save late' => [['raw_saved_at' => '2025-12-31T09:56:00+09:00'], 'PRESTART_EVIDENCE_CANDIDATE', 'UNAVAILABLE', 'CUTOFF_RAW_SAVE_LATE'],
            'validation late' => [['validation_completed_at' => '2025-12-31T09:56:00+09:00'], 'PRESTART_EVIDENCE_CANDIDATE', 'UNAVAILABLE', 'CUTOFF_VALIDATION_LATE'],
            'publication late' => [['publication_completed_at' => '2025-12-31T09:56:00+09:00'], 'PRESTART_EVIDENCE_CANDIDATE', 'UNAVAILABLE', 'CUTOFF_PUBLICATION_LATE'],
            'timezone absent' => [['schedule_timezone' => null], 'TIMING_UNKNOWN', 'UNKNOWN', 'INCOMPLETE_CLOCK_OR_RECEIPT'],
            'offset absent' => [['response_received_at' => '2025-12-31T09:50:00'], 'TIMING_UNKNOWN', 'UNKNOWN', 'INCOMPLETE_CLOCK_OR_RECEIPT'],
            'invalid date' => [['response_received_at' => '2025-02-30T09:50:00+09:00'], 'TIMING_UNKNOWN', 'UNKNOWN', 'INCOMPLETE_CLOCK_OR_RECEIPT'],
            'clock absent' => [['clock_evidence' => null], 'TIMING_UNKNOWN', 'UNKNOWN', 'INCOMPLETE_CLOCK_OR_RECEIPT'],
            'old clock is not real clock' => [['clock_evidence' => 'SYNTHETIC_CLOCK_CONFIRMED'], 'TIMING_UNKNOWN', 'UNKNOWN', 'INCOMPLETE_CLOCK_OR_RECEIPT'],
            'missing receipt' => [['publication_receipt' => null], 'PRESTART_EVIDENCE_CANDIDATE', 'UNKNOWN', 'INCOMPLETE_CLOCK_OR_RECEIPT'],
            'reverse clock order' => [['validation_completed_at' => '2025-12-31T09:49:00+09:00'], 'PRESTART_EVIDENCE_CANDIDATE', 'UNKNOWN', 'CLOCK_ORDER_UNVERIFIED'],
            'missing uncertainty' => [['uncertainty_microseconds' => []], 'TIMING_UNKNOWN', 'UNKNOWN', 'INCOMPLETE_CLOCK_OR_RECEIPT'],
        ];
    }

    #[DataProvider('timingCases')]
    public function test_simulated_clock_strict_capture_and_all_cutoff_completion_stages(array $changes, string $capture, string $cutoff, string $reason): void
    {
        $scenario = $this->scenario($changes);
        $race = $this->project()[0];
        $result = (new ScenarioTiming)->classify($race, $scenario);
        $this->assertSame($capture, $result['capture_status']);
        $this->assertSame($cutoff, $result['cutoff_status']);
        $this->assertSame($reason, $result['cutoff_reason']);
        if ($capture === 'POSTSTART_CAPTURE') {
            $this->assertSame(str_contains($changes['response_received_at'], '10:00:00') ? 'AT_SCHEDULED_START' : 'AFTER_SCHEDULED_START', $result['capture_reason']);
        }
        $this->assertSame('SIMULATED_SCENARIO', $result['time_basis']);
        $this->assertSame('NOT_ESTABLISHED', $result['physical_historical_availability']);
        $this->assertFalse($result['historical_as_of_available']);
    }

    public function test_uncertainty_overlap_and_offset_equivalence(): void
    {
        $race = $this->project()[0];
        $classifier = new ScenarioTiming;
        $normal = $classifier->classify($race, $this->scenario());
        $this->assertSame($normal, $classifier->classify($race, $this->scenario(['response_received_at' => '2025-12-31T00:50:00Z'])));
        $scenario = $this->scenario();
        $scenario['uncertainty_microseconds']['response_received_at'] = 601000000;
        $overlap = $classifier->classify($race, $scenario);
        $this->assertSame('TIMING_UNKNOWN', $overlap['capture_status']);
        $this->assertSame('CLOCK_INTERVALS_OVERLAP', $overlap['capture_reason']);
        $this->assertNotSame('SIMULATED_TECHNICALLY_AVAILABLE', $overlap['cutoff_status']);
        $afterBoundary = $this->scenario(['response_received_at' => '2025-12-31T10:01:00+09:00']);
        $afterBoundary['uncertainty_microseconds']['response_received_at'] = 60000000;
        $this->assertSame('TIMING_UNKNOWN', $classifier->classify($race, $afterBoundary)['capture_status']);
        $receiptOffset = $this->scenario(['publication_receipt' => ['time_basis' => 'SIMULATED_SCENARIO', 'published_at' => '2025-12-31T00:50:03Z']]);
        $this->assertSame($normal, $classifier->classify($race, $receiptOffset));
        $scenario = $this->scenario(['prediction_as_of' => '2025-12-31T09:50:03+09:00']);
        $this->assertSame('SIMULATED_TECHNICALLY_AVAILABLE', $classifier->classify($race, $scenario)['cutoff_status']);
        $scenario['uncertainty_microseconds']['publication_completed_at'] = 500000;
        $this->assertSame('CUTOFF_PUBLICATION_CLOCK_OVERLAP', $classifier->classify($race, $scenario)['cutoff_reason']);
    }

    public static function scoreValues(): array
    {
        return [
            'missing' => [null, 'MISSING', null, null],
            'null' => ['null', 'NULL', 'NULL', null],
            'empty' => ['""', 'EMPTY_STRING', 'STRING', null],
            'dash' => ['"－"', 'FULLWIDTH_HYPHEN', 'STRING', null],
            'negative' => ['-2', 'PRESENT', 'INTEGER', null],
            'boolean' => ['true', 'PRESENT', 'BOOLEAN', null],
            'object' => ['{}', 'PRESENT', 'OBJECT', null],
            'list' => ['[]', 'PRESENT', 'ARRAY', null],
            'invalid format' => ['"90..10"', 'PRESENT', 'STRING', null],
            'unknown' => ['"UNKNOWN"', 'PRESENT', 'STRING', null],
            'large exact decimal' => ['123456789012345678901234567890.1234567890123400', 'PRESENT', 'DECIMAL', '123456789012345678901234567890.12345678901234'],
        ];
    }

    #[DataProvider('scoreValues')]
    public function test_score_original_states_and_large_exact_decimal_are_not_float_or_zero_fallback(?string $token, string $presence, ?string $type, ?string $normalized): void
    {
        $raw = $this->pj();
        $raw = str_replace('"heikinTokuten":90.10}', $token === null ? '"absentScore":90.10}' : '"heikinTokuten":'.$token.'}', $raw);
        $source = $this->source('PJ0315', $raw);
        $score = $this->rows($this->save($source))[0]['entries'][0]['attributes']['race_score'];
        $this->assertSame($presence, $score['presence']);
        $this->assertSame($token, $score['raw_lexeme']);
        $this->assertSame($type, $score['raw_type']);
        $this->assertSame($normalized, $score['normalized_value']);
        $this->assertSame($normalized !== null, $score['stat01_usable']);
    }

    public function test_number_string_bike_preserves_zeros_and_unprovided_jsj_fields_are_not_observations(): void
    {
        $raw = $this->jsj();
        $raw['rInfo'][0]['sInfo'][0]['syaban'] = '01';
        $raw['rInfo'][0]['sInfo'][0]['heikinTokuten'] = '90.10';
        $raw['rInfo'][0]['sInfo'][0]['wakuban'] = 1;
        $source = $this->source('JSJ017', EvidenceFiles::json($raw));
        $entry = $this->rows($this->save($source))[0]['entries'][0];
        $this->assertSame('"01"', $entry['attributes']['bike_number']['raw_lexeme']);
        $this->assertSame(1, $entry['attributes']['bike_number']['normalized_value']);
        $this->assertSame('SOURCE_NOT_PROVIDED', $entry['attributes']['race_score']['presence']);
        $this->assertNull($entry['attributes']['race_score']['raw_lexeme']);
        $this->assertNull($entry['attributes']['frame_number']['raw_type']);
    }

    public function test_independent_reprojection_rejects_resealed_false_token_and_code_contract(): void
    {
        foreach (['token', 'pointer', 'timing', 'code'] as $attack) {
            $source = $this->source('PJ0315');
            $original = $this->snapshot($source->path);
            $saved = $this->save($source);
            $manifest = EvidenceFiles::object(EvidenceFiles::read($saved->path, 'manifest.json'));
            if ($attack === 'timing') {
                $name = 'validation.json';
                $value = EvidenceFiles::object(EvidenceFiles::read($saved->path, $name));
                $value['races'][0]['timing']['cutoff_status'] = 'FALSE_APPROVAL';
                $bytes = EvidenceFiles::json($value);
            } elseif ($attack !== 'code') {
                $name = 'attribute-observations.jsonl';
                $rows = $this->rows($saved);
                if ($attack === 'token') {
                    $rows[0]['entries'][0]['attributes']['race_score']['raw_lexeme'] = '91.10';
                } else {
                    $rows[0]['entries'][0]['attributes']['race_score']['pointer']['json_pointer'] = '/decoy/0/heikinTokuten';
                }
                $bytes = BundleFiles::jsonl($rows);
            }
            if ($attack === 'code') {
                $manifest['code_seal']['projection'] = 'FALSE_VERSION';
            } else {
                $this->mutate($saved->path, $name, $bytes);
                $manifest['files'][$name] = EvidenceFiles::seal($bytes);
            }
            $bytes = EvidenceFiles::json($manifest);
            $hash = hash('sha256', $bytes);
            $this->mutate($saved->path, 'manifest.json', $bytes);
            $marker = EvidenceFiles::object(EvidenceFiles::read($saved->path, 'COMPLETE.json'));
            $marker['manifest_sha256'] = $hash;
            $this->mutate($saved->path, 'COMPLETE.json', EvidenceFiles::json($marker));
            $receipt = EvidenceFiles::object(EvidenceFiles::read($saved->path, 'publication-receipt.json'));
            $receipt['manifest_sha256'] = $hash;
            $receiptBytes = EvidenceFiles::json($receipt);
            $this->mutate($saved->path, 'publication-receipt.json', $receiptBytes);
            $this->reject(fn () => (new AttributeEvidenceVerifier)->verify($saved->path, $hash, $source->path, $source->manifestSha256, hash('sha256', $receiptBytes)));
            $this->assertSame($original, $this->snapshot($source->path));
        }
    }

    public function test_structural_parser_rejects_expansion_before_unbounded_tree_allocation(): void
    {
        $this->reject(fn () => (new LosslessJson)->parse('{"large":['.str_repeat('0,', 20001).'0]}'));
        $this->reject(fn () => (new LosslessJson)->parse('{"'.str_repeat('k', 300000).'":['.str_repeat('0,', 40).'0]}'));
    }

    public function test_missing_schedule_is_not_taken_from_another_race(): void
    {
        $raw = $this->jsj();
        unset($raw['rInfo'][0]['stTime']);
        $source = $this->source('JSJ017', EvidenceFiles::json($raw));
        $saved = $this->save($source);
        $races = $saved->verification['validation']['races'];
        $this->assertSame('TIMING_UNKNOWN', $races[0]['timing']['capture_status']);
        $this->assertSame('PRESTART_EVIDENCE_CANDIDATE', $races[1]['timing']['capture_status']);
        $this->assertSame('MISSING', $this->rows($saved)[0]['entries'][0]['attributes']['scheduled_start']['presence']);
    }

    public function test_scenario_cannot_claim_real_clock_or_copy_unrelated_private_fields(): void
    {
        $source = $this->source();
        $root = $this->directory();
        $before = $this->snapshot($root->path());
        $store = new AttributeEvidenceStore($root->path());
        foreach (['time_basis' => 'ACTUAL_SYSTEM_CLOCK', 'request_uri' => 'ARTIFICIAL_PRIVATE_FIELD'] as $key => $value) {
            $this->reject(fn () => $store->save($source->path, $source->manifestSha256, $this->scenario([$key => $value])));
            $this->assertSame($before, $this->snapshot($root->path()));
        }
    }

    public static function identityCases(): array
    {
        return array_map(fn ($name) => [$name], ['race date', 'track', 'race number', 'registration', 'leading zero', 'bike', 'duplicate bike', 'duplicate registration', 'summary']);
    }

    #[DataProvider('identityCases')]
    public function test_identity_disagreement_and_duplicates_never_publish_a_normal_derivative(string $case): void
    {
        $raw = $this->jsj();
        match ($case) {
            'race date' => $raw['kaisaihi'] = '20251230',
            'track' => $raw['keirinCd'] = '23',
            'race number' => $raw['rInfo'][0]['raceNo'] = 9,
            'registration' => $raw['rInfo'][0]['sInfo'][0]['senNo'] = '999999',
            'leading zero' => $raw['rInfo'][0]['sInfo'][0]['senNo'] = '1',
            'bike' => $raw['rInfo'][0]['sInfo'][0]['syaban'] = 5,
            'duplicate bike' => $raw['rInfo'][0]['sInfo'][1]['syaban'] = 1,
            'duplicate registration' => $raw['rInfo'][0]['sInfo'][1]['senNo'] = '000001',
            default => null,
        };
        $source = $case === 'summary'
            ? $this->source('PJ0315', str_replace('"numPlayer":"000001"', '"numPlayer":"999999"', $this->pj()))
            : $this->source('JSJ017', EvidenceFiles::json($raw));
        $before = $this->snapshot($source->path);
        $this->reject(fn () => $this->save($source));
        $this->assertSame($before, $this->snapshot($source->path));
    }

    public static function invalidSources(): array
    {
        return [
            '3xx' => [['httpStatus' => 302]],
            'redirect' => [['redirectDetected' => true]],
            'unknown URI' => [['effectiveUri' => null]],
            'URI mismatch' => [['effectiveUri' => 'https://artificial.invalid/card?encp=different&type=JSJ017']],
            'unapproved source' => [['effectiveUri' => 'https://unapproved.invalid/card?encp=artificial-secret&type=JSJ017']],
        ];
    }

    #[DataProvider('invalidSources')]
    public function test_invalid_source_cannot_be_rescued_by_correct_raw(array $changes): void
    {
        $source = $this->source(changes: $changes);
        $this->assertSame('FAILED', $source->validation['publication_state']);
        $this->reject(fn () => $this->save($source));
    }

    public static function tamperCases(): array
    {
        return array_map(fn ($name) => [$name], ['raw same length', 'raw size', 'raw hash metadata', 'source manifest', 'source marker',
            'derived token', 'derived manifest', 'derived marker', 'receipt bytes', 'receipt missing', 'receipt manifest', 'receipt unknown time']);
    }

    #[DataProvider('tamperCases')]
    public function test_external_hashes_and_reprojection_reject_tampering_and_missing_receipt(string $case): void
    {
        $source = $this->source('PJ0315');
        $saved = $this->save($source);
        $receiptHash = $saved->receiptSha256;
        if ($case === 'raw same length') {
            $this->mutate($source->path, 'raw.bin', str_replace('90.10', '91.10', EvidenceFiles::read($source->path, 'raw.bin')));
        } elseif ($case === 'raw size') {
            $this->mutate($source->path, 'raw.bin', EvidenceFiles::read($source->path, 'raw.bin').' ');
        } elseif ($case === 'raw hash metadata') {
            $capture = EvidenceFiles::object(EvidenceFiles::read($source->path, 'capture.json'));
            $capture['raw_seal']['sha256'] = str_repeat('0', 64);
            $this->mutate($source->path, 'capture.json', EvidenceFiles::json($capture));
        } elseif ($case === 'source manifest' || $case === 'source marker') {
            $name = $case === 'source manifest' ? 'manifest.json' : 'COMPLETE.json';
            $this->mutate($source->path, $name, '{}');
        } elseif ($case === 'derived token') {
            $this->mutate($saved->path, 'attribute-observations.jsonl', str_replace('90.10', '91.10', EvidenceFiles::read($saved->path, 'attribute-observations.jsonl')));
        } elseif ($case === 'derived manifest' || $case === 'derived marker') {
            $this->mutate($saved->path, $case === 'derived manifest' ? 'manifest.json' : 'COMPLETE.json', '{}');
        } elseif ($case === 'receipt missing') {
            // Retain the artificial receipt under a different name, never delete it.
            $this->assertTrue(rename($saved->path.'/publication-receipt.json', $saved->path.'/retained-receipt.json'));
        } else {
            $receipt = EvidenceFiles::object(EvidenceFiles::read($saved->path, 'publication-receipt.json'));
            if ($case === 'receipt unknown time') {
                $receipt['publication_observed_at'] = null;
            } elseif ($case === 'receipt manifest') {
                $receipt['manifest_sha256'] = str_repeat('0', 64);
            } else {
                $receipt['bundle_id'] = str_repeat('0', 32);
            }
            $bytes = EvidenceFiles::json($receipt);
            $this->mutate($saved->path, 'publication-receipt.json', $bytes);
            // Even an externally supplied updated hash cannot legitimize a contradictory receipt.
            if ($case !== 'receipt bytes') {
                $receiptHash = hash('sha256', $bytes);
            }
        }
        $this->reject(fn () => (new AttributeEvidenceVerifier)->verify($saved->path, $saved->manifestSha256, $source->path, $source->manifestSha256, $receiptHash));
    }

    public function test_same_raw_has_distinct_captures_and_bundle_collision_does_not_overwrite(): void
    {
        $first = $this->source();
        $second = $this->source();
        $this->assertNotSame($first->captureId, $second->captureId);
        $this->assertSame(hash_file('sha256', $first->path.'/raw.bin'), hash_file('sha256', $second->path.'/raw.bin'));
        $store = new AttributeEvidenceStore($this->directory()->path());
        $id = str_repeat('a', 32);
        $saved = $store->save($first->path, $first->manifestSha256, $this->scenario(), $id);
        $snapshot = $this->snapshot($saved->path);
        $this->reject(fn () => $store->save($second->path, $second->manifestSha256, $this->scenario(), $id));
        $this->assertSame($snapshot, $this->snapshot($saved->path));
        $this->assertSame($saved->verification, $this->verify($saved, $first));
    }

    public static function unsafePaths(): array
    {
        return array_map(fn ($path) => [$path], ['', '.', '..', 'relative', '/tmp', '/tmp/../tmp', '/home/shinya', '/home/shinya/Desktop',
            '/home/shinya/Desktop/Trash', '/var/www/NeoBicycleRaceInformationWeb', '/proc', '/tmp/no-such-stat39-directory']);
    }

    #[DataProvider('unsafePaths')]
    public function test_protected_invalid_missing_and_mount_roots_are_rejected_before_writes(string $path): void
    {
        $this->reject(fn () => new AttributeEvidenceStore($path));
    }

    public function test_symlink_cannot_be_used_as_root_or_existing_bundle(): void
    {
        $link = LinkDirectory::create();
        $this->links[] = $link;
        $target = $this->directory();
        $id = str_repeat('b', 32);
        $link->createLink($id, $target);
        $before = $this->snapshot($target->path());
        $this->reject(fn () => new AttributeEvidenceStore($link->path().'/'.$id));
        $source = $this->source();
        $this->reject(fn () => (new AttributeEvidenceStore($link->path()))->save($source->path, $source->manifestSha256, $this->scenario(), $id));
        $this->assertSame($before, $this->snapshot($target->path()));
    }

    public function test_write_failure_code_drift_and_source_drift_retain_failed_evidence_without_publication(): void
    {
        foreach (['write', 'code', 'source', 'manifest', 'marker', 'publication', 'receipt'] as $failure) {
            $source = $this->source();
            $root = $this->directory();
            $store = new class($root->path(), $failure, $source->path) extends AttributeEvidenceStore
            {
                private int $calls = 0;

                public function __construct(string $root, private string $failure, private string $source)
                {
                    parent::__construct($root);
                }

                protected function codeSeal(): array
                {
                    $seal = parent::codeSeal();
                    if ($this->failure === 'code' && ++$this->calls > 1) {
                        $seal['projection'] = 'ARTIFICIAL_DRIFT';
                    }

                    return $seal;
                }

                protected function write(string $directory, string $name, string $bytes): void
                {
                    if (($this->failure === 'write' && $name === 'validation.json') || ($this->failure === 'receipt' && $name === 'publication-receipt.json')
                        || ($this->failure === 'publication' && $name === 'COMPLETE.json')) {
                        throw new RuntimeException('Artificial persistence failure.');
                    }
                    if (($this->failure === 'manifest' && $name === 'manifest.json') || ($this->failure === 'marker' && $name === 'COMPLETE.json')) {
                        $bytes = '{}';
                    }
                    parent::write($directory, $name, $bytes);
                    if ($this->failure === 'source' && $name === 'manifest.json') {
                        file_put_contents($this->source.'/raw.bin', 'ARTIFICIAL SOURCE DRIFT');
                    }
                }
            };
            $id = str_repeat('c', 32);
            $this->reject(fn () => $store->save($source->path, $source->manifestSha256, $this->scenario(), $id));
            $this->assertFileExists($root->path().'/'.$id.'/FAILED.json');
            $this->assertFileExists($root->path().'/'.$id.'/source-reference.json');
            $this->assertFileDoesNotExist($root->path().'/'.$id.'/publication-receipt.json');
            if (! in_array($failure, ['marker', 'receipt'], true)) {
                $this->assertFileDoesNotExist($root->path().'/'.$id.'/COMPLETE.json');
            }
        }
    }

    public function test_nonwritable_root_and_original_namespace_are_refused_without_fallback(): void
    {
        $source = $this->source();
        $root = $this->directory();
        $this->assertTrue(chmod($root->path(), 0500));
        try {
            $before = $this->snapshot($root->path());
            $this->reject(fn () => new AttributeEvidenceStore($root->path()));
            $this->assertSame($before, $this->snapshot($root->path()));
        } finally {
            $this->assertTrue(chmod($root->path(), 0700));
        }
        $before = $this->snapshot($source->path);
        $this->reject(fn () => (new AttributeEvidenceStore($source->path))->save($source->path, $source->manifestSha256, $this->scenario()));
        $this->assertSame($before, $this->snapshot($source->path));
    }

    public function test_artificial_2026_target_is_rejected_before_any_capture_io(): void
    {
        $root = $this->directory();
        $before = $this->snapshot($root->path());
        try {
            new RaceTarget('20260101', '22', 1, $this->registrations());
            $this->fail('Forbidden synthetic target accepted.');
        } catch (InvalidArgumentException) {
            $this->assertSame($before, $this->snapshot($root->path()));
        }
    }

    public function test_independent_process_repeated_save_and_verify_has_a_real_128m_limit(): void
    {
        $code = 'require $argv[1]; require $argv[2]; echo json_encode(\\Tests\\Unit\\Domain\\Keirin\\Scraping\\Stat39PrestartAttributeEvidencePhase1Test::memoryWork(), JSON_THROW_ON_ERROR);';
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=128M', '-r', $code, dirname(__DIR__, 5).'/vendor/autoload.php', __FILE__]);
        $process->setTimeout(120);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame('', $process->getErrorOutput());
        $report = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('128M', $report['memory_limit']);
        $this->assertSame(12, $report['verified_bundles']);
        $this->assertLessThan(128 * 1024 * 1024, $report['peak_memory_bytes']);
        EvidenceFiles::writeNew($this->directory()->path(), 'independent-memory-report.json', EvidenceFiles::json($report));
    }

    public static function memoryWork(): array
    {
        $test = new self('test_independent_process_repeated_save_and_verify_has_a_real_128m_limit');
        $reason = 'PROCESS_FAILURE';
        try {
            for ($i = 0; $i < 12; $i++) {
                $source = $test->source($i % 2 === 0 ? 'JSJ017' : 'PJ0315');
                $snapshot = $test->snapshot($source->path);
                $saved = $test->save($source);
                if ($test->verify($saved, $source) !== $saved->verification || $test->snapshot($source->path) !== $snapshot) {
                    throw new RuntimeException('Offline verification or original invariance failed.');
                }
            }

            $reason = 'PROCESS_COMPLETE';

            return ['memory_limit' => ini_get('memory_limit'), 'peak_memory_bytes' => memory_get_peak_usage(true), 'verified_bundles' => $i, 'pid' => getmypid()];
        } finally {
            $test->retire($reason);
        }
    }

    private function directory(): Directory
    {
        return $this->owned[] = Directory::create();
    }

    private function retire(string $reason): void
    {
        foreach ($this->links as $link) {
            $link->retire($link->path(), $reason);
        }
        foreach ($this->owned as $directory) {
            $directory->retire($directory->path(), $reason);
        }
    }

    private function registrations(): array
    {
        return [1 => '000001', 2 => '000002', 3 => '000003', 4 => '000004', 6 => '000006'];
    }

    private function targets(string $source): array
    {
        return array_map(fn ($n) => new RaceTarget('20251231', '22', $n, $this->registrations()), $source === 'JSJ017' ? [1, 2] : [1]);
    }

    private function source(string $type = 'JSJ017', ?string $raw = null, array $changes = []): StoredCapture
    {
        $uri = 'https://artificial.invalid/card'.($type === 'JSJ017' ? '?encp=artificial-secret&type=JSJ017' : '');
        $input = ['source' => $type, 'raw' => $raw ?? ($type === 'JSJ017' ? EvidenceFiles::json($this->jsj()) : $this->pj()),
            'targets' => $this->targets($type), 'requestUri' => $uri, 'effectiveUri' => $uri,
            'method' => $type === 'JSJ017' ? 'GET' : 'POST', 'parameters' => ['encp' => 'artificial-secret', $type === 'JSJ017' ? 'type' : 'disp' => $type],
            'httpStatus' => 200, 'redirectDetected' => false, 'fetchedAt' => '2025-12-31T09:50:00+09:00',
            'scheduleTimezone' => 'Asia/Tokyo', 'clockEvidence' => 'SYNTHETIC_CLOCK_CONFIRMED'];
        $policy = ['route' => ['scheme' => 'https', 'host' => 'artificial.invalid', 'port' => 443, 'path' => '/card'], 'method' => $input['method']];

        return (new CaptureStore($this->directory()->path(), $policy))->save(new SyntheticCapture(...array_replace($input, $changes)));
    }

    private function save(StoredCapture $source): StoredAttributeEvidence
    {
        return (new AttributeEvidenceStore($this->directory()->path()))->save($source->path, $source->manifestSha256, $this->scenario());
    }

    private function verify(StoredAttributeEvidence $saved, StoredCapture $source): array
    {
        return (new AttributeEvidenceVerifier)->verify($saved->path, $saved->manifestSha256, $source->path, $source->manifestSha256, $saved->receiptSha256);
    }

    private function rows(StoredAttributeEvidence $saved): array
    {
        return array_map(fn ($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), explode("\n", rtrim(EvidenceFiles::read($saved->path, 'attribute-observations.jsonl'), "\n")));
    }

    private function project(): array
    {
        return (new AttributeProjector)->project('JSJ017', EvidenceFiles::json($this->jsj()), $this->targets('JSJ017'), str_repeat('a', 32), str_repeat('b', 64));
    }

    private function jsj(): array
    {
        $entries = [];
        foreach ($this->registrations() as $bike => $registration) {
            $entries[] = ['syaban' => $bike, 'senNo' => $registration, 'kyaku' => '人工'];
        }

        return ['resultCd' => 0, 'keirinCd' => '22', 'kaisaihi' => '20251231', 'reqprm' => ['bkcd' => '22', 'kday' => '20251231'],
            'rInfo' => [['raceNo' => 1, 'stTime' => '10:00', 'sInfo' => $entries], ['raceNo' => 2, 'stTime' => '10:30', 'sInfo' => $entries]]];
    }

    private function pj(array $scores = ['90.10', '90.1', '"90.10"', '0', '91'], ?string $frameToken = '1'): string
    {
        $entries = $summary = [];
        foreach ($this->registrations() as $bike => $registration) {
            $summary[] = ['carNum' => $bike, 'numPlayer' => $registration];
            $entries[] = '{"syaban":'.$bike.',"sensyuRegistNo":"'.$registration.'","kyakusitu":"人工",'
                .'"decoy":{"heikinTokuten":90.10},"text":'.json_encode('heikinTokuten " } { 90.10', JSON_THROW_ON_ERROR).','
                .($frameToken === null ? '' : '"wakuban":'.$frameToken.',').'"heikinTokuten":'.$scores[count($entries)].'}';
        }
        $context = ['C0201data' => ['selKaisai' => '20251231', 'selKjyoCd' => '22', 'selRaceNo' => 1,
            'C0201racedtl' => ['aftStartTime' => '10:00', 'C0201sensyu' => $summary]]];

        return '<html><script>jsonData["PC0201"] = '.json_encode($context, JSON_THROW_ON_ERROR)
            .';jsonData["PJ0315"] = {"decoy":[{"heikinTokuten":90.10}],"sensyuTypeInfo":['.implode(',', $entries).']};</script></html>';
    }

    private function scenario(array $changes = []): array
    {
        $scenario = array_replace(['time_basis' => 'SIMULATED_SCENARIO', 'clock_evidence' => 'SIMULATED_CLOCK_WITH_BOUNDS', 'schedule_timezone' => 'Asia/Tokyo',
            'response_received_at' => '2025-12-31T09:50:00+09:00', 'raw_saved_at' => '2025-12-31T09:50:01+09:00',
            'validation_completed_at' => '2025-12-31T09:50:02+09:00', 'publication_completed_at' => '2025-12-31T09:50:03+09:00',
            'prediction_as_of' => '2025-12-31T09:55:00+09:00', 'uncertainty_microseconds' => array_fill_keys([
                'response_received_at', 'raw_saved_at', 'validation_completed_at', 'publication_completed_at', 'prediction_as_of', 'observed_scheduled_start_at',
            ], 0)], $changes);
        if (! array_key_exists('publication_receipt', $changes)) {
            $scenario['publication_receipt'] = ['time_basis' => 'SIMULATED_SCENARIO', 'published_at' => $scenario['publication_completed_at']];
        }

        return $scenario;
    }

    private function snapshot(string $path): array
    {
        $result = [];
        foreach (array_diff(scandir($path), ['.', '..']) as $name) {
            $result[$name] = hash_file('sha256', $path.'/'.$name);
        }

        return $result;
    }

    private function mutate(string $path, string $name, string $bytes): void
    {
        $this->assertSame(strlen($bytes), file_put_contents($path.'/'.$name, $bytes));
    }

    private function reject(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Unsafe or unverifiable attribute evidence accepted.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
    }
}
