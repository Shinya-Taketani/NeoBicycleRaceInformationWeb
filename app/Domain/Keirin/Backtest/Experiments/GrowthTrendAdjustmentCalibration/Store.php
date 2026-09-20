<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\GrowthTrendAdjustmentCalibration;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionResult\ResultStore;
use RuntimeException;

class Store
{
    public const INVENTORY = ['contract.json', 'candidate-grid.json', 'code.json', 'sources.json',
        'signal-projection.jsonl', 'signal-projection.jsonl.manifest.json',
        'prediction-input-2024.jsonl', 'prediction-input-2024.jsonl.manifest.json',
        'prediction-input-2025.jsonl', 'prediction-input-2025.jsonl.manifest.json',
        'prediction-reproduction.json', 'preflight.json', 'signal-scaling.json', 'signal-scaling-seal.json',
        'baseline-2024.json', 'coefficient-curve-2024.json', 'coefficient-curve-2024.csv', 'selection.json', 'selection-seal.json',
        'outcome-sources.json', 'baseline-2025.json', 'transfer-2025.json', 'coefficient-curve-2025.json', 'coefficient-curve-2025.csv',
        'coefficient-curve-pooled.json', 'coefficient-curve-pooled.csv',
        'selected-weight-details.jsonl', 'selected-weight-details.jsonl.manifest.json',
        'changed-race-diagnostics.json', 'growth-state-diagnostics.json', 'growth-magnitude-diagnostics.json',
        'c1-confidence-diagnostics.json', 'grade-class-diagnostics.json', 'first-observation-diagnostics.json',
        'old-calibration-comparison.json', 'temporal-access-audit.json', 'source-end.json'];

    public function __construct(private readonly ResultStore $writer) {}

    public function curve(string $stage, string $name, array $curve): array
    {
        $expected = $this->writer->writeJson($stage, $name.'.json', $curve);
        $fields = ['numerator', 'denominator', 'rate', 'baseline_numerator', 'baseline_rate', 'delta', 'delta_pp'];
        $changes = array_keys(Engine::changes([1, 2, 3], [1, 2, 3]));
        $header = ['k', 'w', 'purpose', 'races'];
        foreach (Contract::METRICS as $metric) {
            foreach ($fields as $field) {
                $header[] = $metric.'_'.$field;
            }
        }
        array_push($header, ...$changes);
        $stream = fopen('php://temp', 'w+');
        fputcsv($stream, $header, ',', '"', '');
        foreach ($curve['candidates'] as $c) {
            $values = [$c['k'], $c['w'], $curve['purpose'], $curve['races']];
            foreach (Contract::METRICS as $metric) {
                foreach ($fields as $field) {
                    $values[] = $c['metrics'][$metric][$field];
                }
            }
            foreach ($changes as $field) {
                $values[] = $c['changes'][$field];
            }
            fputcsv($stream, array_map(fn ($v) => is_float($v) ? sprintf('%.17g', $v) : $v, $values), ',', '"', '');
        }
        rewind($stream);
        $text = stream_get_contents($stream);
        fclose($stream);
        $out = fopen($stage.'/'.$name.'.csv', 'xb');
        if (! $out || fwrite($out, $text) !== strlen($text) || ! fflush($out) || ! fsync($out)) {
            throw new RuntimeException('CSV write failed.');
        }
        fclose($out);
        $expected[$name.'.csv'] = ['bytes' => strlen($text), 'sha256' => hash('sha256', $text)];
        $this->writer->verifyGenerated($stage, $expected);

        return $expected;
    }

    public function publish(string $stage, string $destination, array $expected): void
    {
        if (array_keys($expected) !== self::INVENTORY) {
            throw new RuntimeException('Incomplete calibration inventory.');
        }
        $this->writer->verifyGenerated($stage, $expected);
        $m = $this->writer->writeJson($stage, 'manifest.json', ['status' => 'CALIBRATION_LOCKED', 'contract' => Contract::plan(), 'files' => $expected]);
        $lock = $this->writer->writeJson($stage, 'LOCKED.json', $m['manifest.json']);
        $this->verify($stage);
        $this->writer->verifyGenerated($stage, $m + $lock);
        if (file_exists($destination) || is_link($destination) || ! rename($stage, $destination)) {
            throw new RuntimeException('Cannot publish without overwrite.');
        }
    }

    public function verify(string $path): array
    {
        if (realpath($path) !== $path || is_link($path)) {
            throw new RuntimeException('Unsafe calibration bundle.');
        }
        Files::verify($path.'/manifest.json', Files::json($path.'/LOCKED.json'));
        $m = Files::json($path.'/manifest.json');
        Files::same(Contract::plan(), $m['contract'], 'calibration contract');
        if ($m['status'] !== 'CALIBRATION_LOCKED' || array_keys($m['files']) !== self::INVENTORY) {
            throw new RuntimeException('Invalid calibration inventory.');
        }
        $this->writer->verifyGenerated($path, $m['files']);
        foreach (['signal-scaling', 'selection'] as $name) {
            Files::verify($path.'/'.$name.'.json', Files::json($path.'/'.$name.'-seal.json'));
        }

        return $m;
    }
}
