<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\C1CompositionRequest;

use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Contract as Model;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use RuntimeException;

final class Contract
{
    public const VERSION = 'C1-STAT35-COMPOSITION-REQUEST-01-v1';

    public const MODE = 'DEVELOPMENT_FEATURE_SNAPSHOT_REPLAY';

    public const PACKAGE = Model::ROOT.'/pr93-repackage-commit-fix-20261009-e91a7f3c/package/artifact.json';

    public const INPUT = Model::ROOT.'/run-20261009-LE6Wit1O/result/verified-inputs/features-2025.jsonl';

    public const ARTIFACT_SEAL = ['bytes' => 119032, 'sha256' => '75f793599687da3f6a946db6500921463df496144e6844e97a8c019a458a3de9'];

    public const RECEIPT_SEAL = ['bytes' => 847, 'sha256' => 'd0ddc8bbe9e04e812f467c967fec765e8943e660fa224b7abdbe10bbcb084755'];

    public const INPUT_SEAL = ['bytes' => 72144103, 'sha256' => '4bf8c8ccd61125e2562807154b4cffafcb6fcd2985521206e59cfc9df0fa6bfa'];

    public const FILES = ['request.json', 'input.jsonl', 'input.jsonl.manifest.json', 'input.jsonl.input.json',
        'prediction.jsonl', 'prediction.jsonl.manifest.json', 'model-reference.json', 'runtime.json'];

    public static function id(string $id): void
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,95}\z/', $id) !== 1) {
            throw new RuntimeException('Invalid request_id.');
        }
    }

    public static function number(string $value): int
    {
        if (preg_match('/\A[1-9][0-9]*\z/', $value) !== 1 || (string) (int) $value !== $value) {
            throw new RuntimeException('Invalid positive integer request value.');
        }

        return (int) $value;
    }

    public static function plan(): array
    {
        return ['request_version' => self::VERSION, 'mode' => self::MODE, 'years' => [2025],
            'artifact' => self::ARTIFACT_SEAL, 'receipt' => self::RECEIPT_SEAL, 'input' => self::INPUT_SEAL,
            'input_version' => Model::INPUT_VERSION, 'probability' => Model::plan()['probability'],
            'decoder' => Model::plan()['decoder'], 'historical_as_of_available' => false,
            'formal_adoption' => false, 'formal_freeze' => false, 'live_use_authorized' => false,
            '2026_access' => 'FORBIDDEN', 'points' => null,
            'performance' => 'NOT_PERFORMED_REQUEST_FLOW_TECHNICAL_VERIFICATION_ONLY'];
    }

    public static function code(): array
    {
        $paths = [...glob(__DIR__.'/*.php'), base_path('app/Console/Commands/Keirin/CompositionPredictionRequestCommand.php')];
        $code = [];
        foreach ($paths as $path) {
            $code[substr($path, strlen(base_path()) + 1)] = Files::identity($path);
        }
        ksort($code, SORT_STRING);

        return $code;
    }
}
