<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Contract;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Forward;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Input;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\LegacySource;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Package;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Publication;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Repackage;
use App\Domain\Keirin\Backtest\Experiments\Stat35C1Comparison\ModelLoader as C2Loader;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact as Jsonl;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\ModelLoader as C1Loader;

final class C1Stat35RepackageFixture
{
    public static function make(string $root, array $models): LegacySource
    {
        $source = Files::directory($root.'/legacy');
        $code = Contract::code();
        foreach (['run-01', 'run-02'] as $run) {
            Files::directory($source.'/'.$run);
            $artifact = app(Package::class)->stage($models['c1_artifact'], dirname($models['outer_c2'][2024]),
                ['lambda' => 1.0], $source.'/'.$run.'/package', C1Stat35CompositionFinalFixture::provenance($models['c1_artifact']));
            $data = Files::json($artifact);
            unset($data['publication_version'], $data['publication_owner']);
            unlink($artifact);
            Jsonl::json($artifact, $data);
            Jsonl::json(dirname($artifact).'/COMPLETE.json', ['artifact' => Files::identity($artifact), 'status' => 'FINAL_COMPOSITION_FIT_REPRODUCED_AWAITING_REVIEW']);
            Files::directory($source.'/'.$run.'/final');
            foreach (['model.json', 'layout.json'] as $name) {
                copy($source.'/'.$run.'/package/c2/'.$name, $source.'/'.$run.'/final/'.$name);
            }
            copy($source.'/'.$run.'/package/c2/selection.json', $source.'/'.$run.'/selection.json');
        }
        Files::directory($source.'/verified-inputs');
        $input = $source.'/verified-inputs/features-2025.jsonl';
        Input::write($input, [C1Stat35CompositionFinalFixture::feature(100), C1Stat35CompositionFinalFixture::feature(2)]);
        $rows = app(Forward::class);
        $c1 = app(C1Loader::class)->published($models['c1_artifact']);
        $c2 = app(C2Loader::class)->load($models['outer_c2'][2024], Files::identity($models['outer_c2'][2024]));
        $prediction = Jsonl::write($root.'/public-predictions-2025.jsonl', (function () use ($rows, $c1, $c2): \Generator {
            foreach ([100, 2] as $id) {
                yield $rows->predict(C1Stat35CompositionFinalFixture::feature($id), $c1, $c2);
            }
        })());
        Jsonl::json($root.'/public-predictions-2025.jsonl.COMPLETE.json', ['predictions' => $prediction]);
        Files::directory($root.'/log-execute');
        Jsonl::json($root.'/log-execute/execution.json', ['mode' => 'execute', 'commands' => [['exit_code' => 0, 'command' => ['synthetic-fit-record', '--output-dir='.$source]]]]);
        Jsonl::json($source.'/frozen.json', ['code' => $code]);
        Jsonl::json($source.'/reproduction.json', ['identical' => true, 'semantic_file_count' => 37,
            'files' => ['composition-before-package.jsonl' => ['bytes' => $prediction['bytes'], 'sha256' => $prediction['sha256']]]]);
        Jsonl::json($source.'/result.json', ['status' => 'FINAL_COMPOSITION_FIT_REPRODUCED_AWAITING_REVIEW', 'semantic_file_count' => 37,
            'new_fit_paths' => 4, 'candidate_attempts' => 8, 'c1_retraining_count' => 0]);
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $files[substr($file->getPathname(), strlen($source) + 1)] = Files::identity($file->getPathname());
        }
        Jsonl::json($source.'/manifest.json', ['status' => 'FINAL_COMPOSITION_FIT_REPRODUCED_AWAITING_REVIEW', 'contract' => Contract::plan(), 'code' => $code, 'files' => $files]);
        $pin = Files::identity($source.'/manifest.json');
        Jsonl::json($source.'/COMPLETE.json', $pin);
        $parents = Files::json($source.'/run-01/package/artifact.json')['files'];

        return new LegacySource($source, $pin, $parents['c1/model.json']['sha256'], $parents['c2/model.json']['sha256']);
    }

    public static function bind(LegacySource $pin): Package
    {
        app()->instance(LegacySource::class, $pin);
        $package = new Package(app(C1Loader::class), app(C2Loader::class), $pin);
        app()->instance(Package::class, $package);

        return $package;
    }

    public static function repackage(LegacySource $pin, Publication $publication): Repackage
    {
        return new class(self::bind($pin), $publication, $pin) extends Repackage
        {
            protected function verifyCode(array $code): void
            {
                foreach ($code as $path => $seal) {
                    Files::verify(base_path($path), $seal);
                }
            }
        };
    }
}
