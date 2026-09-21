<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Audit\Stat35DataReadiness\Contract;
use App\Domain\Keirin\Audit\Stat35DataReadiness\Service;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

final class Stat35DataReadinessAuditCommand extends Command
{
    protected $signature = 'keirin:audit:stat35-data-readiness {--plan} {--execute} {--reproduce} {--from=2022-01-01} {--to=2025-12-31} {--outer-root=} {--output-root=} {--audit-id=}';

    protected $description = 'Read-only saved-result agari readiness audit; no prediction evaluation.';

    public function handle(): int
    {
        try {
            if (count(array_filter([$this->option('plan'), $this->option('execute'), $this->option('reproduce')])) !== 1
                || $this->option('from') !== Contract::FROM || $this->option('to') !== Contract::TO) {
                throw new RuntimeException('Require exactly one operation and fixed 2022-2025 scope.');
            }
            if ($this->option('plan')) {
                $result = Contract::plan();
            } else {
                $root = $this->required('output-root');
                $id = $this->required('audit-id');
                $service = app(Service::class);
                $result = $this->option('execute') ? $service->execute($root, $id, $this->required('outer-root')) : $service->reproduce($root, $id);
            }
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error->getMessage());

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
