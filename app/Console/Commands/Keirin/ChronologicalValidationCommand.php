<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Backtest\ChronologicalValidation\BundleRules;
use App\Domain\Keirin\Backtest\ChronologicalValidation\Coordinator;
use App\Domain\Keirin\Backtest\ChronologicalValidation\FileResultSource;
use App\Domain\Keirin\Backtest\ChronologicalValidation\Json;
use App\Domain\Keirin\Backtest\ChronologicalValidation\RunVerifier;
use App\Domain\Keirin\Backtest\ChronologicalValidation\Scope;
use App\Domain\Keirin\Backtest\ChronologicalValidation\Store;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\ModelLoader;
use Illuminate\Console\Command;
use Throwable;

final class ChronologicalValidationCommand extends Command
{
    protected $signature = 'keirin:backtest:chronological-validation
        {--plan} {--execute-offline} {--verify} {--reproduce-evaluation}
        {--input-manifest=} {--result-root=} {--output-root=} {--artifact=}';

    protected $description = 'Explicit offline chronological connection; real execution is not authorized.';

    public function handle(Coordinator $coordinator): int
    {
        try {
            $modes = array_filter(['plan', 'execute-offline', 'verify', 'reproduce-evaluation'], fn (string $m): bool => (bool) $this->option($m));
            BundleRules::require(count($modes) === 1, 'Exactly one mode is required.');
            if ($this->option('plan')) {
                $this->line(Json::encode(['contract' => Json::read(base_path('docs/contracts/stat-2026-chronological-validation-v1.json')),
                    'implementation' => 'OFFLINE_CONNECTION', 'allowed_materials' => ['SYNTHETIC_TEST', 'LEGACY_COMPATIBILITY'],
                    'initial_real_history' => 'NOT_ESTABLISHED', 'real_execution_authorized' => false, 'db_http_fit' => 0]));

                return self::SUCCESS;
            }
            $root = $this->option('output-root');
            BundleRules::require(is_string($root) && $root !== '', '--output-root is required.');
            if (! $this->option('execute-offline')) {
                $store = new Store($root, false);
                $result = $this->option('verify') ? (new RunVerifier)->verify($store) : $coordinator->reproduce($store);
            } else {
                BundleRules::require(is_string($this->option('input-manifest')) && is_string($this->option('result-root')) && is_string($this->option('artifact')), 'Input manifest, result root and artifact are required.');
                $manifest = Json::read($this->option('input-manifest'));
                $scope = new Scope(...$manifest['scope']);
                $artifact = $this->option('artifact');
                Json::verify($artifact, ['bytes' => 3524, 'sha256' => 'abdb0038b1fecbc2821c38f6dae08875876a4aa0f39c02abf60c23ca9b34bd6f']);
                $path = dirname($artifact).'/model.json';
                $seal = ['bytes' => 80928, 'sha256' => 'e3cc1f7f10af60bb22f97a2172ca52ef2c09a3393222ee46c25619de752d43a1'];
                Json::verify($path, $seal);
                $model = $this->laravel->make(ModelLoader::class)->published($artifact);
                $guard = static function () use ($path, $seal, $artifact): void {
                    Json::verify($path, $seal);
                    Json::verify($artifact, ['bytes' => 3524, 'sha256' => 'abdb0038b1fecbc2821c38f6dae08875876a4aa0f39c02abf60c23ca9b34bd6f']);
                };
                $store = new Store($root);
                $result = $coordinator->execute($manifest, $model, $seal['sha256'], $store, new FileResultSource($this->option('result-root'), $scope, $store), $guard);
                Json::verify($path, $seal);
                Json::verify($artifact, ['bytes' => 3524, 'sha256' => 'abdb0038b1fecbc2821c38f6dae08875876a4aa0f39c02abf60c23ca9b34bd6f']);
            }
            $this->line(Json::encode([...$result, 'peak_memory_bytes' => memory_get_peak_usage(true), 'memory_limit' => ini_get('memory_limit')]));

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error(Json::encode(['status' => 'FAILED', 'exception' => $error::class, 'message' => $error->getMessage()]));

            return self::FAILURE;
        }
    }
}
