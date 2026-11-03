<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\InitialHistoryMetadata;

use App\Domain\Keirin\Backtest\ChronologicalValidation\Json;
use Throwable;

final class Exporter
{
    public const OUTPUTS = ['CONTEXT' => 'context.jsonl', 'ENTRY' => 'entry-identities.jsonl', 'FETCH' => 'fetch-persistence.jsonl', 'IMPORT' => 'result-persistence.jsonl', 'RESULT' => 'result-persistence.jsonl'];

    public function __construct(private readonly MetadataSource $source) {}

    public function export(string $scopeManifest, string $output): array
    {
        Evidence::create($output);
        $started = Evidence::now();
        $clock = hrtime(true);
        $code = Evidence::code();
        try {
            [$scope, $index] = Scope::load($scopeManifest, $output.'/extraction-index.sqlite');
            $inspection = new Inspection($index);
            $index->beginTransaction();
            $writers = [];
            foreach (array_unique([...array_values(self::OUTPUTS), 'unresolved.jsonl']) as $name) {
                $writers[$name] = new Writer($output.'/'.$name);
            }
            $files = ['queries.json' => Evidence::json($output, 'queries.json', Queries::all()),
                'timing-contract.json' => Evidence::json($output, 'timing-contract.json', Reconciliation::meanings())];
            $audit = $this->source->capture($index, function (string $type, string $key, ?array $current) use ($inspection, $writers): void {
                $row = $inspection->row($type, $key, $current);
                if ($row === null) {
                    return;
                }
                if (str_starts_with($type, 'EXTRA_')) {
                    $writers['unresolved.jsonl']->append($row);
                } else {
                    $writers[self::OUTPUTS[$type]]->append($row);
                    if ($row['reasons'] !== []) {
                        $writers['unresolved.jsonl']->append(self::unresolved($row));
                    }
                }
            }, function (string $name, array $data) use ($output, &$files): void {
                Evidence::require(in_array($name, ['connection-precheck.json', 'snapshot.json'], true), 'Unknown connection evidence.');
                $files[$name] = Evidence::json($output, $name, $data);
            });
            // Source capture has rolled back and disconnected before publishing any COMPLETE.
            $summary = $inspection->finish($scope);
            $index->commit();
            foreach ($writers as $name => $writer) {
                $files[$name] = $writer->finish();
            }
            $files['connection.json'] = Evidence::json($output, 'connection.json', $audit);
            $files['summary.json'] = Evidence::json($output, 'summary.json', $summary);
            $proof = ['scope_manifest' => ['path' => $scopeManifest, 'seal' => Json::seal($scopeManifest)],
                'counts' => $scope['counts'], 'files' => array_intersect_key($scope['files'], array_flip(array_values(Evidence::SCOPE_FILES))),
                'sources' => $scope['sources'], 'code' => $scope['code']];
            $files['scope-proof.json'] = Evidence::json($output, 'scope-proof.json', $proof);
            Evidence::require(Json::hash($code) === Json::hash(Evidence::code()), 'Metadata code changed during export.');
            $files['execution.json'] = Evidence::json($output, 'execution.json', ['started_at' => $started, 'finished_at' => Evidence::now(),
                'elapsed_seconds' => (hrtime(true) - $clock) / 1e9, 'peak_memory_bytes' => memory_get_peak_usage(true), 'memory_limit' => ini_get('memory_limit'),
                'php' => PHP_BINARY, 'php_version' => PHP_VERSION, 'business_writes' => 0, 'new_http' => 0, 'exit' => 0]);
            $index = null;
            $files['extraction-index.sqlite'] = Json::seal($output.'/extraction-index.sqlite');

            return Evidence::publish($output, 'manifest.json', ['version' => Evidence::VERSION, 'kind' => 'EXPORT', 'files' => $files,
                'scope' => $proof, 'code' => $code, 'counts' => $scope['counts'], 'meaning' => 'METADATA_COMPLETE_NOT_O1', 'historical_as_of_available' => false,
                'prediction_use' => 'NOT_AUTHORIZED']);
        } catch (Throwable $e) {
            Evidence::failed($output, $e, 'EXPORT', $started, $clock);
            throw $e;
        }
    }

    public static function unresolved(array $row): array
    {
        return ['record_type' => $row['record_type'], 'key' => $row['scope']['key'], 'race_id' => $row['scope']['race_id'],
            'race_date' => $row['scope']['race_date'], 'classification' => $row['classification'], 'reasons' => $row['reasons']];
    }
}
