<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\InitialHistoryMetadata;

use App\Domain\Keirin\Backtest\ChronologicalValidation\Json;
use PDO;

final class Inspection
{
    private array $summary = ['fixed' => [], 'additional' => [], 'by_year' => [], 'reasons' => [], 'timing' => [], 'states' => []];

    private array $hashes = [];

    private array $counts = [];

    public function __construct(private readonly PDO $index)
    {
        $index->exec('CREATE TABLE observed (type TEXT,k TEXT,data TEXT,PRIMARY KEY(type,k));
            CREATE TABLE import_use AS SELECT CAST(json_extract(data,\'$.expected.race_result_import_id\') AS INTEGER) iid,count(*) n FROM scope WHERE type=\'RESULT\' GROUP BY iid;
            CREATE UNIQUE INDEX import_use_id ON import_use(iid)');
        foreach (Evidence::TYPES as $type) {
            $this->hashes[$type] = hash_init('sha256');
            $this->counts[$type] = 0;
            $this->summary['fixed'][$type] = ['MATCH' => 0, 'CHANGED' => 0, 'NOT_FOUND_IN_FIXED_PERIOD_SOURCE' => 0];
        }
    }

    private function observed(string $type, string $key): ?array
    {
        $s = $this->index->prepare('SELECT data FROM observed WHERE type=? AND k=?');
        $s->execute([$type, $key]);
        $data = $s->fetchColumn();

        return $data === false ? null : json_decode($data, true, flags: JSON_THROW_ON_ERROR);
    }

    public function row(string $type, string $key, ?array $current): ?array
    {
        if (str_starts_with($type, 'EXTRA_')) {
            Evidence::require($current !== null, 'Additional key requires current evidence.');
            $base = substr($type, 6);
            $key = $base === 'ENTRY' ? (string) $current['id'] : $current['race_id'].':'.$current['bike_number'];
            $existing = Scope::get($this->index, $base, $key);
            if ($existing !== null && $existing['race_id'] === $current['race_id']) {
                return null;
            }
            $parent = Scope::get($this->index, 'CONTEXT', (string) $current['race_id']);
            Evidence::require($parent !== null, 'Additional key outside fixed races.');
            $row = ['record_type' => $type, 'key' => $key, 'race_id' => $current['race_id'], 'race_date' => $parent['race_date'],
                'current' => $current, 'classification' => 'ADDITIONAL_NOT_ADOPTED', 'reasons' => ['ADDITIONAL_NOT_ADOPTED']];
            $this->store($type, $key, $row);
            $this->summary['additional'][$type] = ($this->summary['additional'][$type] ?? 0) + 1;

            return $row;
        }
        $scope = Scope::get($this->index, $type, $key);
        Evidence::require($scope !== null, 'Unexpected output key.');
        $related = [];
        if ($type === 'CONTEXT') {
            $s = $this->index->prepare("SELECT count(*) FROM scope WHERE type='IMPORT' AND rid=?");
            $s->execute([$scope['race_id']]);
            $related['fixed_imports'] = $s->fetchColumn();
        } elseif ($type === 'IMPORT') {
            $related['fetch'] = $this->observed('FETCH', (string) $scope['expected']['scraping_fetch_log_id']);
            $s = $this->index->prepare('SELECT n FROM import_use WHERE iid=?');
            $s->execute([(int) $key]);
            $related['fixed_result_references'] = $s->fetchColumn() ?: 0;
        } elseif ($type === 'RESULT') {
            $related['import'] = $this->observed('IMPORT', (string) $scope['expected']['race_result_import_id']);
            $log = $related['import']['scope']['expected']['scraping_fetch_log_id'] ?? null;
            $related['fetch'] = $log === null ? null : $this->observed('FETCH', (string) $log);
        }
        $row = Reconciliation::row($type, $scope, $current, $related);
        $this->store($type, $key, $row);
        hash_update($this->hashes[$type], Json::encode($scope));
        $this->counts[$type]++;
        $this->summary['fixed'][$type][$row['classification']]++;
        $year = substr($scope['race_date'], 0, 4);
        $this->summary['by_year'][$year][$type][$row['classification']] = ($this->summary['by_year'][$year][$type][$row['classification']] ?? 0) + 1;
        foreach ($row['reasons'] as $reason) {
            $this->summary['reasons'][$type][$reason] = ($this->summary['reasons'][$type][$reason] ?? 0) + 1;
        }
        foreach ($row['timing_evidence'] as $reason) {
            $this->summary['timing'][$type][$reason] = ($this->summary['timing'][$type][$reason] ?? 0) + 1;
        }
        foreach (['result_status', 'import_status', 'parsed_page_status', 'error_type'] as $field) {
            foreach (['expected' => $scope['expected'], 'current' => $current] as $which => $r) {
                if ($r !== null && array_key_exists($field, $r)) {
                    $value = $r[$field] ?? 'NULL';
                    $this->summary['states'][$type][$which][$field][$value] = ($this->summary['states'][$type][$which][$field][$value] ?? 0) + 1;
                }
            }
        }

        return $row;
    }

    private function store(string $type, string $key, array $row): void
    {
        $s = $this->index->prepare('INSERT INTO observed VALUES (?,?,?)');
        $s->execute([$type, $key, Json::encode($row)]);
    }

    public function finish(array $scope): array
    {
        $this->index->exec("CREATE TABLE version_times AS SELECT CAST(json_extract(o.data,'$.scope.expected.race_result_import_id') AS INTEGER) iid,count(*) n,
            sum(CASE WHEN EXISTS(SELECT 1 FROM json_each(o.data,'$.timing_evidence') WHERE value='RESULT_VERSION_PROCESSING_TIME') THEN 1 ELSE 0 END) established
            FROM observed o WHERE type='RESULT' GROUP BY iid; CREATE UNIQUE INDEX version_times_id ON version_times(iid)");
        $versions = $this->index->query("SELECT CASE WHEN coalesce(u.n,0)=0 THEN 'UNESTABLISHED_NO_FIXED_RESULT_ROWS'
            WHEN coalesce(v.established,0)=u.n THEN 'APPLICATION_PROCESSING_TIME_ALL_FIXED_RESULT_ROWS'
            ELSE 'UNESTABLISHED_FIXED_RESULT_DRIFT_OR_TIME' END classification,count(*) versions
            FROM scope s LEFT JOIN import_use u ON u.iid=CAST(s.k AS INTEGER) LEFT JOIN version_times v ON v.iid=CAST(s.k AS INTEGER)
            WHERE s.type='IMPORT' GROUP BY classification ORDER BY classification")->fetchAll(PDO::FETCH_ASSOC);
        Evidence::require(array_sum(array_column($versions, 'versions')) === $scope['counts']['IMPORT'], 'Import-version timing denominator mismatch.');
        $digest = [];
        foreach (Evidence::SCOPE_FILES as $type => $file) {
            Evidence::require($this->counts[$type] === $scope['counts'][$type], 'Incomplete fixed metadata output.');
            $digest[$type] = hash_final($this->hashes[$type]);
            Evidence::require($digest[$type] === $scope['files'][$file]['sha256'], 'Output fixed key/value/order digest mismatch.');
        }

        return [...$this->summary, 'fixed_counts' => $this->counts, 'scope_digests' => $digest, 'version_timing' => $versions, 'meanings' => Reconciliation::meanings(),
            'initial_history_epoch' => 'NOT_ESTABLISHED', 'history_event_assignment' => 'NOT_RUN'];
    }
}
