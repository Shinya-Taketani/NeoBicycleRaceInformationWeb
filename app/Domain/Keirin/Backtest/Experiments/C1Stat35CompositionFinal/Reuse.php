<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal;

use App\Domain\Keirin\Backtest\Calculators\EffectBinBuilder;
use App\Domain\Keirin\Backtest\Experiments\Stat35C1Comparison\Dataset;
use App\Domain\Keirin\Backtest\Experiments\Stat35C1Comparison\LayoutBuilder;
use App\Domain\Keirin\Backtest\Experiments\Stat35C1Comparison\ModelLoader;
use App\Domain\Keirin\Backtest\Experiments\Stat35C1Comparison\Objective;
use App\Domain\Keirin\Backtest\Experiments\Stat35C1Comparison\Trainer;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact as Jsonl;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Services\Bt03e03Contract as Numeric;
use App\Domain\Keirin\Backtest\Support\Bt03e03ValidationLossSpool as Spool;
use RuntimeException;

final class Reuse
{
    public function __construct(private readonly LayoutBuilder $layouts, private readonly Dataset $data, private readonly Trainer $trainer,
        private readonly EffectBinBuilder $bins, private readonly ModelLoader $loader, private readonly Objective $objective) {}

    public function restore(string $parent, callable $training, callable $validation, string $directory): Spool
    {
        $path = Files::json($parent.'/path.json');
        Files::same(Numeric::FIT_EXECUTION_ORDER, $path['fit_order'], 'reused C2 full path');
        $keys = array_map(Spool::lambdaKey(...), Numeric::LAMBDA_GRID);
        if (array_map('strval', array_keys($path['candidate_statuses'])) !== $keys) {
            throw new RuntimeException('Reused C2 grid incomplete.');
        }
        $layout = $this->layouts->build($training);
        Files::same($this->trainer->layoutAudit($layout), Files::json($parent.'/layout.json'), 'reused C2 training-local layout/support');
        $seal = Jsonl::write($directory.'/training-verification.jsonl', $this->data->binned($training, $layout, $this->bins));
        Files::same($seal, Files::json($parent.'/training.jsonl.manifest.json'), 'reused C2 training years/order/labels');
        $models = [];
        if (array_diff(array_keys($path['models']), array_keys($path['candidate_statuses'])) !== []) {
            throw new RuntimeException('Unregistered reused C2 model.');
        }
        foreach ($path['candidate_statuses'] as $key => $status) {
            if (($status['status'] === 'CONVERGED') !== isset($path['models'][$key])
                || ! in_array($status['status'], ['CONVERGED', 'NUMERICALLY_NON_CONVERGED'], true)
                || ($status['lambda'] ?? null) !== (float) $key) {
                throw new RuntimeException('Invalid reused C2 candidate state.');
            }
            if ($status['status'] === 'CONVERGED') {
                $loaded = $this->loader->restore($path['models'][$key]);
                Files::same($loaded->artifact['layout'], $this->trainer->layoutAudit($layout), 'reused model support');
                Files::same($loaded->fit->diagnostics, $status['positions'], 'reused C2 convergence diagnostics');
                if ($loaded->fit->lambda !== (float) $key) {
                    throw new RuntimeException('Reused C2 lambda mismatch.');
                }
                $models[$key] = $loaded->fit;
            }
        }
        $fits = ['fits' => $models, 'layout' => $layout];
        $spool = $this->losses($validation, $fits, $directory, $parent.'/validation-losses.jsonl');
        Jsonl::json($directory.'/reuse.json', ['status' => 'VERIFIED_WITHOUT_RETRAINING', 'validation_year' => basename($parent) === 'C2-inner-A' ? 2023 : 2024,
            'available_lambda_keys' => $spool->availableLambdaKeys(), 'race_count' => $spool->raceCount(), 'saved_path' => Files::identity($parent.'/path.json')]);

        return $spool;
    }

    public function losses(callable $validation, array $path, string $directory, ?string $savedPath = null): Spool
    {
        $spool = new Spool($directory.'/losses-working.bin', array_keys($path['fits']));
        $saved = $savedPath === null ? null : Jsonl::read($savedPath);
        $saved?->rewind();
        $rows = (function () use ($validation, $path, $spool, $saved): \Generator {
            foreach ($this->data->binned($validation, $path['layout'], $this->bins) as $race) {
                $losses = [];
                foreach ($path['fits'] as $key => $fit) {
                    foreach (Numeric::POSITIONS as $position) {
                        $losses[$key][$position] = $this->objective->raceLoss($race, $path['layout'], $fit->coefficients[$position], $position);
                    }
                }
                $row = ['race_id' => $race['race_id'], 'losses' => $losses];
                if ($saved !== null) {
                    if (! $saved->valid()) {
                        throw new RuntimeException('Reused C2 losses incomplete.');
                    }
                    Files::same($saved->current(), $row, 'reused C2 loss values/order/eligibility');
                    $saved->next();
                }
                $spool->append($losses);
                yield $row;
            }
            if ($saved !== null && $saved->valid()) {
                throw new RuntimeException('Reused C2 losses extra rows.');
            }
        })();
        Jsonl::write($directory.'/validation-losses.jsonl', $rows);
        $spool->seal();

        return $spool;
    }
}
