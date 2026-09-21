<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Audit\Stat35DataReadiness;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionPipeline\ReadOnlySession;
use Generator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class Source
{
    private array $registered = [];

    public array $audit = ['accessed_tables' => Contract::TABLES, 'query_count' => 0, 'read_only' => false,
        'write_count' => 0, 'date_min' => Contract::FROM, 'date_max' => Contract::TO, '2026_access_count' => 0];

    public function session(callable $work): mixed
    {
        return (new ReadOnlySession)->run(function (array $settings) use ($work) {
            if (DB::getDriverName() === 'pgsql') {
                $timeout = DB::selectOne("SELECT current_setting('statement_timeout') AS value")->value;
                if (! in_array($timeout, ['2min', '120s', '120000ms'], true)) {
                    throw new RuntimeException('Require statement_timeout=120000 before connecting.');
                }
            }
            $this->audit['read_only'] = true;
            $this->audit['settings'] = $settings;

            return $work();
        });
    }

    public function query(string $kind, int $after = 0, array $ids = []): Builder
    {
        foreach ($ids as $id) {
            Contract::id($id);
        }
        $q = DB::table('races as r')->whereBetween('r.race_date', [Contract::FROM, Contract::TO])
            ->where(fn ($q) => $q->where('r.race_type', 'like', 'Ａ級%')->orWhere('r.race_type', 'like', 'Ｓ級%'));
        if ($kind === 'races') {
            $q->leftJoin('race_days as d', 'd.id', '=', 'r.race_day_id')->leftJoin('race_meetings as m', 'm.id', '=', 'd.race_meeting_id')
                ->leftJoin('racetracks as t', 't.id', '=', 'r.racetrack_id')->where('r.id', '>', $after)->orderBy('r.id')->limit(100)
                ->select(['r.id as race_id', 'r.race_date', 'r.scheduled_start_at', 'r.race_number', 'r.race_type', 'r.entrant_count',
                    'r.grade as race_grade', 'm.id as meeting_id', 'm.starts_on as meeting_start', 'm.grade as meeting_grade',
                    't.external_track_id as track_code']);
        } elseif ($kind === 'entries') {
            $q->join('race_entries as e', 'e.race_id', '=', 'r.id')->whereIn('r.id', $ids)
                ->orderBy('r.id')->orderBy('e.bike_number')->select(['e.id', 'e.race_id', 'e.bike_number', 'e.player_id', 'e.external_player_id']);
        } elseif ($kind === 'results') {
            $q->join('race_results as x', 'x.race_id', '=', 'r.id')->whereIn('r.id', $ids)->orderBy('r.id')->orderBy('x.bike_number')
                ->select(['x.race_id', 'x.bike_number', 'x.race_entry_id', 'x.player_id', 'x.race_result_import_id', 'x.result_status']);
        } elseif ($kind === 'imports') {
            $q->join('race_result_imports as i', 'i.race_id', '=', 'r.id')->leftJoin('scraping_fetch_logs as f', 'f.id', '=', 'i.scraping_fetch_log_id')
                ->whereIn('r.id', $ids)->orderBy('r.id')->orderBy('i.id')->select(['i.id as import_id', 'i.race_id', 'r.race_date', 'i.scraping_fetch_log_id',
                    'i.source_url', 'i.source_hash', 'i.raw_file_path', 'i.raw_response_size', 'i.converted_hash', 'i.parser_version',
                    'i.parsed_page_status', 'i.import_status', 'i.imported_at', 'f.fetched_at', 'f.sha256 as fetch_hash',
                    'f.response_size as fetch_bytes', 'f.raw_file_path as fetch_path', 'f.content_type']);
        } else {
            throw new RuntimeException('Unapproved source query kind.');
        }
        $this->registered[spl_object_id($q)] = [$q->toSql(), $q->getBindings()];

        return $q;
    }

    public function approved(Builder $query): void
    {
        if (($this->registered[spl_object_id($query)] ?? null) !== [$query->toSql(), $query->getBindings()]) {
            throw new RuntimeException('Unapproved or modified audit SELECT.');
        }
    }

    private function select(Builder $query): array
    {
        $this->approved($query);
        $this->audit['query_count']++;
        $rows = [];
        foreach ($query->get() as $object) {
            $row = (array) $object;
            foreach (['race_id', 'race_number', 'entrant_count', 'meeting_id', 'id', 'bike_number', 'player_id', 'race_entry_id',
                'race_result_import_id', 'import_id', 'scraping_fetch_log_id', 'raw_response_size', 'fetch_bytes'] as $key) {
                if (isset($row[$key])) {
                    $row[$key] = (int) $row[$key];
                }
            }
            $rows[] = $row;
        }
        unset($this->registered[spl_object_id($query)]);

        return $rows;
    }

    public function rows(array &$digest): Generator
    {
        $last = $count = $imports = $statuses = 0;
        $hash = hash_init('sha256');
        do {
            $races = $this->select($this->query('races', $last));
            if ($races === []) {
                break;
            }
            $ids = array_column($races, 'race_id');
            $children = [];
            foreach (['entries', 'results', 'imports'] as $kind) {
                foreach ($this->select($this->query($kind, ids: $ids)) as $row) {
                    if ($kind === 'imports') {
                        if ($row['fetch_path'] !== null && $row['fetch_path'] !== $row['raw_file_path']) {
                            throw new RuntimeException('Import/fetch path conflict.');
                        }
                        $row['absolute_path'] = Storage::disk((string) config('keirin.raw_disk'))->path($row['raw_file_path']);
                        $imports++;
                    } elseif ($kind === 'results') {
                        $statuses++;
                    }
                    $children[$kind][$row['race_id']][] = $row;
                }
            }
            foreach ($races as $race) {
                Contract::date($race['race_date']);
                $last = $race['race_id'];
                $row = ['race' => $race, 'entries' => $children['entries'][$last] ?? [],
                    'results' => $children['results'][$last] ?? [], 'imports' => $children['imports'][$last] ?? []];
                hash_update($hash, Files::canonical($row)."\n");
                $count++;
                yield $row;
            }
        } while (count($races) === 100);
        $digest = ['races' => $count, 'imports' => $imports, 'result_status_rows' => $statuses, 'sha256' => hash_final($hash)];
    }
}
