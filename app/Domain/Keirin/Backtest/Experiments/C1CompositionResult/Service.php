<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\C1CompositionResult;

use App\Domain\Keirin\Backtest\Experiments\C1CompositionRequest\Contract as Request;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Publication;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use Throwable;

final class Service
{
    public function __construct(private readonly Sources $sources, private readonly Store $store,
        private readonly Calculation $calculation, private readonly Publication $publication) {}

    public function execute(string $root, string $id, string $requestRoot, string $selection): array
    {
        Request::id($id);
        $root = $this->store->root($root, [$requestRoot, $selection, dirname($this->sources->labels)]);
        $source = $this->sources->capture($requestRoot, $selection);
        $code = Contract::code();
        $sourceRecord = array_diff_key($source, ['fixed' => true]);
        $identity = ['evaluation_id' => $id, 'contract' => Contract::plan(), 'selection' => $source['selection'],
            'source_sha256' => hash('sha256', Files::canonical($sourceRecord)), 'code_sha256' => hash('sha256', Files::canonical($code))];
        $this->store->prepare($root);
        // Never publish the guard destination; acquire() can then serialize both CREATED and REUSED.
        $guard = $this->publication->destination($root.'/.guards/'.$id);
        $lock = $this->publication->acquire($guard);
        $stage = '';
        $expected = [];
        $completion = null;
        $destination = $root.'/evaluations/'.$id;
        try {
            if (file_exists($destination) || is_link($destination)) {
                $saved = $this->store->verify($destination, $id);
                Files::same($identity, $saved['manifest']['request'], 'CONFLICT evaluation_id');
                $this->sources->end($source);

                return $this->view('REUSED', $saved);
            }
            $destination = $this->publication->destination($destination, [$requestRoot, dirname($this->sources->labels)]);
            $stage = $this->publication->stage($destination);
            $this->store->json($stage, 'request.json', $identity, $expected);
            $this->store->json($stage, 'sources.json', $sourceRecord, $expected);
            $this->store->json($stage, 'code.json', $code, $expected);
            $this->store->copyRequests($stage, $source, $expected);
            $this->store->rows($stage, 'fixed.jsonl', $source['fixed'], $expected);
            $this->store->json($stage, 'freeze.json', ['order' => ['SELECTION_VERIFIED', 'REQUESTS_VERIFIED',
                'FIXED_PREDICTIONS_SEALED', 'RESULTS_MAY_NOW_BE_PARSED'], 'fixed' => $expected['fixed.jsonl'],
                'request_count' => count($source['fixed']), 'not_a_historical_prestart_claim' => true], $expected);
            $this->store->verifyExpected($stage, $expected);
            $this->store->rows($stage, 'results.jsonl', $this->sources->results($source), $expected);
            $calculated = $this->calculation->compute($stage.'/fixed.jsonl', $stage.'/results.jsonl');
            $this->writeCalculation($stage, $calculated, $expected);
            $this->sources->end($source);
            Files::same($code, Contract::code(), 'result execution code END');
            $this->store->json($stage, 'source-end.json', ['status' => 'UNCHANGED', 'seals' => $source['seals'], 'code' => $code], $expected);
            $files = [];
            foreach (Store::names($source['selection']['targets']) as $name) {
                $files[$name] = $expected[$name];
            }
            $this->store->verifyExpected($stage, $expected);
            $manifest = ['status' => 'COMPLETE_COMPOSITION_RESULT', 'request' => $identity,
                'generated_at' => gmdate(DATE_ATOM), 'files' => $files, 'generation_verification' => 'EXPECTED_WRITE_SEALS_AND_SEMANTIC_CHECK'];
            $this->store->json($stage, 'manifest.json', $manifest, $expected);
            $this->store->json($stage, 'COMPLETE.json', $expected['manifest.json'], $expected);
            $completion = $expected['COMPLETE.json'];
            $this->store->verifyExpected($stage, $expected);
            $saved = $this->store->verify($stage, $id);
            $this->sources->end($source);
            Files::same($code, Contract::code(), 'prepublication code END');
            $this->publication->commit($stage, $destination);
            $saved['path'] = $destination;

            return $this->view('CREATED', $saved);
        } catch (Throwable $error) {
            if ($this->publication->wasCommitted($stage, $destination, $completion)) {
                return $this->view('CREATED', $this->store->verify($destination, $id)) + ['postcommit_warning' => $error->getMessage()];
            }
            $this->publication->failed($stage, $error);
            throw $error;
        } finally {
            fclose($lock);
        }
    }

    public function show(string $root, string $id): array
    {
        Request::id($id);
        $root = $this->store->root($root);
        $path = $root.'/evaluations/'.$id;

        return ! file_exists($path) && ! is_link($path)
            ? ['status' => 'NOT_FOUND', 'evaluation_id' => $id] : $this->view('SAVED', $this->store->verify($path, $id));
    }

    public function reproduce(string $root, string $id): array
    {
        Request::id($id);
        $root = $this->store->root($root);
        $saved = $this->store->verify($root.'/evaluations/'.$id, $id);
        Files::same(Files::json($saved['path'].'/code.json'), Contract::code(), 'reproduction code START');
        $this->store->prepare($root);
        $lock = $this->publication->acquire($this->publication->destination($root.'/.guards/'.$id));
        $stage = '';
        $expected = [];
        try {
            $stage = $this->publication->stage($root.'/reproductions/'.$id);
            $calculated = $this->calculation->compute($saved['path'].'/fixed.jsonl', $saved['path'].'/results.jsonl');
            $this->writeCalculation($stage, $calculated, $expected);
            $this->store->verifyExpected($stage, $expected);
            $this->store->verifyMeaning($stage, $calculated, $saved['manifest']['files']);
            Files::same($saved, $this->store->verify($saved['path'], $id), 'saved evaluation END unchanged');
            Files::same(Files::json($saved['path'].'/code.json'), Contract::code(), 'reproduction code END');
            $this->store->json($stage, 'REPRODUCED.json', ['status' => 'REPRODUCED', 'evaluation_id' => $id,
                'semantic_files' => $expected, 'original_manifest' => Files::identity($saved['path'].'/manifest.json'),
                'original_sources_required' => false], $expected);

            return $this->view('REPRODUCED', $saved) + ['reproduction_path' => $stage, 'identical' => true, 'original_sources_required' => false];
        } catch (Throwable $error) {
            $this->publication->failed($stage, $error);
            throw $error;
        } finally {
            fclose($lock);
        }
    }

    private function writeCalculation(string $stage, array $calculated, array &$expected): void
    {
        foreach ($calculated as $name => $value) {
            if (str_ends_with($name, '.jsonl')) {
                $this->store->rows($stage, $name, $value, $expected);
            } else {
                $this->store->json($stage, $name, $value, $expected);
            }
        }
    }

    private function view(string $status, array $saved): array
    {
        return ['status' => $status, 'evaluation_id' => $saved['manifest']['request']['evaluation_id'],
            'path' => $saved['path'], 'manifest' => Files::identity($saved['path'].'/manifest.json'),
            'summary' => $saved['summary'], 'races' => $saved['races']];
    }
}
