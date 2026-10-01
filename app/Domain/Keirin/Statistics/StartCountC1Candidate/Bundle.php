<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\StartCountC1Candidate;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use RuntimeException;

final class Bundle
{
    public static function open(string $path, string $purpose = 'review'): array
    {
        if ($purpose !== 'review') {
            throw new RuntimeException('BLOCKED_INPUT_SEMANTICS: S timing and training/evaluation use are not authorized.');
        }
        if (realpath($path) !== $path) {
            throw new RuntimeException('Canonical candidate path required.');
        }
        Files::verify($path.'/manifest.json', Files::json($path.'/COMPLETE.json'));
        $m = Files::json($path.'/manifest.json');
        if (($m['version'] ?? null) !== Contract::VERSION || ($m['status'] ?? null) !== 'CANDIDATES_PREPARED_NOT_AUTHORIZED'
            || ($m['restrictions'] ?? null) !== Contract::restrictions()) {
            throw new RuntimeException('Unknown candidate contract or restrictions.');
        }
        $names = ['contract.json', 'source-observation-links.jsonl', 'coverage.json', 'coverage.csv',
            'timing-status.json', 'invariance.json', 'verification.json'];
        foreach (Contract::YEARS as $year) {
            $names[] = 'candidates-'.$year.'.jsonl';
            $names[] = 'mapping-audit-'.$year.'.jsonl';
        }
        \App\Domain\Keirin\Statistics\AgariC1Input\Contract::keys($m['files'], $names);
        foreach ($m['files'] as $name => $seal) {
            Files::verify($path.'/'.$name, $seal);
        }

        return $m;
    }

    public static function openForTraining(string $path): never
    {
        self::open($path, 'training');
        throw new RuntimeException('BLOCKED_INPUT_SEMANTICS');
    }
}
