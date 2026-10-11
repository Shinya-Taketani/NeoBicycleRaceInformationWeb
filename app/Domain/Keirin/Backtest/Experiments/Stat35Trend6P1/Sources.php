<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\Stat35Trend6P1;

use App\Domain\Keirin\Backtest\Experiments\C1Stat35P1Composition\Contract as CompositionContract;
use App\Domain\Keirin\Backtest\Experiments\Stat35C1Comparison\Sources as Previous;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use RuntimeException;

final class Sources
{
    public function __construct(private readonly Previous $previous, private readonly string $compositionSha = Contract::COMPOSITION_SHA) {}

    public function open(string $input, string $baseline, string $composition): array
    {
        $source = $this->previous->open($input, $baseline);
        $seal = Files::identity($composition.'/manifest.json');
        if ($seal['sha256'] !== $this->compositionSha) {
            throw new RuntimeException('Unaccepted PR92 source manifest.');
        }
        Files::same($seal, Files::json($composition.'/COMPLETE.json'), 'PR92 COMPLETE');
        $manifest = Files::json($composition.'/manifest.json');
        Files::same(CompositionContract::plan(), $manifest['contract'], 'PR92 frozen contract');
        $source['seals'][$composition.'/manifest.json'] = $seal;
        $source['seals'][$composition.'/COMPLETE.json'] = Files::identity($composition.'/COMPLETE.json');
        $source['pr92'] = $composition;
        $source['pr92_provenance'] = $manifest['code'];
        $inputManifest = Files::json($input.'/manifest.json');
        $source['history'] = $inputManifest['source']['history'];
        foreach (['manifest.json', 'COMPLETE.json', 'player-meetings.jsonl'] as $file) {
            $path = $source['history'].'/'.$file;
            $source['seals'][$path] = $inputManifest['source']['seals'][$path] ?? throw new RuntimeException('Missing history seal.');
        }
        Files::verify($source['history'].'/manifest.json', Files::json($source['history'].'/COMPLETE.json'));
        $historyManifest = Files::json($source['history'].'/manifest.json');
        Files::same($source['seals'][$source['history'].'/player-meetings.jsonl'],
            $historyManifest['files']['player-meetings.jsonl'], 'history child seal');
        foreach ([2022, 2023, 2024, 2025] as $year) {
            $audit = $input.'/audit-'.$year.'.jsonl';
            $source['paths'][$year]['audit'] = $audit;
            $source['seals'][$audit] = $inputManifest['files'][basename($audit)] ?? throw new RuntimeException('Missing audit seal.');
            if ($year < 2024) {
                continue;
            }
            $path = $composition.'/run-01/predictions-'.$year.'.jsonl';
            $source['paths'][$year]['composition'] = $path;
            $source['seals'][$path] = $manifest['files']['run-01/'.basename($path)];
            foreach (['input' => 'input', 'baseline' => 'prediction', 'teacher' => 'labels', 'model' => 'model'] as $old => $key) {
                Files::same($source['seals'][$source['paths'][$year][$old]],
                    $manifest['source'][$old === 'teacher' ? 'outcome_seals' : 'seals'][$manifest['source']['paths'][$year][$key]], 'PR92 parent '.$old);
            }
        }
        self::verify($source);

        return $source;
    }

    public static function verify(array $source): void
    {
        Previous::verify($source);
    }
}
