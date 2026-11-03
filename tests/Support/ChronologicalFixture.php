<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Keirin\Backtest\ChronologicalValidation\BundleRules;
use App\Domain\Keirin\Backtest\ChronologicalValidation\Coordinator;
use App\Domain\Keirin\Backtest\ChronologicalValidation\Json;
use App\Domain\Keirin\Backtest\ChronologicalValidation\PredictionAdapter;
use App\Domain\Keirin\Backtest\ChronologicalValidation\PrimaryEvaluator;
use App\Domain\Keirin\Backtest\ChronologicalValidation\RacecardSource;
use App\Domain\Keirin\Backtest\ChronologicalValidation\ResultMatcher;
use App\Domain\Keirin\Backtest\ChronologicalValidation\ResultSource;
use App\Domain\Keirin\Backtest\ChronologicalValidation\Scope;
use App\Domain\Keirin\Backtest\ChronologicalValidation\StatEngine;
use App\Domain\Keirin\Backtest\ChronologicalValidation\Store;
use App\Domain\Keirin\Backtest\Contracts\EffectBinBoundaryProvider;
use App\Domain\Keirin\Backtest\DTO\Bt03e03FitResultDto;
use App\Domain\Keirin\Backtest\DTO\EffectBinDto;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\HistoryAggregator;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Layout;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\LoadedModel;
use App\Domain\Keirin\Backtest\Services\Bt03e02Contract;
use App\Domain\Keirin\Backtest\Services\Bt03e03Contract;
use Closure;
use Illuminate\Container\Container;
use RuntimeException;

/** Entirely synthetic fixtures. No training, actual race data, DB or HTTP. */
final class ChronologicalFixture
{
    public readonly CompositionResultTemporaryDirectory $temporary;

    public readonly string $root;

    public readonly Container $container;

    public array $manifest;

    public array $results = [];

    public function __construct(array $fields = [5, 7, 9, 6, 8])
    {
        $this->temporary = CompositionResultTemporaryDirectory::create('/tmp');
        $this->root = $this->temporary->path();
        $this->container = new Container;
        $this->container->instance(\Illuminate\Contracts\Container\Container::class, $this->container);
        $this->container->instance(EffectBinBoundaryProvider::class, new class implements EffectBinBoundaryProvider
        {
            public function build(iterable $trainingValues): array
            {
                throw new RuntimeException('Rebin is forbidden in this test.');
            }
        });
        $history = '';
        for ($i = 1; $i <= 60; $i++) {
            $day = Scope::day('2025-10-31')->modify('+'.$i.' days')->format('Y-m-d');
            $n = [7, 9, 5][$i % 3];
            $race = $this->candidate(1000 + $i, $day, $n, 1);
            $entries = $race['entries'];
            foreach ($entries as &$e) {
                $rank = (($e['bike'] + $i) % $n) + 1;
                $e += ['frame_number' => $e['bike'], 'grade' => 'A1', 'race_score_raw' => (string) (100 - $e['bike']),
                    'rank' => $rank, 'status' => 'FINISHED', 'winning_technique' => $rank === 2 ? "\u{30de}\u{30fc}\u{30af}" : ($rank === 1 ? "\u{9003}\u{3052}" : null)];
            }
            unset($e);
            $history .= Json::encode(['material_kind' => 'SYNTHETIC_TEST', 'race' => [...array_intersect_key($race, array_flip(['original_race_id', 'external_race_key', 'race_date', 'track_code', 'race_number', 'entrant_count', 'meeting_id', 'meeting_start', 'meeting_end', 'day_number'])),
                'race_type' => $i % 2 === 0 ? "A\u{7d1a}\u{6c7a}\u{52dd}" : "A\u{7d1a}\u{4e88}\u{9078}", 'scheduled_start_at' => $day.'T12:00:00+09:00', 'sales_close_at' => $day.'T11:55:00+09:00'],
                'context' => $race['context'], 'entries' => $entries, 'race_status' => 'CONFIRMED', 'source_id' => 'synthetic-history-'.$i, 'verified' => true,
                'logical_available_from' => Scope::day($day)->modify('+1 day')->format('Y-m-d'), 'input_fetched_at' => '2026-10-01T00:00:00+09:00', 'fetched_at' => '2026-10-01T00:00:10+09:00',
                'saved_at' => '2026-10-01T00:00:11+09:00', 'confirmed_at' => null]);
        }
        Json::write($this->root.'/history.jsonl', $history);
        $candidates = [];
        foreach ($fields as $i => $n) {
            $day = $i < 3 ? '2026-01-01' : '2026-01-02';
            $candidate = $this->candidate(100 + $i, $day, $n, $i + 1);
            $candidate['meeting_start'] = '2026-01-01';
            $candidate['meeting_end'] = '2026-01-03';
            $candidate['meeting_id'] = 999;
            $candidate['capture'] = $this->capture($candidate);
            $candidates[] = $candidate;
            $this->results[$day][] = ['external_race_key' => $candidate['external_race_key'], 'original_race_id' => $candidate['original_race_id'], 'race_date' => $day,
                'result_version' => 1, 'source_id' => 'synthetic-result-'.$i, 'official_status' => 'CONFIRMED', 'fetched_at' => '2026-11-01T12:00:00+09:00', 'saved_at' => '2026-11-01T12:00:01+09:00',
                'entries' => array_map(fn (array $e): array => [...$e, 'rank' => $e['bike'], 'status' => 'FINISHED', 'winning_technique' => $e['bike'] === 1 ? "\u{9003}\u{3052}" : ($e['bike'] === 2 ? "\u{30de}\u{30fc}\u{30af}" : null)], $candidate['entries'])];
        }
        $this->manifest = ['run_id' => 'synthetic-chronological', 'fixture_root' => $this->root, 'material_kind' => 'SYNTHETIC_TEST', 'scope' => ['kind' => 'SYNTHETIC_TEST', 'from' => '2026-01-01', 'to' => '2026-01-02'],
            'history' => ['history_event_from' => '2022-01-01', 'sources' => [['path' => $this->root.'/history.jsonl', 'seal' => Json::seal($this->root.'/history.jsonl'), 'material_kind' => 'SYNTHETIC_TEST']]], 'candidates' => $candidates];
    }

    public function coordinator(?Closure $checkpoint = null, ?Closure $clock = null): Coordinator
    {
        $this->container->bind(Coordinator::class, fn (Container $c): Coordinator => new Coordinator($c->make(RacecardSource::class),
            $c->make(StatEngine::class), $c->make(PredictionAdapter::class),
            $c->make(PrimaryEvaluator::class), $c->make(ResultMatcher::class),
            $clock ?? fn (): string => '2026-11-01T10:00:00.000000+09:00', $checkpoint));

        return $this->container->make(Coordinator::class);
    }

    public function model(): LoadedModel
    {
        $bins = [];
        foreach ([...Bt03e02Contract::STAT_CODES, ...HistoryAggregator::FEATURES] as $code) {
            $bins[$code] = [new EffectBinDto(1, 'NUMERIC_RANGE', null, 0.0, null, 1), new EffectBinDto(2, 'NUMERIC_RANGE', 0.0, null, null, 1)];
        }
        $layout = new Layout($bins);
        $vector = array_merge(...array_fill(0, 16, [0.05, -0.05]));
        $coefficients = array_fill_keys(Bt03e03Contract::POSITIONS, $vector);

        return new LoadedModel($layout, new Bt03e03FitResultDto(0.1, $coefficients, [], [], [], []), ['kind' => 'SYNTHETIC_FIXED_COEFFICIENTS_NO_TRAINING']);
    }

    public function source(Store $store, ?Closure $change = null): ResultSource
    {
        return new class($this, $store, $change) implements ResultSource
        {
            public array $calls = [];

            public function __construct(private ChronologicalFixture $fixture, private Store $store, private ?Closure $change) {}

            public function read(string $day, array $receipt): iterable
            {
                $r = $this->store->get($receipt)['data'];
                BundleRules::require($r['state'] === 'RESULTS_OPENED', 'Result read before receipt.');
                $this->calls[] = $day;
                foreach ($this->fixture->results[$day] as $row) {
                    if (! isset($r['predictions'][$row['external_race_key']])) {
                        continue;
                    }
                    $row = $this->change === null ? $row : ($this->change)($row);
                    yield [...$row, 'source_seal' => ['bytes' => strlen(Json::encode($row)), 'sha256' => Json::hash($row)]];
                }
            }
        };
    }

    public function capture(array $candidate, ?string $type = null, ?string $time = '12:00', ?string $score = 'normal', bool $displayCounts = false): array
    {
        $type ??= "A\u{7d1a}\u{6c7a}\u{52dd}";
        $pc = ['C0201data' => ['selKaisai' => str_replace('-', '', $candidate['race_date']), 'selKjyoCd' => $candidate['track_code'], 'selRaceNo' => (string) $candidate['race_number'],
            'C0201racedtl' => ['syumoku' => $type, 'aftStartTime' => $time, 'aftBetTime' => $time === null ? null : '11:55',
                'C0201sensyu' => array_map(fn (array $e): array => ['carNum' => (string) $e['bike'], 'numPlayer' => $e['external_player_id']], $candidate['entries'])]]];
        $pj = ['sensyuTypeInfo' => array_map(fn (array $e): array => ['syaban' => (string) $e['bike'], 'sensyuRegistNo' => $e['external_player_id'],
            'wakuban' => (string) $e['bike'], 'kyuhan' => 'A1', 'heikinTokuten' => $score === 'normal' ? (string) (100 - $e['bike']) : $score], $candidate['entries'])];
        if ($displayCounts) {
            foreach ($pj['sensyuTypeInfo'] as &$entry) {
                $entry += ['nigeCnt' => '999', 'syouritu' => '99.9'];
            }
            unset($entry);
        }
        $path = $this->root.'/synthetic-pj0315-'.bin2hex(random_bytes(8)).'.html';
        Json::write($path, '<html><script>var jsonData = {}; jsonData["PC0201"] = '.json_encode($pc, JSON_THROW_ON_ERROR).'; jsonData["PJ0315"] = '.json_encode($pj, JSON_THROW_ON_ERROR).';</script></html>');

        return ['capture_id' => 'synthetic-capture-'.$candidate['original_race_id'], 'source' => 'PJ0315', 'method' => 'POST', 'material_kind' => 'SYNTHETIC_TEST', 'source_status' => 'VERIFIED', 'parser_version' => 'SYNTHETIC-PJ0315-v1',
            'private_ref' => $path, 'raw' => Json::seal($path), 'content_type' => 'text/html; charset=UTF-8', 'fetched_at' => '2026-10-01T00:00:00+09:00', 'saved_at' => '2026-10-01T00:00:01+09:00'];
    }

    private function candidate(int $id, string $day, int $n, int $number): array
    {
        return ['original_race_id' => $id, 'external_race_key' => '28:'.str_replace('-', '', $day).':'.sprintf('%02d', $number), 'race_date' => $day, 'track_code' => '28', 'race_number' => $number,
            'entrant_count' => $n, 'meeting_id' => $id, 'meeting_start' => $day, 'meeting_end' => $day, 'day_number' => 1,
            'context' => ['racetrack_id' => 28, 'race_day_id' => $id, 'meeting_grade' => 'F1', 'meeting_day_kind' => null],
            'entries' => array_map(fn (int $bike): array => ['original_entry_id' => $id * 10 + $bike, 'original_player_id' => $bike, 'external_player_id' => (string) (90000 + $bike), 'bike' => $bike], range(1, $n))];
    }
}
