<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\C1CompositionResult;

use App\Domain\Keirin\Backtest\Experiments\C1CompositionRequest\Contract as Request;
use App\Domain\Keirin\Backtest\Experiments\C1CompositionRequest\Store as Requests;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Contract as Model;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Publication;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact as Jsonl;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use RuntimeException;

class Store
{
    public function __construct(private readonly Publication $publication, private readonly Calculation $calculation,
        private readonly Requests $requests) {}

    protected function allowedParent(): string
    {
        return Model::ROOT;
    }

    public function root(string $path, array $protected = []): string
    {
        Requests::safe($path);
        if (realpath(dirname($path)) !== $this->allowedParent()
            || $path !== $this->allowedParent().'/'.basename($path)
            || ! preg_match('/\Aresult-store-01-[A-Za-z0-9_-]{1,80}\z/', basename($path))) {
            throw new RuntimeException('Invalid dedicated result store root.');
        }
        foreach ([base_path(), ...$protected] as $source) {
            Requests::safe($source);
            if ($path === $source || str_starts_with($path.'/', $source.'/') || str_starts_with($source.'/', $path.'/')) {
                throw new RuntimeException('Result output overlaps protected source.');
            }
        }

        return $path;
    }

    public function prepare(string $root): void
    {
        $marker = ['version' => Contract::VERSION, 'kind' => 'DEDICATED_COMPOSITION_RESULT_STORE'];
        if (! file_exists($root)) {
            Files::directory($root);
        }
        Requests::safe($root.'/STORE.json');
        if (! file_exists($root.'/STORE.json')) {
            if (scandir($root) !== ['.', '..']) {
                throw new RuntimeException('Unowned nonempty result store.');
            }
            Jsonl::json($root.'/STORE.json', $marker);
        }
        Files::same($marker, Files::json($root.'/STORE.json'), 'result store ownership');
        foreach (['evaluations', '.guards', 'reproductions'] as $name) {
            Requests::safe($root.'/'.$name);
            if (! file_exists($root.'/'.$name)) {
                Files::directory($root.'/'.$name);
            }
            if (! is_dir($root.'/'.$name)) {
                throw new RuntimeException('Invalid result store directory.');
            }
        }
    }

    public function json(string $stage, string $name, array $value, array &$expected): void
    {
        $expected[$name] = self::jsonSeal($value);
        $this->publication->json($stage.'/'.$name, $value);
    }

    public function rows(string $stage, string $name, iterable $rows, array &$expected): void
    {
        $count = $bytes = 0;
        $hash = hash_init('sha256');
        $wrapped = (function () use ($rows, &$count, &$bytes, $hash): \Generator {
            foreach ($rows as $row) {
                $line = Files::canonical($row)."\n";
                $count++;
                $bytes += strlen($line);
                hash_update($hash, $line);
                yield $row;
            }
        })();
        $this->publication->rows($stage.'/'.$name, $wrapped);
        $seal = ['rows' => $count, 'bytes' => $bytes, 'sha256' => hash_final($hash)];
        $expected[$name] = $seal;
        $expected[$name.'.manifest.json'] = self::jsonSeal($seal);
    }

    public function copyRequests(string $stage, array $source, array &$expected): void
    {
        Files::directory($stage.'/requests');
        foreach ($source['selection']['targets'] as $target) {
            $id = $target['request_id'];
            Files::directory($stage.'/requests/'.$id);
            foreach ([...Request::FILES, 'manifest.json', 'COMPLETE.json'] as $name) {
                $original = $source['request_root'].'/requests/'.$id.'/'.$name;
                $relative = 'requests/'.$id.'/'.$name;
                $seal = $source['seals'][$original];
                Files::verify($original, $seal);
                if ($seal['bytes'] > 16777216) {
                    throw new RuntimeException('Oversized single-race request copy.');
                }
                $bytes = file_get_contents($original);
                if ($bytes === false || self::byteSeal($bytes) !== $seal) {
                    throw new RuntimeException('Request changed while copying.');
                }
                $expected[$relative] = $seal;
                $handle = fopen($stage.'/'.$relative, 'xb');
                if ($handle === false) {
                    throw new RuntimeException('Exclusive request copy failed.');
                }
                try {
                    if (fwrite($handle, $bytes) !== strlen($bytes) || ! fflush($handle) || ! fsync($handle)) {
                        throw new RuntimeException('Request copy flush failed.');
                    }
                } finally {
                    fclose($handle);
                }
            }
        }
    }

    public static function names(array $targets): array
    {
        $names = Contract::FILES;
        foreach ($targets as $target) {
            Request::id($target['request_id']);
            foreach ([...Request::FILES, 'manifest.json', 'COMPLETE.json'] as $name) {
                $names[] = 'requests/'.$target['request_id'].'/'.$name;
            }
        }

        return $names;
    }

    public function verifyExpected(string $stage, array $expected): void
    {
        foreach ($expected as $name => $seal) {
            Files::verify($stage.'/'.$name, $seal);
        }
    }

    public function verify(string $path, string $id): array
    {
        Request::id($id);
        Requests::safe($path);
        $complete = Files::json($path.'/COMPLETE.json');
        Files::verify($path.'/COMPLETE.json', self::jsonSeal($complete));
        Files::verify($path.'/manifest.json', $complete);
        $manifest = Files::json($path.'/manifest.json');
        $identity = Files::json($path.'/request.json');
        Files::same($identity, $manifest['request'] ?? [], 'saved evaluation identity');
        Files::same(Contract::plan(), $identity['contract'] ?? [], 'result use / calculation contract');
        $targets = $identity['selection']['targets'] ?? [];
        if (($identity['evaluation_id'] ?? null) !== $id || ($identity['selection']['result_year'] ?? null) !== 2025
            || ! is_array($targets) || ! array_is_list($targets) || count($targets) < 1 || count($targets) > 10
            || ($manifest['status'] ?? null) !== 'COMPLETE_COMPOSITION_RESULT') {
            throw new RuntimeException('Invalid completed evaluation identity.');
        }
        $names = self::names($targets);
        Files::same($names, array_keys($manifest['files'] ?? []), 'result manifest inventory');
        $all = [...$names, 'manifest.json', 'COMPLETE.json'];
        sort($all, SORT_STRING);
        $actual = [];
        if (! is_dir($path)) {
            throw new RuntimeException('Incomplete result directory.');
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST) as $file) {
            Requests::safe($file->getPathname());
            if ($file->isLink() || (! $file->isFile() && ! $file->isDir())) {
                throw new RuntimeException('Invalid result inventory item.');
            }
            if ($file->isFile()) {
                $actual[] = substr($file->getPathname(), strlen($path) + 1);
            }
        }
        sort($actual, SORT_STRING);
        Files::same($all, $actual, 'exact result file inventory');
        $this->verifyExpected($path, $manifest['files']);
        $code = Files::json($path.'/code.json');
        if (hash('sha256', Files::canonical($code)) !== ($identity['code_sha256'] ?? null)) {
            throw new RuntimeException('Saved execution code identity mismatch.');
        }
        $source = Files::json($path.'/sources.json');
        if (hash('sha256', Files::canonical($source)) !== ($identity['source_sha256'] ?? null)) {
            throw new RuntimeException('Saved source identity mismatch.');
        }
        Files::same($identity['selection'], $source['selection'], 'saved selection');
        $fixed = iterator_to_array(Jsonl::read($path.'/fixed.jsonl'), false);
        if (count($fixed) !== count($targets)) {
            throw new RuntimeException('Fixed selection count mismatch.');
        }
        $seen = $ids = [];
        foreach ($targets as $i => $target) {
            if (($target['year'] ?? null) !== 2025 || ! is_int($target['race_id'] ?? null) || $target['race_id'] < 1
                || isset($seen[$target['race_id']]) || isset($ids[$target['request_id']])) {
                throw new RuntimeException('Duplicate / invalid saved target.');
            }
            $seen[$target['race_id']] = $ids[$target['request_id']] = true;
            $saved = $this->requests->verify($path.'/requests/'.$target['request_id'], $target['request_id']);
            Files::verify($saved['path'].'/manifest.json', $target['manifest']);
            if ($saved['input']['race_id'] !== $target['race_id'] || $saved['input']['year'] !== 2025) {
                throw new RuntimeException('Copied request target mismatch.');
            }
            Files::same(['request_id' => $target['request_id'], 'input' => $saved['input'], 'prediction' => $saved['prediction']],
                $fixed[$i], 'saved fixed predictions / copied request');
        }
        $freeze = Files::json($path.'/freeze.json');
        Files::same(['order' => ['SELECTION_VERIFIED', 'REQUESTS_VERIFIED', 'FIXED_PREDICTIONS_SEALED', 'RESULTS_MAY_NOW_BE_PARSED'],
            'fixed' => $manifest['files']['fixed.jsonl'], 'request_count' => count($targets),
            'not_a_historical_prestart_claim' => true], $freeze, 'prediction-before-result processing proof');
        Files::same(['status' => 'UNCHANGED', 'seals' => $source['seals'], 'code' => $code],
            Files::json($path.'/source-end.json'), 'source and code END proof');
        $calculated = $this->calculation->compute($path.'/fixed.jsonl', $path.'/results.jsonl');
        $this->verifyMeaning($path, $calculated, $manifest['files']);

        return ['path' => $path, 'manifest' => $manifest, 'summary' => $calculated['summary.json'],
            'races' => $calculated['contributions.jsonl']];
    }

    public function verifyMeaning(string $path, array $calculated, array $expected): void
    {
        foreach ($calculated as $name => $value) {
            $seal = str_ends_with($name, '.jsonl') ? self::rowsSeal($value) : self::jsonSeal($value);
            Files::same($seal, $expected[$name], 'strict semantic output '.$name);
            Files::verify($path.'/'.$name, $seal);
            if (str_ends_with($name, '.jsonl')) {
                Files::same($seal, Files::json($path.'/'.$name.'.manifest.json'), 'semantic rows / sidecar');
            }
        }
    }

    public static function jsonSeal(array $value): array
    {
        return self::byteSeal(json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES)."\n");
    }

    public static function rowsSeal(array $rows): array
    {
        $bytes = 0;
        $hash = hash_init('sha256');
        foreach ($rows as $row) {
            $line = Files::canonical($row)."\n";
            $bytes += strlen($line);
            hash_update($hash, $line);
        }

        return ['rows' => count($rows), 'bytes' => $bytes, 'sha256' => hash_final($hash)];
    }

    private static function byteSeal(string $bytes): array
    {
        return ['bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
    }
}
