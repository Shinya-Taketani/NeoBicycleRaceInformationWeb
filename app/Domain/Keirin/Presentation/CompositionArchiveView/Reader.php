<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Presentation\CompositionArchiveView;

use App\Domain\Keirin\Backtest\Calculators\Bt03e05MetricEvaluator;
use App\Domain\Keirin\Backtest\Experiments\C1CompositionArchive\Contract;
use App\Domain\Keirin\Backtest\Experiments\C1CompositionArchive\Metrics;
use App\Domain\Keirin\Backtest\Experiments\C1CompositionRequest\PredictionVerifier;
use App\Domain\Keirin\Backtest\Experiments\C1CompositionRequest\Store;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Contract as Model;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Input;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact as Jsonl;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionResult\Matcher;
use RuntimeException;

final class Reader
{
    public function __construct(private readonly Matcher $matcher, private readonly PredictionVerifier $predictions,
        private readonly Metrics $metrics) {}

    public static function integer(mixed $value): ?int
    {
        if (! is_string($value) || ! preg_match('/^[1-9][0-9]*$/D', $value)) {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id !== false && (string) $id === $value ? $id : null;
    }

    public function read(?int $page = null, ?int $raceId = null): ?array
    {
        $root = config('composition_archive_view.root');
        $pin = config('composition_archive_view.manifest');
        if (! is_string($root) || ! is_array($pin)) {
            throw new RuntimeException('Archive view not configured.');
        }
        Store::safe($root);
        $synthetic = app()->environment('testing') && str_starts_with($root, '/home/shinya/Desktop/composition-result-temporary-');
        if ((! $synthetic && ! str_starts_with($root, Model::ROOT.'/')) || realpath($root) !== $root) {
            throw new RuntimeException('Unapproved archive root.');
        }
        $manifestPath = $root.'/manifest.json';
        Files::verify($manifestPath, $pin);
        $completePath = $root.'/COMPLETE.json';
        Store::safe($completePath);
        $complete = Files::identity($completePath);
        Files::same($pin, Files::json($completePath), 'archive completion pin');
        $manifest = Files::json($manifestPath);
        $count = $manifest['count'] ?? null;
        if (($manifest['version'] ?? null) !== Contract::VERSION || ($manifest['status'] ?? null) !== 'COMPLETE_SAVED_PREDICTION_ARCHIVE'
            || ($manifest['year'] ?? null) !== 2025 || ($manifest['page_size'] ?? null) !== Contract::PAGE_SIZE
            || ! is_int($count) || $count < 1 || $count > 24866
            || ($manifest['page_count'] ?? null) !== (int) ceil($count / Contract::PAGE_SIZE)
            || ($manifest['order'] ?? null) !== Contract::plan()['order']) {
            throw new RuntimeException('Invalid archive manifest contract.');
        }
        Files::same(Contract::plan(), $manifest['use'] ?? [], 'archive use restrictions');
        if ($page !== null && ($page < 1 || $page > $manifest['page_count'])) {
            return null;
        }
        $page ??= 1;
        $expected = $this->indexedPage($root, $manifest, $page, $raceId);
        if ($expected === null) {
            return null;
        }
        $page = $expected['page'];
        $summary = Files::json($this->file($root, $manifest, 'summary.json'));
        if (($summary['matched'] ?? null) !== $count || ($summary['missing'] ?? null) !== 0 || ($summary['mismatched'] ?? null) !== 0
            || array_keys($summary['metrics'] ?? []) !== Bt03e05MetricEvaluator::METRIC_CODES) {
            throw new RuntimeException('Invalid saved archive summary.');
        }
        Files::same(Contract::plan(), $summary['use'] ?? [], 'summary use');
        $name = 'pages/'.sprintf('%06d', $page).'.jsonl';
        $path = $this->file($root, $manifest, $name);
        $this->file($root, $manifest, $name.'.manifest.json');
        $rows = [];
        foreach (Jsonl::read($path) as $offset => $row) {
            $ordinal = ($page - 1) * Contract::PAGE_SIZE + $offset + 1;
            $id = $row['input']['race_id'] ?? null;
            if (($row['ordinal'] ?? null) !== $ordinal || ($expected['races'][$offset] ?? null) !== $id || count($rows) >= Contract::PAGE_SIZE) {
                throw new RuntimeException('Page / index correspondence mismatch.');
            }
            Input::validate($row['input'], [2025]);
            $this->predictions->verify($row['joined']['prediction']);
            $joined = $this->matcher->join(['request_id' => 'archive-2025-r'.$id, 'input' => $row['input'],
                'prediction' => $row['joined']['prediction']], $row['result']);
            Files::same($joined, $row['joined'], 'page saved input / result / prediction');
            Files::same($this->metrics->display($joined, $this->matcher->comparison($joined)), $row['contribution'], 'page metric contribution');
            $rows[] = $row;
        }
        if (count($rows) !== count($expected['races'])) {
            throw new RuntimeException('Incomplete archive page.');
        }
        foreach (['summary.json', 'race-index.jsonl', 'race-index.jsonl.manifest.json', $name, $name.'.manifest.json'] as $file) {
            $this->file($root, $manifest, $file);
        }
        Files::verify($manifestPath, $pin);
        Files::verify($completePath, $complete);

        return ['manifest' => $manifest, 'summary' => $summary, 'page' => $page, 'rows' => $rows];
    }

    private function indexedPage(string $root, array $manifest, int $page, ?int $raceId): ?array
    {
        $path = $this->file($root, $manifest, 'race-index.jsonl');
        $this->file($root, $manifest, 'race-index.jsonl.manifest.json');
        $seen = [];
        $selected = [];
        $found = null;
        $ordinal = 0;
        // A small identity-only index is streamed; annual payloads and other pages are never opened.
        foreach (Jsonl::read($path) as $row) {
            $id = $row['race_id'] ?? null;
            if (! is_int($id) || $id < 1 || isset($seen[$id]) || ($row['ordinal'] ?? null) !== ++$ordinal
                || ($row['page'] ?? null) !== intdiv($ordinal - 1, Contract::PAGE_SIZE) + 1
                || ($row['offset'] ?? null) !== ($ordinal - 1) % Contract::PAGE_SIZE || $ordinal > $manifest['count']) {
                throw new RuntimeException('Invalid archive race index.');
            }
            $seen[$id] = true;
            if ($raceId === $id) {
                $found = $row['page'];
            }
            if ($raceId === null && $row['page'] === $page) {
                $selected[] = $id;
            }
        }
        if ($ordinal !== $manifest['count']) {
            throw new RuntimeException('Incomplete race index.');
        }
        if ($raceId !== null) {
            if ($found === null) {
                return null;
            }
            $page = $found;
            foreach (Jsonl::read($path) as $row) {
                if ($row['page'] === $page) {
                    $selected[] = $row['race_id'];
                }
            }
        }

        return ['page' => $page, 'races' => $selected];
    }

    private function file(string $root, array $manifest, string $name): string
    {
        $path = $root.'/'.$name;
        Store::safe($path);
        $seal = $manifest['files'][$name] ?? null;
        if (! is_array($seal)) {
            throw new RuntimeException('Missing archive file seal.');
        }
        Files::verify($path, $seal);

        return $path;
    }
}
