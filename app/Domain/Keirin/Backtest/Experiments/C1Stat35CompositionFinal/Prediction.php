<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact as Jsonl;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use RuntimeException;
use Throwable;

final class Prediction
{
    public function __construct(private readonly Package $packages, private readonly Forward $forward) {}

    public function run(string $artifact, string $input, string $output): array
    {
        $model = $this->packages->load($artifact);
        $parent = realpath(dirname($output));
        $root = realpath(Contract::ROOT);
        if ($parent === false || file_exists($output) || is_link($output) || file_exists($output.'.manifest.json')
            || file_exists($output.'.COMPLETE.json') || str_starts_with($parent.'/', dirname(realpath($artifact)).'/')
            || (! app()->environment('testing') && ($root === false || ! str_starts_with($parent.'/', $root.'/')))) {
            throw new RuntimeException('Prediction requires a new output inside the agreed root outside model sources.');
        }
        $stage = $output.'.stage-'.bin2hex(random_bytes(8));
        Files::directory($stage);
        $code = Contract::code();
        try {
            // The private stage never publishes partial annual input or failed end-integrity checks.
            $seal = Jsonl::write($stage.'/predictions.jsonl', (function () use ($input, $model): \Generator {
                foreach (Input::read($input) as $race) {
                    yield $this->forward->predict($race, $model['c1'], $model['c2']);
                }
            })());
            Files::same($code, Contract::code(), 'prediction code end');
            Files::same($model['seal'], $this->packages->load($artifact)['seal'], 'prediction package end');
            Files::verify($stage.'/predictions.jsonl', $seal);
            foreach (['' => '/predictions.jsonl', '.manifest.json' => '/predictions.jsonl.manifest.json'] as $suffix => $name) {
                if (! rename($stage.$name, $output.$suffix)) {
                    throw new RuntimeException('Could not publish complete predictions.');
                }
            }
            $result = ['status' => 'FEATURE_ONLY_PREDICTIONS_SEALED', 'predictions' => $seal, 'package' => $model['seal'],
                'input' => Files::identity($input), 'purpose' => Contract::plan()['performance_evaluation'],
                'peak_memory_bytes' => memory_get_peak_usage(true)];
            Jsonl::json($output.'.COMPLETE.json', $result);
            rmdir($stage);

            return $result;
        } catch (Throwable $e) {
            Jsonl::json($stage.'/FAILED.json', ['status' => 'FAILED_NOT_PUBLISHED', 'exception' => $e::class, 'error' => $e->getMessage()]);
            throw $e;
        }
    }
}
