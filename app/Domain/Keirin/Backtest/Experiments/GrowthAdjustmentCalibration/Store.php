<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\GrowthAdjustmentCalibration;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionResult\ResultStore;
use RuntimeException;

class Store
{
    public const INVENTORY = ['contract.json', 'candidate-grid.json', 'sources.json', 'code.json',
        'growth-signal-input.jsonl', 'growth-signal-input.jsonl.manifest.json',
        'prediction-input-2024.jsonl', 'prediction-input-2024.jsonl.manifest.json',
        'prediction-input-2025.jsonl', 'prediction-input-2025.jsonl.manifest.json',
        'preflight.json', 'baseline-reproduction.json', 'coefficient-curve-2024.json', 'coefficient-curve-2024.csv',
        'selection.json', 'selection-seal.json', 'coefficient-curve-2025.json', 'coefficient-curve-2025.csv',
        'validation-2025.json', 'coefficient-curve-pooled.json', 'coefficient-curve-pooled.csv',
        'selected-weight-details.jsonl', 'selected-weight-details.jsonl.manifest.json',
        'diagnostics.json', 'grade-class-diagnostics.json', 'source-end.json'];

    public function __construct(private readonly ResultStore $writer) {}

    public function curve(string $stage, string $name, array $curve): array
    {
        $expected = $this->writer->writeJson($stage, $name.'.json', $curve);
        $stream = fopen('php://temp', 'w+');
        $header = ['k', 'w', 'purpose', 'races'];
        foreach (Contract::METRICS as $m) {
            foreach (['numerator', 'denominator', 'rate', 'baseline_numerator', 'baseline_rate', 'delta'] as $field) {
                $header[] = $m.'_'.$field;
            }
        }
        array_push($header, 'P1_changed', 'P2_changed', 'P3_changed', 'any_changed', 'exact_same');
        fputcsv($stream, $header, ',', '"', '');
        foreach ($curve['candidates'] as $c) {
            $values = [$c['k'], $c['w'], $curve['purpose'], $curve['races']];
            foreach (Contract::METRICS as $m) {
                array_push($values, ...array_values($c['metrics'][$m]));
            }
            array_push($values, ...array_values($c['changes']));
            fputcsv($stream, array_map(fn ($v) => is_float($v) ? sprintf('%.17g', $v) : $v, $values), ',', '"', '');
        }
        rewind($stream);
        $text = stream_get_contents($stream);
        fclose($stream);
        $path = $stage.'/'.$name.'.csv';
        $out = fopen($path, 'xb');
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
        $manifest = $this->writer->writeJson($stage, 'manifest.json', ['status' => 'CALIBRATION_LOCKED', 'contract' => Contract::plan(),
            'files' => $expected, 'published_at' => gmdate(DATE_ATOM)]);
        $lock = $this->writer->writeJson($stage, 'LOCKED.json', $manifest['manifest.json']);
        $this->verify($stage);
        $this->writer->verifyGenerated($stage, $manifest + $lock);
        if (file_exists($destination) || is_link($destination) || ! rename($stage, $destination)) {
            throw new RuntimeException('Cannot publish calibration without overwrite.');
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
        Files::verify($path.'/selection.json', Files::json($path.'/selection-seal.json'));

        return $m;
    }
}
