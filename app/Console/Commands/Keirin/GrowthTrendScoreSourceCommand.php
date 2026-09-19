<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Backtest\Experiments\GrowthTrendScoreSource\Contract;
use App\Domain\Keirin\Backtest\Experiments\GrowthTrendScoreSource\Service;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

final class GrowthTrendScoreSourceCommand extends Command
{
    protected $signature = 'keirin:backtest:growth-trend-score-source {--plan} {--capture} {--verify} {--outer-root=} {--output-root=} {--source-id=}';

    protected $description = 'Capture outcome-free score observations in a read-only session.';

    public function handle(): int
    {
        try {
            if (count(array_filter([$this->option('plan'), $this->option('capture'), $this->option('verify')])) !== 1) {
                throw new RuntimeException('Exactly one operation is required.');
            }
            $result = $this->option('plan') ? Contract::plan() : ($this->option('capture') ? app(Service::class)->capture($this->required('output-root'), $this->required('source-id'), $this->required('outer-root'))
                : app(Service::class)->verify($this->required('output-root').'/evaluations/'.$this->required('source-id')));
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function required(string $key): string
    {
        $value = $this->option($key);
        if (! is_string($value) || $value === '') {
            throw new RuntimeException('Explicit --'.$key.' required.');
        }

        return $value;
    }
}
