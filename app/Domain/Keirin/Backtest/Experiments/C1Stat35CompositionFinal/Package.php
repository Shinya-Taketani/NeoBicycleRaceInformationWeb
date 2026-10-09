<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal;

use App\Domain\Keirin\Backtest\Experiments\Stat35C1Comparison\ModelLoader as C2Loader;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact as Jsonl;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\ModelLoader as C1Loader;
use RuntimeException;

final class Package
{
    public function __construct(private readonly C1Loader $c1, private readonly C2Loader $c2) {}

    public function stage(string $c1Artifact, string $c2Directory, array $selection, string $output, array $provenance, string $owner = '.'): string
    {
        Files::directory($output);
        Files::directory($output.'/c1');
        Files::directory($output.'/c2');
        $c1 = $this->c1->published($c1Artifact);
        $c2 = $this->c2->load($c2Directory.'/model.json', Files::identity($c2Directory.'/model.json'));
        if ($c1->fit->lambda !== 0.1 || $c2->fit->lambda !== $selection['lambda']) {
            throw new RuntimeException('Package parent lambda/selection mismatch.');
        }
        foreach (['artifact.json', 'model.json'] as $name) {
            if (! copy(dirname($c1Artifact).'/'.$name, $output.'/c1/'.$name)) {
                throw new RuntimeException('Could not copy byte-exact existing C1.');
            }
            Files::verify($output.'/c1/'.$name, Files::identity(dirname($c1Artifact).'/'.$name));
        }
        foreach (['model.json', 'layout.json'] as $name) {
            if (! copy($c2Directory.'/'.$name, $output.'/c2/'.$name)) {
                throw new RuntimeException('Could not copy new C2.');
            }
            Files::verify($output.'/c2/'.$name, Files::identity($c2Directory.'/'.$name));
        }
        Jsonl::json($output.'/c2/selection.json', $selection);
        $files = [];
        foreach (['c1/artifact.json', 'c1/model.json', 'c2/model.json', 'c2/layout.json', 'c2/selection.json'] as $name) {
            $files[$name] = Files::identity($output.'/'.$name);
        }
        Jsonl::json($output.'/artifact.json', ['contract' => Contract::plan(), 'role' => 'FINAL_DEVELOPMENT_COMPOSITION_PACKAGE',
            'parents' => ['C1' => ['artifact' => 'c1/artifact.json', 'model' => 'c1/model.json', 'seal' => $files['c1/model.json']],
                'C2' => ['model' => 'c2/model.json', 'layout' => 'c2/layout.json', 'selection' => 'c2/selection.json', 'seal' => $files['c2/model.json']]],
            'files' => $files, 'generation_code' => Contract::code(), 'provenance' => $provenance,
            'publication_version' => Contract::PUBLICATION_VERSION, 'publication_owner' => $owner]);
        $this->prepared($output.'/artifact.json');

        return $output.'/artifact.json';
    }

    public function load(string $artifact): array
    {
        $model = $this->prepared($artifact);
        $root = dirname($artifact);
        $owner = $model['artifact']['publication_owner'];
        if (! in_array($owner, ['.', '../..'], true)) {
            throw new RuntimeException('Invalid package publication owner.');
        }
        $publicationRoot = $owner === '.' ? $root : dirname($root, 2);
        foreach ([$root, $publicationRoot] as $path) {
            if (str_contains($path, '.inprogress-') || file_exists($path.'/FAILED.json') || is_link($path.'/FAILED.json')) {
                throw new RuntimeException('Prepared/failed package is not public.');
            }
        }
        Files::same(Files::identity(self::safe($publicationRoot, 'publication.json')),
            Files::json(self::safe($publicationRoot, 'COMPLETE.json')), 'publication commit proof');
        $proof = Files::json($publicationRoot.'/publication.json');
        $relative = substr($artifact, strlen($publicationRoot) + 1);
        if (($proof['version'] ?? null) !== Contract::PUBLICATION_VERSION || ($proof['state'] ?? null) !== 'COMMITTED'
            || ! in_array($relative, $proof['packages'] ?? [], true)
            || ($proof['files'][$relative] ?? null) !== $model['seal']) {
            throw new RuntimeException('Package does not belong to this committed publication.');
        }
        if (($proof['kind'] ?? null) === 'FIT') {
            if ($proof['destination'] !== $publicationRoot || $owner !== '../..') {
                throw new RuntimeException('Uncommitted or relocated fit root requires verified export.');
            }
            Files::verify(self::safe($publicationRoot, 'manifest.json'), $proof['files']['manifest.json'] ?? []);
            $manifest = Files::json($publicationRoot.'/manifest.json');
            if (($manifest['status'] ?? null) !== 'FINAL_COMPOSITION_FIT_REPRODUCED_AWAITING_REVIEW') {
                throw new RuntimeException('Fit publication root did not succeed.');
            }
        } elseif (($proof['kind'] ?? null) === 'REPACKAGE') {
            if ($owner !== '.' || ($proof['evidence'] ?? null) !== ($model['artifact']['provenance']['repackage'] ?? null)) {
                throw new RuntimeException('Invalid portable export proof.');
            }
            Repackage::verifyEvidence($proof['evidence']);
            Files::same($proof['evidence']['legacy_generation_code'], $model['artifact']['generation_code'], 'preserved legacy generation code');
            Files::same($proof['evidence']['parent_files'], $model['artifact']['files'], 'byte-exact repackage parents');
        } elseif (($proof['kind'] ?? null) !== 'SYNTHETIC_EXPORT' || ! app()->environment('testing')
            || ! str_starts_with($publicationRoot, sys_get_temp_dir().'/')) {
            throw new RuntimeException('Unsupported publication kind.');
        }

        return $model;
    }

    /** Internal content roundtrip only; no public command calls this entry point. */
    public function prepared(string $artifact): array
    {
        $root = realpath(dirname($artifact));
        if ($root === false || dirname($artifact) !== $root || basename($artifact) !== 'artifact.json') {
            throw new RuntimeException('Invalid package artifact path.');
        }
        self::safe($root, 'artifact.json');
        $seal = Files::identity($artifact);
        $data = Files::json($artifact);
        if (($data['publication_version'] ?? null) !== Contract::PUBLICATION_VERSION) {
            throw new RuntimeException('Package publication version unsupported; verified repackage required.');
        }
        Files::same(Contract::plan(), $data['contract'] ?? [], 'portable package contract');
        if (($data['role'] ?? null) !== 'FINAL_DEVELOPMENT_COMPOSITION_PACKAGE'
            || array_keys($data['files'] ?? []) !== ['c1/artifact.json', 'c1/model.json', 'c2/model.json', 'c2/layout.json', 'c2/selection.json']) {
            throw new RuntimeException('Invalid package role/file set.');
        }
        Files::same(['C1' => ['artifact' => 'c1/artifact.json', 'model' => 'c1/model.json', 'seal' => $data['files']['c1/model.json']],
            'C2' => ['model' => 'c2/model.json', 'layout' => 'c2/layout.json', 'selection' => 'c2/selection.json', 'seal' => $data['files']['c2/model.json']]], $data['parents'] ?? [], 'fixed parent roles and relative paths');
        foreach ($data['files'] as $path => $expected) {
            Files::verify(self::safe($root, $path), $expected);
        }
        Files::same(Contract::code(), $data['publication_code'] ?? $data['generation_code'], 'package runtime implementation');
        $c1 = $this->c1->published($root.'/c1/artifact.json');
        $c2 = $this->c2->load($root.'/c2/model.json', $data['files']['c2/model.json']);
        $selection = Files::json($root.'/c2/selection.json');
        Files::same($c2->artifact['layout'], Files::json($root.'/c2/layout.json'), 'portable C2 layout');
        if ($c1->fit->lambda !== 0.1 || $c2->fit->lambda !== ($selection['lambda'] ?? null)
            || ($data['provenance']['c1_model_sha256'] ?? null) !== $data['files']['c1/model.json']['sha256']
            || ($data['provenance']['reference_manifest_sha256'] ?? null) !== Contract::REFERENCE_SHA
            || ($data['provenance']['training_years'] ?? null) !== [2022, 2023, 2024, 2025]) {
            throw new RuntimeException('Package final parent/selection/provenance mismatch.');
        }
        if ((! app()->environment('testing') || ! str_starts_with($root, sys_get_temp_dir().'/'))
            && $data['files']['c1/model.json']['sha256'] !== Contract::C1_SHA) {
            throw new RuntimeException('Wrong fixed final C1.');
        }
        Files::verify($artifact, $seal);

        return ['c1' => $c1, 'c2' => $c2, 'artifact' => $data, 'seal' => $seal];
    }

    public static function safe(string $root, string $relative): string
    {
        if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '\\') || str_contains($relative, "\0")
            || array_intersect(explode('/', $relative), ['.', '..', '']) !== []) {
            throw new RuntimeException('Unsafe package relative path.');
        }
        $path = $root;
        foreach (explode('/', $relative) as $part) {
            $path .= '/'.$part;
            if (is_link($path)) {
                throw new RuntimeException('Package symlinks forbidden.');
            }
        }
        if (! is_file($path) || ! str_starts_with((string) realpath($path), $root.'/')) {
            throw new RuntimeException('Missing or escaped package file.');
        }

        return $path;
    }
}
