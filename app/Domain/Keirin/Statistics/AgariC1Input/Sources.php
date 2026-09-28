<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\AgariC1Input;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use RuntimeException;

final class Sources
{
    public const PINS = [
        'c1' => ['bytes' => 3823, 'sha256' => 'd5158a7671f8c453afc77dccc1ab0b089c940198f58f388c038ad0d4fad684cf'],
        'history' => ['bytes' => 12049, 'sha256' => '844440cb08b06b0e69195a6384689bc545e63806ff85237ca36a81f5dd08507e'],
    ];

    // No real identity/class bundle has been reviewed yet. Runtime self-seals cannot establish trust.
    public function __construct(private readonly array $pins = self::PINS, private readonly array $reviewedContextPins = []) {}

    public function open(string $c1, string $history, ?string $context): array
    {
        $seals = [];
        $add = static function (string $path, array $seal) use (&$seals): void {
            Files::verify($path, $seal);
            $seals[$path] = ['bytes' => $seal['bytes'], 'sha256' => $seal['sha256']];
        };
        $add($c1.'/manifest.json', $this->pins['c1']);
        $input = Files::json($c1.'/manifest.json');
        if (($input['calculation_version'] ?? null) !== Contract::C1_VERSION
            || array_keys($input['manifests'] ?? []) !== Contract::YEARS) {
            throw new RuntimeException('Unexpected C1 manifest version/years.');
        }
        foreach (Contract::YEARS as $year) {
            $add($c1.'/inputs-'.$year.'.jsonl', $input['manifests'][$year]['inputs']);
            $add($c1.'/history-'.$year.'.jsonl', $input['manifests'][$year]['history']);
            foreach (['inputs', 'history'] as $kind) {
                if (! is_int($input['manifests'][$year][$kind]['rows'] ?? null) || $input['manifests'][$year][$kind]['rows'] < 0) {
                    throw new RuntimeException('Invalid C1 manifest row count.');
                }
            }
        }
        $add($history.'/manifest.json', $this->pins['history']);
        $complete = Files::json($history.'/COMPLETE.json');
        Files::same($this->pins['history'], $complete, 'history completion');
        $add($history.'/COMPLETE.json', Files::identity($history.'/COMPLETE.json'));
        $hist = Files::json($history.'/manifest.json');
        if (($hist['version'] ?? null) !== 'STAT35-PLAYER-HISTORY-v1' || ($hist['kind'] ?? null) !== 'PLAYER_HISTORY'
            || ($hist['from'] ?? null) !== '2022-01-01' || ($hist['to'] ?? null) !== '2025-12-31'
            || ($hist['source'] ?? null) !== 'keirin_jp' || ($hist['historical_as_of_available'] ?? null) !== false) {
            throw new RuntimeException('Unexpected historical source contract.');
        }
        $add($history.'/player-meetings.jsonl', $hist['files']['player-meetings.jsonl']);
        if ($context !== null) {
            $identity = Files::identity($context.'/manifest.json');
            if (! in_array($identity, $this->reviewedContextPins, true)) {
                throw new RuntimeException('Unverified target context evidence.');
            }
            $seal = Files::json($context.'/COMPLETE.json');
            Files::same($identity, $seal, 'reviewed context completion');
            $add($context.'/manifest.json', $seal);
            $add($context.'/COMPLETE.json', Files::identity($context.'/COMPLETE.json'));
            $manifest = Files::json($context.'/manifest.json');
            Contract::keys($manifest, ['version', 'origin', 'historical_as_of_available', 'files']);
            if ($manifest['version'] !== Contract::CONTEXT_VERSION || $manifest['origin'] !== 'SAVED_ENTRY_AND_MEETING_METADATA'
                || $manifest['historical_as_of_available'] !== false || array_keys($manifest['files']) !== ['entry-context.jsonl']) {
                throw new RuntimeException('Unsupported target context evidence.');
            }
            $add($context.'/entry-context.jsonl', $manifest['files']['entry-context.jsonl']);
        }

        return ['c1' => $c1, 'history' => $history, 'context' => $context, 'seals' => $seals,
            'expected_rows' => array_map(fn ($v) => $v['inputs']['rows'], $input['manifests']),
            'expected_targets' => array_map(fn ($v) => $v['history']['rows'], $input['manifests'])];
    }

    public static function verify(array $source): void
    {
        foreach ($source['seals'] as $path => $seal) {
            Files::verify($path, $seal);
        }
    }
}
