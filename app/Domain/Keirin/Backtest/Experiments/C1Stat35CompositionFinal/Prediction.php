<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use Throwable;

final class Prediction
{
    public function __construct(private readonly Package $packages, private readonly Forward $forward, private readonly Publication $publication) {}

    public function run(string $artifact, string $input, string $output): array
    {
        $model = $this->packages->load($artifact);
        $output = $this->publication->destination($output, [dirname($artifact), $input, $input.'.manifest.json', $input.'.input.json']);
        $lock = $this->publication->acquire($output);
        $stage = '';
        $completion = null;
        try {
            $stage = $this->publication->stage($output);
            $code = Contract::code();
            $inputs = [];
            foreach (['', '.manifest.json', '.input.json'] as $suffix) {
                $inputs[$input.$suffix] = Files::identity($input.$suffix);
            }
            // The private stage never publishes partial annual input or failed end-integrity checks.
            $seal = $this->publication->rows($stage.'/predictions.jsonl', (function () use ($input, $model): \Generator {
                foreach (Input::read($input) as $race) {
                    yield $this->forward->predict($race, $model['c1'], $model['c2']);
                }
            })());
            Files::same($code, Contract::code(), 'prediction code end');
            Files::same($model['seal'], $this->packages->load($artifact)['seal'], 'prediction package end');
            Files::verify($stage.'/predictions.jsonl', $seal);
            $result = ['status' => 'FEATURE_ONLY_PREDICTIONS_SEALED', 'predictions' => $seal, 'package' => $model['seal'],
                'input' => $inputs[$input], 'purpose' => 'NOT_PERFORMED_PUBLICATION_FIX_AND_TECHNICAL_REVALIDATION_ONLY',
                'publication_version' => Contract::PUBLICATION_VERSION, 'output_dir' => $output, 'predictions_path' => $output.'/predictions.jsonl',
                'runtime_code' => $code,
                'peak_memory_bytes' => memory_get_peak_usage(true)];
            $this->publication->json($stage.'/COMPLETE.json', $result);
            foreach ($inputs as $path => $expected) {
                Files::verify($path, $expected);
            }
            Files::same($code, Contract::code(), 'prediction precommit code');
            Files::same($model['seal'], $this->packages->load($artifact)['seal'], 'prediction precommit package');
            Files::verify($stage.'/predictions.jsonl', $seal);
            Files::same($seal, Files::json($stage.'/predictions.jsonl.manifest.json'), 'prediction manifest');
            Files::same($result, Files::json($stage.'/COMPLETE.json'), 'prediction completion');
            $completion = Files::identity($stage.'/COMPLETE.json');
            $this->publication->commit($stage, $output);

            return $result;
        } catch (Throwable $e) {
            if ($this->publication->wasCommitted($stage, $output, $completion)) {
                return $result + ['postcommit_warning' => $e->getMessage()];
            }
            $this->publication->failed($stage, $e);
            throw $e;
        } finally {
            fclose($lock);
        }
    }
}
