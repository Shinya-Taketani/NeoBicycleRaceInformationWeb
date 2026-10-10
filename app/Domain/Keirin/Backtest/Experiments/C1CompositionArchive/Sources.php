<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\C1CompositionArchive;

use App\Domain\Keirin\Backtest\Experiments\C1CompositionRequest\Contract as Requests;
use App\Domain\Keirin\Backtest\Experiments\C1CompositionRequest\Store;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Contract as Model;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use RuntimeException;

class Sources
{
    public function __construct(private readonly ?array $fixed = null) {}

    public function definitions(): array
    {
        return $this->fixed ?? Contract::sources();
    }

    public function start(): array
    {
        $identities = [];
        $sources = $this->definitions();
        foreach ($sources as $source) {
            $path = $source['path'];
            Store::safe($path);
            Files::verify($path, $source['seal']);
            Files::same($source['seal'], Files::json($path.'.manifest.json'), 'annual source sidecar');
            foreach ([$path, $path.'.manifest.json'] as $file) {
                $identities[$file] = Files::identity($file);
            }
        }
        $input = $sources['input']['path'];
        $prediction = $sources['predictions']['path'];
        $complete = dirname($prediction).'/COMPLETE.json';
        $identities[$input.'.input.json'] = Files::identity($input.'.input.json');
        $identities[$complete] = Files::identity($complete);
        $proof = Files::json($complete);
        if (($proof['status'] ?? null) !== 'FEATURE_ONLY_PREDICTIONS_SEALED'
            || ($proof['publication_version'] ?? null) !== Model::PUBLICATION_VERSION
            || ($proof['output_dir'] ?? null) !== dirname($prediction) || ($proof['predictions_path'] ?? null) !== $prediction
            || ! is_array($proof['runtime_code'] ?? null) || ! is_array($proof['package'] ?? null)) {
            throw new RuntimeException('Invalid saved prediction completion contract.');
        }
        Files::same($sources['predictions']['seal'], $proof['predictions'], 'saved prediction completion seal');
        Files::same(Files::identity($input), $proof['input'], 'saved prediction input identity');
        Files::same(Requests::ARTIFACT_SEAL, $proof['package'], 'saved package reference (without opening the model)');

        return $identities;
    }

    public function end(array $start): void
    {
        foreach ($start as $path => $seal) {
            Files::verify($path, $seal);
        }
    }
}
