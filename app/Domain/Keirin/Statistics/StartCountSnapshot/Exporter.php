<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\StartCountSnapshot;

use App\Domain\Keirin\Backtest\Experiments\Stat35C1Diagnostic\LineWriter;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Statistics\AgariRaceRelative\Artifacts;
use App\Domain\Keirin\Statistics\StartObservation\Ledger;
use RuntimeException;
use Throwable;

final class Exporter
{
    public function __construct(private readonly Ledger $ledger, private readonly MetadataSource $metadata) {}

    public function export(string $ledgerPath, string $output): array
    {
        if ($output === $ledgerPath || str_starts_with($output.'/', $ledgerPath.'/')) {
            throw new RuntimeException('Export must not modify the accepted ledger.');
        }
        $source = $this->ledger->open($ledgerPath);
        $code = Bundle::code();
        Artifacts::create($output);
        try {
            $writer = new LineWriter($output.'/targets.jsonl');
            $scope = '[';
            $n = 0;
            // Validate EVERY target (including years) before opening a DB connection.
            foreach ($this->ledger->races($source) as $item) {
                $t = Bundle::target($item);
                $writer->append($t);
                $scope .= ($n++ === 0 ? '' : ',').Files::canonical(['race_id' => $t['race']['race_id'],
                    'race_date' => $t['race']['race_date'], 'race_number' => $t['race']['race_number'],
                    'fetch_ids' => $t['historical_fetch_ids']]);
                if (strlen($scope) > 24 * 1024 * 1024) {
                    throw new RuntimeException('Bounded SQL scope exceeded.');
                }
            }
            $scope .= ']';
            $files = ['targets.jsonl' => $writer->finish()];
            $files['contract.json'] = Artifacts::json($output, 'contract.json', Contract::plan());
            $files['queries.json'] = Artifacts::json($output, 'queries.json', MetadataSource::queries());
            $audit = $this->metadata->capture($scope, function ($name, $rows) use ($output, &$files) {
                $w = new LineWriter($output.'/'.$name);
                foreach ($rows as $row) {
                    $w->append($row);
                }
                $files[$name] = $w->finish();
            });
            unset($scope);
            $files['connection.json'] = Artifacts::json($output, 'connection.json', $audit);
            $this->ledger->verify($source);
            Files::same($code, Bundle::code(), 'export code START/END');
            Artifacts::publish($output, ['version' => Contract::VERSION, 'kind' => 'SOURCE', 'ledger' => $source,
                'raw_root' => config('filesystems.disks.local.root'), 'targets' => $n, 'code' => $code, 'files' => $files]);

            return ['path' => $output, 'manifest' => Files::identity($output.'/manifest.json'), 'targets' => $n];
        } catch (Throwable $e) {
            // Do not log SQL bindings, which may contain private request parameters.
            Artifacts::json($output, 'failure.json', ['status' => 'FAILED_NO_RETRY', 'exception' => $e::class, 'code' => $e->getCode()]);
            throw $e;
        }
    }
}
