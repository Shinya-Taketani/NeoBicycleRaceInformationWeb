<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Backtest\InitialHistoryMetadata\Evidence;
use App\Domain\Keirin\Backtest\InitialHistoryMetadata\Exporter;
use App\Domain\Keirin\Backtest\InitialHistoryMetadata\Planner;
use App\Domain\Keirin\Backtest\InitialHistoryMetadata\PostgresSource;
use App\Domain\Keirin\Backtest\InitialHistoryMetadata\Verifier;
use Illuminate\Console\Command;
use Throwable;

final class SupplementInitialHistoryMetadataCommand extends Command
{
    protected $signature = 'keirin:backtest:initial-history-metadata {--plan} {--export} {--verify}
        {--audit-root=} {--scope-manifest=} {--output-root=} {--bundle=}';

    protected $description = 'Plan, read-only export, or offline verify fixed 2022-2025 initial-history metadata.';

    public function handle(): int
    {
        try {
            $modes = array_filter(['plan', 'export', 'verify'], fn ($m) => $this->option($m));
            Evidence::require(count($modes) === 1, 'Exactly one metadata mode required.');
            $mode = array_values($modes)[0];
            $input = ['plan' => 'audit-root', 'export' => 'scope-manifest', 'verify' => 'bundle'][$mode];
            Evidence::require(is_string($this->option($input)) && $this->option($input) !== '' && is_string($this->option('output-root')) && $this->option('output-root') !== '', 'Mode input and output-root required.');
            foreach (array_diff(['audit-root', 'scope-manifest', 'bundle'], [$input]) as $forbidden) {
                Evidence::require($this->option($forbidden) === null, 'Option not valid in this mode.');
            }
            $result = match ($mode) {
                'plan' => (new Planner)->plan($this->option($input), $this->option('output-root')),
                'export' => (new Exporter(new PostgresSource))->export($this->option($input), $this->option('output-root')),
                'verify' => (new Verifier)->verify($this->option($input), $this->option('output-root')),
            };
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $e) {
            // DB exception messages may contain connection details or SQL bindings.
            $this->error('Initial-history metadata failed: '.$e::class.'. New failure evidence, if created, is retained.');

            return self::FAILURE;
        }
    }
}
