<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\TacticalPredictionPipeline;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\PredictionService;
use Illuminate\Database\QueryException;
use RuntimeException;
use Throwable;

class Pipeline
{
    public function __construct(private readonly RaceInputSource $source, private readonly PredictionService $predictor,
        private readonly ArtifactStore $store, private readonly ModelIdentity $modelIdentity) {}

    public function execute(Request $request): array
    {
        $root = $this->store->root($request->outputRoot);
        $sourceDirectory = realpath(dirname($request->artifact));
        if ($sourceDirectory !== false && ($root === $sourceDirectory || str_starts_with($root, $sourceDirectory.'/'))) {
            throw new RuntimeException('Output root cannot be the model source directory.');
        }
        $this->store->prepareRoot($root);
        try {
            return $this->store->locked($root, $request->requestId, function () use ($root, $request): array {
                $seals = ['artifact.json' => Files::identity($request->artifact), 'model.json' => Files::identity(dirname($request->artifact).'/model.json')];
                $identity = $request->identity($seals['artifact.json'], $seals['model.json']);
                $destination = $root.'/requests/'.$request->requestId;
                if (file_exists($destination) || is_link($destination)) {
                    $manifest = $this->store->verify($destination);
                    if (Files::canonical(Request::normalizeIdentity($manifest['request'])) !== Files::canonical(Request::normalizeIdentity($identity))) {
                        throw new RuntimeException('CONFLICT: request_id is bound to another request.');
                    }
                    $this->store->event($root, $request->requestId, ['status' => 'REUSED', 'input_generated' => false]);

                    return ['status' => 'REUSED', 'path' => $destination, 'manifest' => $manifest];
                }
                $this->modelIdentity->validate($request->artifact);
                $stage = Files::directory($root.'/.staging/'.$request->requestId.'-'.bin2hex(random_bytes(12)));
                try {
                    $generatedAt = gmdate(DATE_ATOM);
                    $code = $this->codeIdentity();
                    JsonlArtifact::json($stage.'/request.json', $identity);
                    JsonlArtifact::json($stage.'/code.json', $code);
                    foreach ($seals as $name => $seal) {
                        $source = $name === 'artifact.json' ? $request->artifact : dirname($request->artifact).'/model.json';
                        if (! copy($source, $stage.'/'.$name)) {
                            throw new RuntimeException('Model staging copy failed.');
                        }
                        Files::verify($stage.'/'.$name, $seal);
                    }
                    $start = $this->source->capture($request);
                    JsonlArtifact::write($stage.'/input.jsonl', [$start['input']]);
                    JsonlArtifact::json($stage.'/audit.json', $start['audit'] + ['read_only' => $start['read_only']]);
                    $prediction = $this->predictor->run($stage.'/artifact.json', $stage.'/input.jsonl', $stage.'/prediction.jsonl');
                    $sealed = [];
                    foreach (['request.json', 'model.json', 'artifact.json', 'input.jsonl', 'input.jsonl.manifest.json',
                        'prediction.jsonl', 'prediction.jsonl.manifest.json', 'audit.json', 'code.json'] as $name) {
                        $sealed[$name] = Files::identity($stage.'/'.$name);
                    }
                    $end = $this->source->capture($request);
                    Files::same($start, $end, 'target metadata / fixed STAT / history end verification');
                    JsonlArtifact::json($stage.'/source-end.json', ['status' => 'VERIFIED_TARGET_SCOPE', 'read_only' => $end['read_only'],
                        'input_sha256' => hash('sha256', Files::canonical($end['input'])), 'audit_sha256' => hash('sha256', Files::canonical($end['audit']))]);
                    Files::verify($request->artifact, $seals['artifact.json']);
                    Files::verify(dirname($request->artifact).'/model.json', $seals['model.json']);
                    Files::same($code, $this->codeIdentity(), 'pipeline execution code');
                    $files = [];
                    foreach (['request.json', 'model.json', 'artifact.json', 'input.jsonl', 'input.jsonl.manifest.json',
                        'prediction.jsonl', 'prediction.jsonl.manifest.json', 'audit.json', 'source-end.json', 'code.json'] as $name) {
                        $files[$name] = Files::identity($stage.'/'.$name);
                        if (isset($sealed[$name])) {
                            Files::verify($stage.'/'.$name, $sealed[$name]);
                        }
                    }
                    Files::verify($stage.'/input.jsonl', Files::json($stage.'/input.jsonl.manifest.json'));
                    Files::verify($stage.'/prediction.jsonl', $prediction);
                    Files::verify($stage.'/model.json', $seals['model.json']);
                    $manifest = ['status' => 'DEVELOPMENT_REPLAY_LOCKED', 'contract' => Contract::plan(), 'request' => $identity,
                        'generated_at' => $generatedAt, 'locked_at' => gmdate(DATE_ATOM), 'files' => $files,
                        'entrants' => array_map(fn ($e) => ['race_entry_id' => $e['id'], 'bike' => $e['bike']], $start['input']['entries']),
                        'prediction_manifest' => $prediction];
                    JsonlArtifact::json($stage.'/manifest.json', $manifest);
                    JsonlArtifact::json($stage.'/LOCKED.json', Files::identity($stage.'/manifest.json'));
                    $this->store->publish($stage, $destination);

                    return ['status' => 'DEVELOPMENT_REPLAY_LOCKED', 'path' => $destination, 'manifest' => $manifest];
                } catch (Throwable $e) {
                    // Preserve only this attempt's diagnostics. Never clean another staging directory.
                    JsonlArtifact::json($stage.'/failure.json', ['status' => 'FAILED_NOT_LOCKED', 'failed_at' => gmdate(DATE_ATOM), 'reason' => $this->reason($e)]);
                    throw $e;
                }
            });
        } catch (Throwable $e) {
            $reason = $this->reason($e);
            $this->store->event($root, $request->requestId, ['status' => 'FAILED', 'reason' => $reason]);
            throw new RuntimeException($reason);
        }
    }

    public function reproduce(string $root, string $requestId): array
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,95}\z/', $requestId) !== 1) {
            throw new RuntimeException('Invalid request identity.');
        }
        $root = $this->store->root($root);
        $this->store->prepareRoot($root);

        return $this->store->locked($root, $requestId, function () use ($root, $requestId): array {
            $path = $root.'/requests/'.$requestId;
            $manifest = $this->store->verify($path);
            $this->modelIdentity->validate($path.'/artifact.json');
            $stage = Files::directory($root.'/.staging/'.$requestId.'-replay-'.bin2hex(random_bytes(12)));
            $actual = $this->predictor->run($path.'/artifact.json', $path.'/input.jsonl', $stage.'/prediction.jsonl');
            Files::same($manifest['prediction_manifest'], $actual, 'fixed-input prediction reproduction');
            Files::same($manifest, $this->store->verify($path), 'locked request after reproduction');
            $this->store->event($root, $requestId, ['status' => 'REPRODUCED', 'database_access' => 'NONE', 'manifest' => $actual,
                'reproduction_stage' => basename($stage)]);
            $this->removeSuccessfulReproduction($stage, $actual);

            return ['status' => 'REPRODUCED', 'database_access' => 'NONE', 'manifest' => $actual];
        });
    }

    private function removeSuccessfulReproduction(string $stage, array $manifest): void
    {
        // Only this call's two verified outputs may be removed, never a recursive staging sweep.
        $names = ['prediction.jsonl', 'prediction.jsonl.manifest.json'];
        if (is_link($stage) || scandir($stage) !== ['.', '..', ...$names]) {
            throw new RuntimeException('Unexpected reproduction stage contents; preserving evidence.');
        }
        foreach ($names as $name) {
            Files::identity($stage.'/'.$name);
        }
        Files::verify($stage.'/prediction.jsonl', $manifest);
        Files::same($manifest, Files::json($stage.'/prediction.jsonl.manifest.json'), 'reproduction stage manifest');
        foreach ($names as $name) {
            if (! unlink($stage.'/'.$name)) {
                throw new RuntimeException('Cannot remove successful reproduction output.');
            }
        }
        if (! rmdir($stage)) {
            throw new RuntimeException('Cannot remove successful reproduction stage.');
        }
    }

    private function codeIdentity(): array
    {
        $files = [];
        foreach (['app', 'config', 'bootstrap'] as $directory) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($directory), \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && $file->getExtension() === 'php' && ! str_contains($file->getPathname(), '/cache/')) {
                    $files[substr($file->getPathname(), strlen(base_path()) + 1)] = Files::identity($file->getPathname());
                }
            }
        }
        $files['composer.lock'] = Files::identity(base_path('composer.lock'));
        ksort($files, SORT_STRING);

        return ['php_version' => PHP_VERSION, 'pipeline_version' => Contract::VERSION, 'files' => $files];
    }

    private function reason(Throwable $e): string
    {
        return $e instanceof \PDOException || $e instanceof QueryException ? 'READ_ONLY_DATABASE_ERROR SQLSTATE '.$e->getCode() : $e->getMessage();
    }
}
