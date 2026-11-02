<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\ChronologicalValidation\BundleRules;
use App\Domain\Keirin\Backtest\ChronologicalValidation\FileResultSource;
use App\Domain\Keirin\Backtest\ChronologicalValidation\HistoryMemoryBudget;
use App\Domain\Keirin\Backtest\ChronologicalValidation\HistoryReader;
use App\Domain\Keirin\Backtest\ChronologicalValidation\Json;
use App\Domain\Keirin\Backtest\ChronologicalValidation\RacecardSource;
use App\Domain\Keirin\Backtest\ChronologicalValidation\ResultMatcher;
use App\Domain\Keirin\Backtest\ChronologicalValidation\RunVerifier;
use App\Domain\Keirin\Backtest\ChronologicalValidation\Scope;
use App\Domain\Keirin\Backtest\ChronologicalValidation\Store;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\HistoryAggregator;
use App\Domain\Keirin\Statistics\Repositories\Batch03HistoricalRaceRepository;
use App\Domain\Keirin\Statistics\Support\Batch04CalculatorSupport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\ChronologicalFixture;

/** Artificial review regressions; original files are never modified or deleted. */
final class ChronologicalReviewFixTest extends TestCase
{
    public function test_real_working_budget_excess_is_not_disabled_by_relative_accounting(): void
    {
        $budget = new HistoryMemoryBudget(1024 * 1024);
        $retained = str_repeat('x', 2 * 1024 * 1024);
        $this->reject(fn () => $budget->check(), 'Offline history memory budget exhausted');
        $this->assertSame(2 * 1024 * 1024, strlen($retained));
        $this->assertGreaterThan(0, $budget->baselineBytes);
        $expected = ini_get('memory_limit') === '-1' ? null : (int) ini_parse_quantity(ini_get('memory_limit'));
        $this->assertSame($expected, $budget->phpLimitBytes);
    }

    public function test_default_96m_working_limit_fails_explicitly_in_a_finite_dedicated_process(): void
    {
        $code = 'require '.var_export(dirname(__DIR__, 5).'/vendor/autoload.php', true).';'
            .'$b=new \\App\\Domain\\Keirin\\Backtest\\ChronologicalValidation\\HistoryMemoryBudget;'
            .'$x=str_repeat("x",97*1024*1024);'
            .'try{$b->check();exit(2);}catch(\\RuntimeException $e){'
            .'echo json_encode(["reason"=>$e->getMessage(),"baseline"=>$b->baselineBytes,"working_limit"=>$b->workingLimitBytes,"memory_limit"=>ini_get("memory_limit"),"peak"=>memory_get_peak_usage(true)]);exit(0);}';
        $p = new Process([PHP_BINARY, '-d', 'memory_limit=256M', '-r', $code]);
        $p->setTimeout(10);
        $p->run();
        $this->assertSame(0, $p->getExitCode(), $p->getErrorOutput());
        $r = json_decode($p->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Offline history memory budget exhausted; no history truncation or fallback.', $r['reason']);
        $this->assertSame(96 * 1024 * 1024, $r['working_limit']);
        $this->assertSame('256M', $r['memory_limit']);
        $f = new ChronologicalFixture([5]);
        Json::write($f->root.'/default-working-budget.json', Json::encode($r));
    }

    public function test_unknown_c1_coverage_stays_left_truncated_without_changing_legacy_entry(): void
    {
        $f = new ChronologicalFixture([5]);
        $target = ['race_id' => 100, 'meeting_id' => 999, 'player_id' => 1, 'race_date' => '2026-01-01',
            'meeting_start' => '2026-01-01', 'meeting_end' => '2026-01-03', 'input_as_of' => '2026-01-01T11:55:00+09:00'];
        $a = new HistoryAggregator;
        $r = $a->aggregateChronological($target, [], new Scope(...$f->manifest['scope']));
        $this->assertSame('LEFT_TRUNCATED', $r['status']);
        $this->assertSame([null, null, null, null], $r['values']);
    }

    public function test_previous_day_release_at_initial_day_is_a_valid_initial_source(): void
    {
        $f = new ChronologicalFixture([5]);
        $e = $this->event($f);
        $e['race']['race_date'] = '2025-12-31';
        $e['race']['scheduled_start_at'] = '2025-12-31T12:00:00+09:00';
        $e['logical_available_from'] = '2026-01-01';
        $this->history($f, [$e]);
        $s = new Store($f->root.'/store');
        $this->assertSame('OFFLINE_CONNECTION_COMPLETED', $this->executeFixture($f, $s)['status']);
    }

    #[DataProvider('initialFailures')]
    public function test_initial_epoch_cannot_seal_current_future_or_unreleased_results(string $day, string $release): void
    {
        $f = new ChronologicalFixture([5]);
        $event = $this->event($f);
        $event['race']['race_date'] = $day;
        $event['race']['scheduled_start_at'] = $day.'T12:00:00+09:00';
        $event['logical_available_from'] = $release;
        $this->history($f, [$event]);
        $s = new Store($f->root.'/store');
        $source = $f->source($s);
        $this->reject(fn () => $this->executeFixture($f, $s, $source), 'Initial epoch boundary violation');
        $this->assertSame([], $source->calls);
        $this->assertSame([], glob($s->root.'/objects/*-p[01]'));
        $this->assertNotEmpty(glob($s->root.'/stages/failure-*/FAILED.json'));
    }

    public static function initialFailures(): iterable
    {
        yield ['2026-01-01', '2026-01-01'];
        yield ['2026-01-02', '2026-01-02'];
        yield ['2025-12-31', '2026-01-02'];
    }

    #[DataProvider('incompleteHistory')]
    public function test_c1_preserves_known_incomplete_history_and_declared_coverage(string $case, string $status, array $values): void
    {
        $f = new ChronologicalFixture([5]);
        $a = $this->event($f);
        $a['entries'][0]['rank'] = 1;
        $a['entries'][0]['winning_technique'] = "\u{9003}\u{3052}";
        $events = [$a];
        if (in_array($case, ['provisional', 'unverified', 'same_meeting', 'other_person', 'outside'], true)) {
            $b = $a;
            $b['race']['original_race_id'] += 999;
            $b['race']['scheduled_start_at'] = $b['race']['race_date'].'T13:00:00+09:00';
            $b['race_status'] = 'PROVISIONAL';
            if ($case === 'unverified') {
                $b['race_status'] = 'CONFIRMED';
                $b['verified'] = false;
            }
            if ($case === 'same_meeting') {
                $b['race']['meeting_id'] = 999;
            }
            if ($case === 'other_person') {
                foreach ($b['entries'] as &$e) {
                    $e['original_player_id'] += 100;
                } unset($e);
            }
            if ($case === 'outside') {
                $b['race']['race_date'] = '2025-08-01';
                $b['race']['scheduled_start_at'] = '2025-08-01T12:00:00+09:00';
            }
            $events[] = $b;
        }
        $this->history($f, $events, $case === 'truncated' ? '2025-12-01' : ($case === 'unknown_start' ? null : '2022-01-01'));
        $s = new Store($f->root.'/store');
        $this->executeFixture($f, $s);
        $input = $s->get($s->reference(basename(glob($s->root.'/objects/*-input')[0])))['data'];
        $h = $input['c1_history'][1001];
        $this->assertSame($status, $h['status']);
        $this->assertSame($values, $h['values']);
    }

    public static function incompleteHistory(): iterable
    {
        foreach (['provisional', 'unverified'] as $c) {
            yield [$c, 'PARTIAL_HISTORY', [null, null, null, null]];
        }
        foreach (['complete', 'same_meeting', 'other_person', 'outside'] as $c) {
            yield [$c, 'AVAILABLE', [1, 0, 0, 0]];
        }
        yield ['truncated', 'LEFT_TRUNCATED', [null, null, null, null]];
    }

    public function test_position_and_pair_history_use_declared_jst_lower_boundary(): void
    {
        $f = new ChronologicalFixture([5]);
        $a = $this->event($f);
        $a['race']['race_date'] = '2025-12-01';
        $a['race']['scheduled_start_at'] = '2025-12-01T00:00:00+09:00';
        $a['logical_available_from'] = '2025-12-02';
        $b = $a;
        $b['race']['original_race_id'] += 1;
        $b['race']['race_date'] = '2025-11-30';
        $b['race']['scheduled_start_at'] = '2025-11-30T23:59:59.999999+09:00';
        $this->history($f, [$a, $b], '2025-12-01');
        [$h] = $this->project($f);
        $this->assertCount(1, $h['pairs']['1:2']);
        $this->assertSame(5, $h['positions'][1001]->groups['FIELD_BASELINE']['sample_count']);
        $this->assertSame($a['race']['original_race_id'], $h['used'][0]['race_id']);
        $this->assertSame('BEFORE_DECLARED_HISTORY_RANGE', $h['excluded'][0]['reason']);
    }

    #[DataProvider('scores')]
    public function test_competition_context_and_pair_scores_match_existing_normalization(array $scores): void
    {
        $f = new ChronologicalFixture([5]);
        $event = $this->event($f);
        foreach ($scores as $i => $score) {
            $event['entries'][$i]['race_score_raw'] = $score;
        }
        $this->history($f, [$event]);
        [$h, $p, $epoch, $s] = $this->project($f);
        $old = new \ReflectionMethod(Batch03HistoricalRaceRepository::class, 'scoreContextSummary');
        $entries = array_map(fn ($e) => ['race_entry_id' => $e['original_entry_id'], 'player_id' => $e['original_player_id'], 'bike_number' => $e['bike'], 'grade' => $e['grade'],
            'race_score' => $f->container->make(Batch04CalculatorSupport::class)->raceScore($e['race_score_raw'])], $event['entries']);
        $expected = $old->invoke($f->container->make(Batch03HistoricalRaceRepository::class), $event['race']['original_race_id'], 5, $entries);
        $row = $h['batch03'][1][0];
        $this->assertSame($expected['score_mean'], $row->raceScoreMean);
        $this->assertSame($expected['score_max'], $row->raceScoreMax);
        $this->assertSame($expected['score_stddev_pop'], $row->raceScoreStddevPop);
        $complete = ! in_array(null, $scores, true) && ! in_array(0, $scores, true);
        $this->assertSame($complete ? (float) (array_sum($scores) / 5) : null, $row->raceScoreMean);
        $this->assertSame($complete ? 1.0 : null, $row->subjectScorePercentile);
        $this->assertSame($complete ? $row->finishStrengthPercentile - 1.0 : null, $row->scoreExpectationResidual);
        $this->assertSame($entries[0]['race_score'], $h['pairs']['1:2'][0]->firstRaceScore);
        $this->executeFixture($f, $s);
        $input = $s->get($s->reference(basename(glob($s->root.'/objects/*-input')[0])))['data'];
        $this->assertArrayHasKey('COMPETITION_CONTEXT', $input['stat_sources'][1001]['STAT-31']['features']);
        $context = $input['stat_sources'][1001]['STAT-31']['features']['COMPETITION_CONTEXT'];
        $this->assertSame($expected['score_mean'], $context['mean_race_score_mean']);
        $this->assertSame($expected['score_max'], $context['mean_race_score_max']);
        $this->assertSame($expected['score_stddev_pop'], $context['mean_race_score_stddev_pop']);
    }

    public static function scores(): iterable
    {
        yield [[100, 90, 80, 70, null]];
        yield [[100, 90, 80, 70, 0]];
        yield [[100, 90, 80, 70, 60]];
        yield [[99, 98, 97, 96, 95]];
        yield [[99.0, 98.0, 97.0, 96.0, 95.0]];
        yield [['99', '98', '97', '96', '95']];
    }

    #[DataProvider('forbiddenFields')]
    public function test_prediction_manifest_rejects_forbidden_nested_fields_before_publication(string $where): void
    {
        $f = new ChronologicalFixture([5]);
        if ($where === 'candidate') {
            $f->manifest['candidates'][0]['rank'] = 1;
        }
        if ($where === 'context') {
            $f->manifest['candidates'][0]['context']['resultFlg'] = 1;
        }
        if ($where === 'entry') {
            $f->manifest['candidates'][0]['entries'][0]['status'] = 'FINISHED';
        }
        $s = new Store($f->root.'/store');
        $source = $f->source($s);
        $this->reject(fn () => $this->executeFixture($f, $s, $source), 'Unapproved input keys');
        $this->assertSame([], $source->calls);
        $this->assertSame([], glob($s->root.'/objects/*-input'));
        $this->assertSame([], glob($s->root.'/objects/*-p[01]'));
        $this->assertNotEmpty(glob($s->root.'/stages/failure-*/FAILED.json'));
    }

    public static function forbiddenFields(): iterable
    {
        yield ['candidate'];
        yield ['context'];
        yield ['entry'];
    }

    #[DataProvider('missingIdentity')]
    public function test_legitimate_missing_identity_is_inability_not_an_input_schema_error(string $field): void
    {
        $f = new ChronologicalFixture([5]);
        $f->manifest['candidates'][0]['entries'][0][$field] = null;
        $s = new Store($f->root.'/store');
        $out = $this->executeFixture($f, $s);
        $this->assertSame(1, $out['summary']['coverage'][BundleRules::METHODS[0]]['N']);
        $this->assertSame([], glob($s->root.'/objects/*-input'));
        $this->assertFalse($out['summary']['is_final']);
    }

    public static function missingIdentity(): iterable
    {
        foreach (['original_entry_id', 'original_player_id', 'external_player_id'] as $field) {
            yield [$field];
        }
    }

    public function test_raw_display_counts_are_retained_as_evidence_but_not_projected_as_inputs(): void
    {
        $f = new ChronologicalFixture([5]);
        $candidate = $f->manifest['candidates'][0];
        $candidate['capture'] = $f->capture($candidate, displayCounts: true);
        $before = Json::seal($candidate['capture']['private_ref']);
        $projection = $f->container->make(RacecardSource::class)->project($candidate, new Scope(...$f->manifest['scope']));
        $this->assertStringContainsString('nigeCnt', file_get_contents($candidate['capture']['private_ref']));
        $expected = ['original_entry_id', 'original_player_id', 'external_player_id', 'bike', 'frame_number', 'grade', 'race_score_raw'];
        sort($expected);
        foreach ($projection['entries'] as $entry) {
            $keys = array_keys($entry);
            sort($keys);
            $this->assertSame($expected, $keys);
        }
        $this->assertSame($before, Json::seal($candidate['capture']['private_ref']));
        $this->assertSame($before, $projection['capture_refs'][0]['raw']);
    }

    public function test_unresolved_race_ids_are_not_verified_even_for_empty_cancellation(): void
    {
        $f = new ChronologicalFixture([5]);
        $candidate = $f->manifest['candidates'][0];
        $r = $f->results['2026-01-01'][0];
        $matcher = new ResultMatcher;
        foreach ([[null, null], [null, 100], [100, null]] as [$a,$b]) {
            $c = [...$candidate, 'original_race_id' => $a];
            $x = [...$r, 'original_race_id' => $b];
            $this->assertFalse($matcher->match($c, $x)['verified']);
            $this->assertFalse($matcher->match($c, [...$x, 'official_status' => 'CANCELLED', 'entries' => []])['verified']);
        }
        $this->assertTrue($matcher->match($candidate, [...$r, 'official_status' => 'CANCELLED', 'entries' => []])['verified']);
        $this->reject(fn () => $matcher->match($candidate, [...$r, 'original_race_id' => 101]), 'Result race/page identity mismatch');
        $this->reject(fn () => $matcher->match([...$candidate, 'original_race_id' => null], [...$r, 'fetched_at' => 'bad']), 'Timezone-qualified instant required');
    }

    #[DataProvider('sameVersionChanges')]
    public function test_existing_result_version_compares_new_content_and_evidence(string $field): void
    {
        $f = new ChronologicalFixture([5]);
        $s = new Store($f->root.'/store');
        $this->executeFixture($f, $s);
        $before = $s->reference('d20260101-predictions');
        if ($field === 'rank') {
            $f->results['2026-01-01'][0]['entries'][0]['rank'] = 2;
            $f->results['2026-01-01'][0]['entries'][1]['rank'] = 1;
        }
        if ($field === 'state') {
            $f->results['2026-01-01'][0]['official_status'] = 'PROVISIONAL';
        }
        if ($field === 'evidence') {
            $f->results['2026-01-01'][0]['source_id'] = 'different-capture';
        }
        if ($field === 'identity') {
            $f->results['2026-01-01'][0]['entries'][0]['original_player_id'] = 999;
        }
        $this->reject(fn () => $this->executeFixture($f, $s), $field === 'identity' ? 'Result participant identity mismatch' : 'CONFLICT');
        $this->assertSame($before, $s->reference('d20260101-predictions'));
    }

    public static function sameVersionChanges(): iterable
    {
        foreach (['rank', 'state', 'evidence', 'identity'] as $c) {
            yield [$c];
        }
    }

    public function test_unresolved_race_remains_saved_unverified_and_cannot_become_next_day_history(): void
    {
        $f = new ChronologicalFixture([5, 5, 5, 5]);
        $f->manifest['candidates'][0]['original_race_id'] = null;
        $f->results['2026-01-01'][0]['original_race_id'] = null;
        $s = new Store($f->root.'/store');
        $out = $this->executeFixture($f, $s);
        $this->assertFalse($out['summary']['is_final']);
        $this->assertSame(1, $out['summary']['coverage'][BundleRules::METHODS[0]]['N']);
        $this->assertFileExists($s->root.'/objects/d20260102-predictions/LOCKED');
        foreach ($s->all() as $ref) {
            $b = $s->get($ref);
            if ($b['kind'] === 'result_bundle' && $b['data']['original_race_id'] === null) {
                $this->assertFalse($b['data']['identity_audit']['verified']);
                $this->assertSame('UNRESOLVED_IDENTITY', $b['data']['identity_audit']['reason']);
                $this->assertNull($b['data']['history_event']);
            }
        }
        $this->assertSame('VERIFIED', (new RunVerifier)->verify($s)['status']);
    }

    public function test_daily_file_seal_change_is_not_silently_ignored_for_unchanged_row_version(): void
    {
        $f = new ChronologicalFixture([5, 5]);
        $s = new Store($f->root.'/store');
        $scope = new Scope(...$f->manifest['scope']);
        foreach (['a', 'b'] as $dir) {
            mkdir($f->root.'/'.$dir, 0700);
        }
        $doc = ['material_kind' => 'SYNTHETIC_TEST', 'date' => '2026-01-01', 'results' => $f->results['2026-01-01']];
        Json::write($f->root.'/a/2026-01-01.json', Json::encode($doc));
        $this->executeFixture($f, $s, new FileResultSource($f->root.'/a', $scope, $s));
        $doc['results'][1]['result_version'] = 2;
        $doc['results'][1]['source_id'] = 'new-second-capture';
        Json::write($f->root.'/b/2026-01-01.json', Json::encode($doc));
        $this->reject(fn () => $this->executeFixture($f, $s, new FileResultSource($f->root.'/b', $scope, $s)), 'CONFLICT');
    }

    #[DataProvider('invalidVersions')]
    public function test_result_updates_reject_gaps_regressions_and_wrong_parent_without_reinference(string $case): void
    {
        $f = new ChronologicalFixture([5, 5]);
        $s = new Store($f->root.'/store');
        $this->executeFixture($f, $s);
        $before = $this->sealedInputs($s);
        $parents = [];
        foreach ($s->all() as $ref) {
            $b = $s->get($ref);
            if ($b['kind'] === 'result_bundle') {
                $parents[$b['data']['external_race_key']] = $ref;
            }
        }
        $r = $this->revision($f, 0, $case === 'regression' ? 'PROVISIONAL' : 'CORRECTED', $case === 'gap' ? 3 : 2);
        $parent = $parents[$f->results['2026-01-01'][$case === 'parent' ? 1 : 0]['external_race_key']];
        $reason = match ($case) {
            'gap' => 'Invalid correction parent','parent' => 'Result race/page identity mismatch',default => 'Invalid official result transition'
        };
        $this->reject(fn () => $f->coordinator()->correct($s, $parent, $r), $reason);
        foreach ($before as $id => $ref) {
            $this->assertSame($ref, $s->reference($id));
        }
    }

    public static function invalidVersions(): iterable
    {
        yield ['gap'];
        yield ['parent'];
        yield ['regression'];
    }

    public function test_foreign_epoch_and_seal_are_rejected_without_replacing_the_base_epoch(): void
    {
        $f = new ChronologicalFixture([5, 5, 5, 5]);
        $s = new Store($f->root.'/store');
        $this->reject(fn () => $f->coordinator(fn ($at) => $at === 'AFTER_DAY' ? throw new RuntimeException('DAY_STOP') : null)->execute($f->manifest, $f->model(), Json::hash($f->model()->artifact), $s, $f->source($s)), 'DAY_STOP');
        $parent = $s->reference(basename(glob($s->root.'/objects/*-result-v1')[0]));
        $old = $s->get($parent)['data'];
        $i = array_search($old['external_race_key'], array_column($f->results['2026-01-01'], 'external_race_key'), true);
        $r = $this->revision($f, $i, 'CORRECTED', 2);
        $base = $s->reference('d20260101-next-epoch');
        $this->reject(fn () => $f->coordinator()->correct($s, $parent, $r, $s->reference('initial-history'), '2026-01-02'), 'Unrelated epoch selection parent');
        $this->reject(fn () => $f->coordinator()->correct($s, $parent, $r, [...$base, 'manifest_sha256' => str_repeat('0', 64)], '2026-01-02'), 'Unrelated epoch selection parent');
        $this->reject(fn () => $s->get([...$base, 'manifest_sha256' => str_repeat('0', 64)]), 'Bundle reference hash mismatch');
        $this->assertSame($base, $s->reference('d20260101-next-epoch'));
        $this->assertFalse($s->has('d20260102-epoch-selection-1'));
    }

    #[DataProvider('updateInterruptions')]
    public function test_multiple_updates_use_explicit_epoch_lineage_and_resume_without_replacing_predictions(string $stage): void
    {
        $f = new ChronologicalFixture([5, 5, 5, 5]);
        $f->manifest['candidates'][3]['meeting_id'] = 10000;
        $f->manifest['candidates'][3]['meeting_start'] = '2026-01-02';
        $f->results['2026-01-01'][0]['official_status'] = 'PROVISIONAL';
        $s = new Store($f->root.'/store');
        $hash = Json::hash($f->model()->artifact);
        $this->reject(fn () => $f->coordinator(fn ($at) => $at === 'AFTER_DAY' ? throw new RuntimeException('REVIEW_DAY_ONE_STOP') : null)
            ->execute($f->manifest, $f->model(), $hash, $s, $f->source($s)), 'REVIEW_DAY_ONE_STOP');
        $before = $this->sealedInputs($s);
        $parents = [];
        foreach ($s->all() as $ref) {
            $b = $s->get($ref);
            if ($b['kind'] === 'result_bundle') {
                $parents[$b['data']['external_race_key']] = $ref;
            }
        }
        $epoch = $s->reference('d20260101-next-epoch');
        foreach ([0 => 'CONFIRMED', 1 => 'CORRECTED'] as $i => $status) {
            $revision = $this->revision($f, $i, $status, 2);
            $parent = $parents[$revision['external_race_key']];
            if ($i === 0 && $stage !== 'AFTER_CANDIDATES') {
                $this->reject(fn () => $f->coordinator(fn ($at) => $at === $stage ? throw new RuntimeException('REVIEW_UPDATE_STOP') : null)->correct($s, $parent, $revision, $epoch, '2026-01-02'), 'REVIEW_UPDATE_STOP');
            }
            $updated = $f->coordinator()->correct($s, $parent, $revision, $epoch, '2026-01-02');
            $this->assertSame($updated, $f->coordinator(null, fn () => '2026-11-02T12:00:00+09:00')->correct($s, $parent, $revision, $epoch, '2026-01-02'));
            $epoch = $updated['epoch'];
            unset($revision['source_seal']);
            $f->results['2026-01-01'][$i] = $revision;
        }
        $a = $this->revision($f, 0, 'CORRECTED', 3);
        $v2 = $s->reference(preg_replace('/-v1$/', '-v2', $parents[$a['external_race_key']]['bundle_id']));
        $epoch = $f->coordinator()->correct($s, $v2, $a, $epoch, '2026-01-02')['epoch'];
        unset($a['source_seal']);
        $f->results['2026-01-01'][0] = $a;
        $this->reject(fn () => $f->coordinator(fn ($at) => $at === 'AFTER_CANDIDATES' ? throw new RuntimeException('REVIEW_CANDIDATE_STOP') : null)
            ->execute($f->manifest, $f->model(), $hash, $s, $f->source($s)), 'REVIEW_CANDIDATE_STOP');
        $selected = $s->get($s->reference('d20260102-candidates'))['data']['history_epoch'];
        $this->assertSame($epoch, $selected);
        $done = $f->coordinator()->execute($f->manifest, $f->model(), $hash, $s, $f->source($s));
        $again = $f->coordinator(null, fn () => '2026-11-02T14:00:00+09:00')->execute($f->manifest, $f->model(), $hash, $s, $f->source($s));
        $this->assertSame($done, $again);
        foreach ($before as $id => $seal) {
            $this->assertSame($seal, $s->reference($id));
        }
        $adopted = [];
        foreach ($s->get($selected)['data']['results'] as $ref) {
            $r = $s->get($ref)['data'];
            $this->assertArrayNotHasKey($r['external_race_key'], $adopted);
            $adopted[$r['external_race_key']] = $r['result_version'];
        }
        $this->assertSame(3, $adopted[$a['external_race_key']]);
        $this->assertSame(2, $adopted[$f->results['2026-01-01'][1]['external_race_key']]);
        $input = $s->get($s->reference(basename(glob($s->root.'/objects/d20260102-*-input')[0])))['data'];
        $this->assertSame(2, count(array_filter($input['history_used'], fn ($r) => in_array($r['race_id'], [100, 101], true))));
        $this->assertSame('VERIFIED', (new RunVerifier)->verify($s)['status']);
        $this->assertSame(7, $f->coordinator()->reproduce($s)['evaluations']);
    }

    public static function updateInterruptions(): iterable
    {
        foreach (['AFTER_RESULT_UPDATE', 'AFTER_UPDATE_EVALUATION', 'AFTER_CORRECTION_EPOCH', 'AFTER_CORRECTION_SELECTION', 'AFTER_CANDIDATES'] as $s) {
            yield [$s];
        }
    }

    public function test_finalized_run_can_record_confirmed_new_version_without_mutating_locked_epochs(): void
    {
        $f = new ChronologicalFixture([5]);
        $f->results['2026-01-01'][0]['official_status'] = 'PROVISIONAL';
        $s = new Store($f->root.'/store');
        $old = $this->executeFixture($f, $s);
        $this->assertFalse($old['summary']['is_final']);
        $before = $this->sealedInputs($s);
        $epoch = $s->reference('d20260101-next-epoch');
        $parent = $s->reference(basename(glob($s->root.'/objects/*-result-v1')[0]));
        $r = $this->revision($f, 0, 'CONFIRMED', 2);
        $saved = $f->coordinator()->correct($s, $parent, $r);
        $this->assertNull($saved['epoch']);
        $this->assertSame($saved, $f->coordinator(null, fn () => '2026-11-02T12:00:00+09:00')->correct($s, $parent, $r));
        $this->assertTrue($s->get($saved['evaluation'])['data']['is_final']);
        $this->assertSame($epoch, $s->reference('d20260101-next-epoch'));
        foreach ($before as $id => $ref) {
            $this->assertSame($ref, $s->reference($id));
        }
        $this->assertSame(2, $f->coordinator()->reproduce($s)['evaluations']);
        $this->assertSame('VERIFIED', (new RunVerifier)->verify($s)['status']);
    }

    private function revision(ChronologicalFixture $f, int $i, string $status, int $version): array
    {
        $r = $f->results['2026-01-01'][$i];
        $r['official_status'] = $status;
        $r['result_version'] = $version;
        $r['source_id'] = 'synthetic-revision-'.$i.'-'.$version;
        unset($r['source_seal']);

        return [...$r, 'source_seal' => ['bytes' => strlen(Json::encode($r)), 'sha256' => Json::hash($r)]];
    }

    private function sealedInputs(Store $s): array
    {
        $out = [];
        foreach ($s->all() as $ref) {
            if (in_array($s->get($ref)['kind'], ['input_bundle', 'prediction_bundle'], true)) {
                $out[$ref['bundle_id']] = $ref;
            }
        }

        return $out;
    }

    private function event(ChronologicalFixture $f): array
    {
        foreach (file($f->manifest['history']['sources'][0]['path']) as $line) {
            $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            if ($row['race']['entrant_count'] === 5) {
                return $row;
            }
        }
        throw new RuntimeException('Artificial event missing.');
    }

    private function history(ChronologicalFixture $f, array $events, ?string $from = '2022-01-01'): void
    {
        usort($events, fn ($a, $b) => [Scope::instant($a['race']['scheduled_start_at']), $a['race']['original_race_id']] <=> [Scope::instant($b['race']['scheduled_start_at']), $b['race']['original_race_id']]);
        $path = $f->root.'/review-history-'.bin2hex(random_bytes(8)).'.jsonl';
        Json::write($path, implode('', array_map(fn ($e) => Json::encode($e), $events)));
        $f->manifest['history'] = ['history_event_from' => $from, 'sources' => [['path' => $path, 'seal' => Json::seal($path), 'material_kind' => 'SYNTHETIC_TEST']]];
    }

    private function project(ChronologicalFixture $f): array
    {
        $s = new Store($f->root.'/store');
        $scope = new Scope(...$f->manifest['scope']);
        $p = $f->container->make(RacecardSource::class)->project($f->manifest['candidates'][0], $scope);
        $epoch = [...$f->manifest['history'], 'results' => [], 'logical_available_from' => $scope->from, 'history_event_end_exclusive' => $scope->from.'T00:00:00+09:00'];

        return [$f->container->make(HistoryReader::class)->project($p, $epoch, $s, $scope), $p, $epoch, $s];
    }

    private function executeFixture(ChronologicalFixture $f, Store $s, ?object $source = null): array
    {
        return $f->coordinator()->execute($f->manifest, $f->model(), Json::hash($f->model()->artifact), $s, $source ?? $f->source($s));
    }

    private function reject(callable $work, string $reason): void
    {
        try {
            $work();
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($reason, $e->getMessage());

            return;
        }
        $this->fail('Expected '.$reason);
    }
}
