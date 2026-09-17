<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Contract;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\FinalFitService;
use Illuminate\Console\Command;
use Throwable;

final class TacticalHistoryFinalCommand extends Command
{
    protected $signature = 'keirin:backtest:tactical-history-final
        {--plan : Display the final development fit contract without database access}
        {--execute : Verify fixed parent artifacts and independently fit C1 twice}
        {--source-bundle= : Existing TACTICAL-HISTORY-01 v2 run bundle}
        {--output-dir= : New persistent directory outside the repository and source bundle}';

    protected $description = 'Create a reproducible C1 final development model candidate, not a holdout evaluation.';

    public function handle(FinalFitService $service): int
    {
        if ((bool) $this->option('plan') === (bool) $this->option('execute')) {
            $this->error('Specify exactly one of --plan or --execute.');

            return self::FAILURE;
        }
        if ($this->option('plan')) {
            if ($this->option('source-bundle') !== null || $this->option('output-dir') !== null) {
                $this->error('Paths are only allowed with --execute.');

                return self::FAILURE;
            }
            $this->line(json_encode(Contract::plan(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }
        $source = $this->option('source-bundle');
        $output = $this->option('output-dir');
        if (! is_string($source) || $source === '' || ! is_string($output) || $output === '') {
            $this->error('Explicit --source-bundle and --output-dir are required.');

            return self::FAILURE;
        }
        try {
            $result = $service->run($source, $output);
            $this->info($result['status']);
            $this->line('lambda='.sprintf('%.17g', $result['selection']['lambda']));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
