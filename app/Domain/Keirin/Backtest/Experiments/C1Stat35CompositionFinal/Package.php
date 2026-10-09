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

    public function stage(string $c1Artifact, string $c2Directory, array $selection, string $output, array $provenance): string
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
            'files' => $files, 'generation_code' => Contract::code(), 'provenance' => $provenance]);
        $this->load($output.'/artifact.json', false);

        return $output.'/artifact.json';
    }

    public function load(string $artifact, bool $requirePublished = true): array
    {
        $root = realpath(dirname($artifact));
        if ($root === false || dirname($artifact) !== $root || basename($artifact) !== 'artifact.json') {
            throw new RuntimeException('Invalid package artifact path.');
        }
        self::safe($root, 'artifact.json');
        $seal = Files::identity($artifact);
        $data = Files::json($artifact);
        if ($requirePublished) {
            Files::same(['artifact' => $seal, 'status' => 'FINAL_COMPOSITION_FIT_REPRODUCED_AWAITING_REVIEW'], Files::json(self::safe($root, 'COMPLETE.json')), 'composition publication');
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
        Files::same(Contract::code(), $data['generation_code'], 'package runtime implementation');
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

    public function publish(string $artifact): void
    {
        $this->load($artifact, false);
        Jsonl::json(dirname($artifact).'/COMPLETE.json', ['artifact' => Files::identity($artifact), 'status' => 'FINAL_COMPOSITION_FIT_REPRODUCED_AWAITING_REVIEW']);
        $this->load($artifact);
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
