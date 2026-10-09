<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\C1CompositionRequest;

use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Contract as Model;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Input;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact as Jsonl;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use RuntimeException;

final class Store
{
    public function root(string $path, Sources $sources): string
    {
        self::safe($path);
        $existing = realpath($path);
        $parent = $existing === false ? realpath(dirname($path)) : dirname($existing);
        $test = app()->environment('testing') && $parent !== false && str_starts_with($parent, '/tmp/');
        if ($parent === false || (! $test && $parent !== Model::ROOT)
            || $path !== $parent.'/'.basename($path)
            || preg_match('/\Arequest-store-01-[A-Za-z0-9_-]{1,80}\z/', basename($path)) !== 1
            || str_starts_with($path, base_path().'/')) {
            throw new RuntimeException('Invalid dedicated request store root.');
        }
        foreach ([dirname($sources->artifact), dirname($sources->input)] as $source) {
            if ($path === $source || str_starts_with($path, $source.'/') || str_starts_with($source, $path.'/')) {
                throw new RuntimeException('Store overlaps fixed source.');
            }
        }

        return $path;
    }

    public static function safe(string $path): void
    {
        if (! str_starts_with($path, '/') || str_contains($path, "\0") || str_contains($path, '\\')
            || preg_match('~(?:^|/)(?:\.|\.\.)(?:/|$)|//|/$~', $path)) {
            throw new RuntimeException('Invalid canonical absolute path.');
        }
        // Saved-only processes may allow this store without granting access to its ancestors.
        $resolved = @realpath($path);
        if ($resolved !== false && $resolved !== $path) {
            throw new RuntimeException('Symlink or noncanonical request path.');
        }
        $part = '';
        foreach (explode('/', ltrim($path, '/')) as $component) {
            $part .= '/'.$component;
            clearstatcache(true, $part);
            if (@is_link($part)) {
                throw new RuntimeException('Symlink in request path.');
            }
        }
    }

    public function prepare(string $root): void
    {
        if (! file_exists($root)) {
            Files::directory($root);
        }
        if (! is_dir($root)) {
            throw new RuntimeException('Store is not a directory.');
        }
        $marker = ['version' => Contract::VERSION, 'kind' => 'DEDICATED_COMPOSITION_REQUEST_STORE'];
        if (! file_exists($root.'/STORE.json')) {
            if (scandir($root) !== ['.', '..']) {
                throw new RuntimeException('Unowned nonempty request store.');
            }
            Jsonl::json($root.'/STORE.json', $marker);
        }
        self::safe($root.'/STORE.json');
        Files::same($marker, Files::json($root.'/STORE.json'), 'store ownership');
        foreach (['requests', '.guards'] as $name) {
            self::safe($root.'/'.$name);
            if (! file_exists($root.'/'.$name)) {
                Files::directory($root.'/'.$name);
            }
            if (! is_dir($root.'/'.$name)) {
                throw new RuntimeException('Invalid store directory.');
            }
        }
    }

    public function read(string $root, string $id): ?array
    {
        Contract::id($id);
        $path = $root.'/requests/'.$id;
        self::safe($path);
        if (! file_exists($path)) {
            return null;
        }
        self::safe($root.'/STORE.json');
        Files::same(['version' => Contract::VERSION, 'kind' => 'DEDICATED_COMPOSITION_REQUEST_STORE'],
            Files::json($root.'/STORE.json'), 'saved store ownership');

        return $this->verify($path, $id);
    }

    public function verify(string $path, string $id): array
    {
        self::safe($path);
        $names = [...Contract::FILES, 'manifest.json', 'COMPLETE.json'];
        sort($names, SORT_STRING);
        if (! is_dir($path) || scandir($path) !== ['.', '..', ...$names]) {
            throw new RuntimeException('Incomplete or unexpected request inventory.');
        }
        foreach ($names as $name) {
            self::safe($path.'/'.$name);
            Files::identity($path.'/'.$name);
        }
        $completion = Files::json($path.'/COMPLETE.json');
        Files::verify($path.'/manifest.json', $completion);
        $completionBytes = json_encode($completion, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES)."\n";
        Files::verify($path.'/COMPLETE.json', ['bytes' => strlen($completionBytes), 'sha256' => hash('sha256', $completionBytes)]);
        $manifest = Files::json($path.'/manifest.json');
        Files::same(Contract::FILES, array_keys($manifest['files'] ?? []), 'request inventory');
        foreach ($manifest['files'] as $name => $seal) {
            Files::verify($path.'/'.$name, $seal);
        }
        $request = Files::json($path.'/request.json');
        Files::same(Contract::plan(), $manifest['use'] ?? [], 'request use restrictions');
        if (($manifest['status'] ?? null) !== 'COMPLETE_DEVELOPMENT_REQUEST'
            || ! array_key_exists('input_as_of', $manifest) || $manifest['input_as_of'] !== null
            || ! array_key_exists('observed_at', $manifest) || $manifest['observed_at'] !== null) {
            throw new RuntimeException('Invalid request completion / unknown historical timing.');
        }
        Files::same($manifest['request'], $request, 'request metadata');
        if (($request['request_id'] ?? null) !== $id || ($request['request_version'] ?? null) !== Contract::VERSION
            || ($request['mode'] ?? null) !== Contract::MODE || ! in_array($request['year'] ?? null, [2024, 2025], true)) {
            throw new RuntimeException('Invalid saved request identity.');
        }
        Files::same($request['sources'], Files::json($path.'/model-reference.json')['sources'], 'model reference');
        Files::same($request['code'], Files::json($path.'/runtime.json')['request_code'], 'request runtime');
        $inputs = iterator_to_array(Input::read($path.'/input.jsonl'));
        $predictions = iterator_to_array(Jsonl::read($path.'/prediction.jsonl'));
        if (count($inputs) !== 1 || count($predictions) !== 1) {
            throw new RuntimeException('Request must contain one complete race.');
        }
        $race = $inputs[0];
        $prediction = $predictions[0];
        self::withoutOutcomes($prediction);
        foreach ([$race, $prediction['probabilities'], $prediction['decision']] as $row) {
            if (($row['year'] ?? null) !== $request['year'] || ($row['race_id'] ?? null) !== $request['race_id']) {
                throw new RuntimeException('Input / prediction target mismatch.');
            }
        }
        $entries = $prediction['probabilities']['entries'];
        $pairs = static fn (array $rows): array => array_map(static fn (array $e): array => [$e['id'], $e['bike']], $rows);
        Files::same($pairs($race['entries']), $pairs($entries), 'input / prediction entrants');
        Files::same($pairs($entries), $manifest['entrants'], 'manifest entrants');
        foreach ($entries as $i => $entry) {
            foreach (['raw', 'anchor', 'stat01_rank'] as $field) {
                if ($entry[$field] != $race['entries'][$i][$field]) {
                    throw new RuntimeException('Prediction input value mismatch.');
                }
            }
            foreach ([1, 2, 3] as $position) {
                $p = $entry['position_'.$position.'_probability'] ?? null;
                if ((! is_float($p) && ! is_int($p)) || ! is_finite($p) || $p < 0 || $p > 1) {
                    throw new RuntimeException('Invalid stored marginal probability.');
                }
            }
        }
        $primary = [];
        foreach ([1, 2, 3] as $position) {
            $primary[] = $prediction['decision']['primary_position_'.$position.'_bike'] ?? null;
        }
        if (count(array_unique($primary)) !== 3 || array_diff($primary, array_column($entries, 'bike')) !== []) {
            throw new RuntimeException('Invalid stored Primary decision.');
        }

        return ['path' => $path, 'manifest' => $manifest, 'input' => $race, 'prediction' => $prediction];
    }

    private static function withoutOutcomes(array $row): void
    {
        foreach ($row as $key => $value) {
            if (is_string($key) && preg_match('/(?:^|_)(?:rank|status|label|actual|payout|result)(?:_|$)/', $key)
                && $key !== 'stat01_rank') {
                throw new RuntimeException('Outcome field forbidden in saved prediction.');
            }
            if (is_array($value)) {
                self::withoutOutcomes($value);
            }
        }
    }
}
