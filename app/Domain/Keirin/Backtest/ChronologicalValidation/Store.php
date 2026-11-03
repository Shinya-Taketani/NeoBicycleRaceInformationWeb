<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\ChronologicalValidation;

use RuntimeException;
use Throwable;

final class Store
{
    public const KINDS = ['validation_run', 'day_manifest', 'input_bundle', 'prediction_bundle', 'result_bundle', 'evaluation_bundle', 'history_epoch'];

    private int $verificationDepth = 0;

    private array $verified = [];

    private array $visiting = [];

    private array $verifiedPayloads = [];

    private int $cachedBytes = 0;

    public function __construct(public readonly string $root, bool $initialize = true)
    {
        if (! str_starts_with($root, '/') || preg_match('~(?:^|/)(?:\.|\.\.)(?:/|$)|//|/$~', $root)
            || str_starts_with($root, dirname(__DIR__, 5).'/')
            || in_array($root, ['/', '/tmp', '/home/shinya', '/home/shinya/Desktop', '/home/shinya/Desktop/Trash', dirname(__DIR__, 5)], true)) {
            throw new RuntimeException('Dedicated canonical store root required.');
        }
        $parent = dirname($root);
        if (realpath($parent) !== $parent || is_link($parent)) {
            throw new RuntimeException('Store parent must exist without links.');
        }
        for ($path = $parent; $path !== '/'; $path = dirname($path)) {
            if (is_link($path)) {
                throw new RuntimeException('Store symlink forbidden.');
            }
        }
        if (! $initialize && ! is_dir($root)) {
            throw new RuntimeException('Existing store required for read-only verification.');
        }
        $exists = file_exists($root) || is_link($root);
        if ($exists) {
            BundleRules::require(is_dir($root) && ! is_link($root) && is_file($root.'/STORE.json'), 'Unrecorded existing root cannot be adopted.');
            BundleRules::require(Json::read($root.'/STORE.json') === ['version' => Json::VERSION], 'Store marker mismatch.');
        }
        if (! $exists && ! mkdir($root, 0700)) {
            throw new RuntimeException('Cannot create dedicated store.');
        }
        if (! $exists) {
            Json::write($root.'/STORE.json', Json::encode(['version' => Json::VERSION]));
        }
        foreach ([$root, $root.'/objects', $root.'/stages', $root.'/locks'] as $directory) {
            if (! file_exists($directory) && (! $initialize || ! mkdir($directory, 0700))) {
                throw new RuntimeException('Cannot create store directory.');
            }
            if (is_link($directory) || ! is_dir($directory) || realpath($directory) !== $directory
                || stat($directory)['dev'] !== stat($root)['dev']) {
                throw new RuntimeException('Store link or filesystem boundary forbidden.');
            }
        }
    }

    public function locked(string $id, callable $work): mixed
    {
        self::id($id);
        $path = $this->root.'/locks/'.$id;
        if (is_link($path) || (file_exists($path) && ! is_file($path))) {
            throw new RuntimeException('Invalid persistent lock.');
        }
        $lock = fopen($path, 'c+b');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Concurrent chronological execution refused.');
        }
        try {
            chmod($path, 0600);

            return $work();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function has(string $id): bool
    {
        self::id($id);

        return file_exists($this->root.'/objects/'.$id) || is_link($this->root.'/objects/'.$id);
    }

    public function put(string $id, string $kind, array $data): array
    {
        self::id($id);
        if (! in_array($kind, self::KINDS, true)) {
            throw new RuntimeException('Unknown bundle kind.');
        }

        return $this->locked('bundle-'.$id, function () use ($id, $kind, $data): array {
            if ($this->has($id)) {
                $old = $this->get($this->reference($id));
                if ($old['kind'] !== $kind || Json::encode($old['data']) !== Json::encode($data)) {
                    throw new RuntimeException('CONFLICT: existing bundle differs.');
                }

                return $this->reference($id);
            }
            $refs = self::references($data);
            foreach ($refs as $ref) {
                $this->get($ref);
            }
            (new BundleRules)->validate($kind, $data, $this);
            $stage = $this->root.'/stages/'.$id.'-'.bin2hex(random_bytes(12));
            if (! mkdir($stage, 0700)) {
                throw new RuntimeException('Cannot create exclusive stage.');
            }
            try {
                $bytes = Json::encode($data);
                BundleRules::require(strlen($bytes) <= 4 * 1024 * 1024, 'Bounded bundle payload capacity exceeded; stage retained.');
                Json::write($stage.'/data.json', $bytes);
                $manifest = ['serialization' => Json::VERSION, 'bundle_id' => $id, 'kind' => $kind,
                    'data' => ['bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)], 'references' => $refs];
                $manifestBytes = Json::encode($manifest);
                Json::write($stage.'/manifest.json', $manifestBytes);
                Json::write($stage.'/LOCKED', hash('sha256', $manifestBytes)."\n");
                $this->inventory($stage);
                Json::verify($stage.'/data.json', $manifest['data']);
                $destination = $this->root.'/objects/'.$id;
                if (file_exists($destination) || is_link($destination) || stat($stage)['dev'] !== stat(dirname($destination))['dev']
                    || ! rename($stage, $destination)) {
                    throw new RuntimeException('Non-overwriting same-filesystem publication failed.');
                }
            } catch (Throwable $error) {
                if (is_dir($stage) && ! file_exists($stage.'/FAILED.json')) {
                    Json::write($stage.'/FAILED.json', Json::encode(['exception' => $error::class, 'stage' => $id]));
                }
                throw $error;
            }

            return $this->reference($id);
        });
    }

    public function reference(string $id): array
    {
        self::id($id);
        $path = $this->root.'/objects/'.$id.'/manifest.json';
        Json::file($path);

        return ['bundle_id' => $id, 'manifest_sha256' => hash_file('sha256', $path)];
    }

    public function get(array $ref, array $ancestors = []): array
    {
        if ($this->verificationDepth === 0) {
            $this->verified = $this->visiting = [];
            $this->verifiedPayloads = [];
            $this->cachedBytes = 0;
        }
        $this->verificationDepth++;
        try {
            return $this->readVerified($ref, $ancestors);
        } finally {
            $this->verificationDepth--;
            if ($this->verificationDepth === 0) {
                $this->verified = $this->visiting = [];
                $this->verifiedPayloads = [];
                $this->cachedBytes = 0;
            }
        }
    }

    private function readVerified(array $ref, array $ancestors): array
    {
        self::id($ref['bundle_id'] ?? '');
        $id = $ref['bundle_id'];
        BundleRules::require(count($ref) === 2 && preg_match('/^[a-f0-9]{64}$/D', $ref['manifest_sha256'] ?? '') === 1, 'Invalid immutable reference.');
        if (isset($this->visiting[$id]) || in_array($id, $ancestors, true) || count($ancestors) > 256) {
            throw new RuntimeException('Cyclic or excessive bundle dependency.');
        }
        if (($this->verified[$id] ?? null) === $ref['manifest_sha256'] && isset($this->verifiedPayloads[$id])) {
            return $this->verifiedPayloads[$id];
        }
        $directory = $this->root.'/objects/'.$id;
        $this->inventory($directory);
        if ($this->reference($id) !== $ref) {
            throw new RuntimeException('Bundle reference hash mismatch.');
        }
        $manifest = Json::read($directory.'/manifest.json');
        BundleRules::require(is_int($manifest['data']['bytes'] ?? null) && $manifest['data']['bytes'] <= 4 * 1024 * 1024, 'Bounded bundle payload capacity exceeded.');
        if ($manifest['bundle_id'] !== $id || $manifest['serialization'] !== Json::VERSION
            || ! in_array($manifest['kind'], self::KINDS, true)
            || file_get_contents($directory.'/LOCKED') !== $ref['manifest_sha256']."\n") {
            throw new RuntimeException('Invalid immutable terminal manifest.');
        }
        Json::verify($directory.'/data.json', $manifest['data']);
        $data = Json::read($directory.'/data.json');
        if (self::references($data) !== $manifest['references']) {
            throw new RuntimeException('Incomplete dependency inventory.');
        }
        if (($this->verified[$id] ?? null) === $ref['manifest_sha256']) {
            return ['kind' => $manifest['kind'], 'data' => $data];
        }
        $this->visiting[$id] = true;
        foreach ($manifest['references'] as $parent) {
            $this->get($parent, [...$ancestors, $id]);
        }
        (new BundleRules)->validate($manifest['kind'], $data, $this);
        unset($this->visiting[$id]);
        $this->verified[$id] = $ref['manifest_sha256'];
        if ($this->cachedBytes + $manifest['data']['bytes'] <= 1024 * 1024) {
            $this->verifiedPayloads[$id] = ['kind' => $manifest['kind'], 'data' => $data];
            $this->cachedBytes += $manifest['data']['bytes'];
        }

        return ['kind' => $manifest['kind'], 'data' => $data];
    }

    public function all(): array
    {
        $refs = [];
        foreach (scandir($this->root.'/objects') as $id) {
            if ($id !== '.' && $id !== '..') {
                $ref = $this->reference($id);
                $this->get($ref);
                $refs[] = $ref;
            }
        }

        return $refs;
    }

    private function inventory(string $directory): void
    {
        if (is_link($directory) || ! is_dir($directory) || realpath($directory) !== $directory) {
            throw new RuntimeException('Invalid bundle directory.');
        }
        $files = array_values(array_diff(scandir($directory), ['.', '..']));
        sort($files);
        if ($files !== ['LOCKED', 'data.json', 'manifest.json']) {
            throw new RuntimeException('Bundle file inventory mismatch.');
        }
        foreach ($files as $file) {
            Json::file($directory.'/'.$file);
            if (lstat($directory.'/'.$file)['nlink'] !== 1) {
                throw new RuntimeException('Bundle hard link forbidden.');
            }
        }
    }

    private static function id(string $id): void
    {
        if (! preg_match('/^[A-Za-z0-9_-]{1,120}$/D', $id)) {
            throw new RuntimeException('Invalid bundle ID.');
        }
    }

    private static function references(array $data): array
    {
        $refs = [];
        $walk = function (array|\stdClass $node) use (&$walk, &$refs): void {
            $node = (array) $node;
            if (isset($node['bundle_id'], $node['manifest_sha256'])) {
                BundleRules::require(! isset($refs[$node['bundle_id']]) || $refs[$node['bundle_id']] === $node, 'Conflicting references to one bundle ID.');
                $refs[$node['bundle_id']] = $node;
            } else {
                foreach ($node as $value) {
                    if (is_array($value) || $value instanceof \stdClass) {
                        $walk($value);
                    }
                }
            }
        };
        $walk($data);
        ksort($refs, SORT_STRING);

        return array_values($refs);
    }
}
