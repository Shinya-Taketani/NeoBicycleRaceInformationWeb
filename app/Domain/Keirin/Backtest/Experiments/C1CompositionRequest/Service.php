<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\C1CompositionRequest;

use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Contract as Model;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Package;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Publication;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use RuntimeException;
use Throwable;

final class Service
{
    public function __construct(private readonly Sources $sources, private readonly Store $store, private readonly Publication $publication) {}

    public function create(string $root, string $id, string $year, string $raceId): array
    {
        Contract::id($id);
        $year = Contract::number($year);
        $raceId = Contract::number($raceId);
        $this->sources->year($year);
        $root = $this->store->root($root, $this->sources);
        $identity = ['request_version' => Contract::VERSION, 'request_id' => $id, 'mode' => Contract::MODE,
            'year' => $year, 'race_id' => $raceId, 'sources' => $this->sources->reference(),
            'input_version' => Model::INPUT_VERSION, 'probability' => Model::plan()['probability'],
            'decoder' => Model::plan()['decoder'], 'code' => Contract::code()];
        $this->store->prepare($root);
        // A never-published guard destination lets the existing lock API also serialize REUSED calls.
        $guard = $this->publication->destination($root.'/.guards/'.$id);
        $lock = $this->publication->acquire($guard);
        $stage = '';
        $completion = null;
        $destination = $root.'/requests/'.$id;
        try {
            $saved = $this->store->read($root, $id);
            if ($saved !== null) {
                Files::same($identity, $saved['manifest']['request'], 'CONFLICT request_id');

                return $this->view('REUSED', $saved);
            }
            $destination = $this->publication->destination($destination);
            $packages = app(Package::class);
            $model = $this->sources->load($packages);
            $modelCode = Model::code();
            $stage = $this->publication->stage($destination);
            $inputs = $this->sources->capture();
            $race = $this->sources->extract($year, $raceId);
            $prediction = app(Predictor::class)->predict($race, $model);
            $expected = [];
            $json = function (string $name, array $data) use ($stage, &$expected): void {
                $bytes = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES)."\n";
                $expected[$name] = self::seal($bytes);
                $this->publication->json($stage.'/'.$name, $data);
            };
            $rows = function (string $name, array $row) use ($stage, &$expected): array {
                $bytes = json_encode($row, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES)."\n";
                $seal = ['rows' => 1, ...self::seal($bytes)];
                $expected[$name] = self::seal($bytes);
                $this->publication->rows($stage.'/'.$name, [$row]);
                $expected[$name.'.manifest.json'] = self::seal(json_encode($seal,
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES)."\n");

                return $seal;
            };
            $json('request.json', $identity);
            $input = $rows('input.jsonl', $race);
            $json('input.jsonl.input.json', ['version' => Model::INPUT_VERSION, 'purpose' => 'FEATURE_ONLY_TECHNICAL_PREDICTION', 'data' => $input]);
            $rows('prediction.jsonl', $prediction);
            $json('model-reference.json', ['sources' => $this->sources->reference(), 'parents' => $model['artifact']['parents'],
                'models' => $model['artifact']['files'], 'publication_version' => $model['artifact']['publication_version']]);
            $json('runtime.json', ['request_code' => $identity['code'], 'model_code' => $modelCode, 'php_version' => PHP_VERSION]);
            $files = [];
            foreach (Contract::FILES as $name) {
                Files::verify($stage.'/'.$name, $expected[$name]);
                $files[$name] = $expected[$name];
            }
            $manifest = ['status' => 'COMPLETE_DEVELOPMENT_REQUEST', 'request' => $identity,
                'generated_at' => gmdate(DATE_ATOM), 'input_as_of' => null, 'observed_at' => null,
                'use' => Contract::plan(), 'files' => $files,
                'entrants' => array_map(static fn (array $e): array => [$e['id'], $e['bike']], $race['entries'])];
            $json('manifest.json', $manifest);
            $json('COMPLETE.json', $expected['manifest.json']);
            $completion = $expected['COMPLETE.json'];
            $this->sources->end($inputs);
            Files::same($model['seal'], $this->sources->load($packages)['seal'], 'package end');
            Files::same($modelCode, Model::code(), 'model code end');
            Files::same($identity['code'], Contract::code(), 'request code end');
            foreach ($expected as $name => $seal) {
                Files::verify($stage.'/'.$name, $seal);
            }
            $saved = $this->store->verify($stage, $id);
            $this->publication->commit($stage, $destination);
            $saved['path'] = $destination;

            return $this->view('CREATED', $saved);
        } catch (Throwable $e) {
            if ($this->publication->wasCommitted($stage, $destination, $completion)) {
                return $this->view('CREATED', $this->store->verify($destination, $id)) + ['postcommit_warning' => $e->getMessage()];
            }
            $this->publication->failed($stage, $e);
            throw $e;
        } finally {
            fclose($lock);
        }
    }

    public function show(string $root, string $id): array
    {
        Contract::id($id);
        $root = $this->store->root($root, $this->sources);
        $saved = $this->store->read($root, $id);

        return $saved === null ? ['status' => 'NOT_FOUND', 'request_id' => $id] : $this->view('SAVED', $saved);
    }

    public function reproduce(string $root, string $id, ?string $artifact = null): array
    {
        Contract::id($id);
        $root = $this->store->root($root, $this->sources);
        $saved = $this->store->read($root, $id) ?? throw new RuntimeException('Request NOT_FOUND.');
        Files::same($saved['manifest']['request']['sources'], $this->sources->reference(), 'reproduction source identity');
        Files::same($saved['manifest']['request']['code'], Contract::code(), 'reproduction runtime code');
        $model = $this->sources->load(app(Package::class), $artifact);
        $modelCode = Model::code();
        Files::same($saved['prediction'], app(Predictor::class)->predict($saved['input'], $model), 'strict prediction reproduction');
        Files::same($model['seal'], $this->sources->load(app(Package::class), $artifact)['seal'], 'reproduction package end');
        Files::same($modelCode, Model::code(), 'reproduction model code end');
        Files::same($saved['manifest']['request']['code'], Contract::code(), 'reproduction request code end');
        Files::same($saved, $this->store->read($root, $id), 'original request unchanged');

        return ['status' => 'REPRODUCED', 'request_id' => $id, 'identical' => true,
            'prediction' => $saved['manifest']['files']['prediction.jsonl'], 'model' => $model['seal'], 'annual_input_read' => false];
    }

    private static function seal(string $bytes): array
    {
        return ['bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
    }

    private function view(string $status, array $saved): array
    {
        $manifest = $saved['manifest'];
        $decision = $saved['prediction']['decision'];

        return ['status' => $status, 'path' => $saved['path'], 'request' => $manifest['request'],
            'generated_at' => $manifest['generated_at'], 'input_as_of' => $manifest['input_as_of'], 'use' => $manifest['use'],
            'primary' => array_map(static fn (int $p): int => $decision['primary_position_'.$p.'_bike'], [1, 2, 3]),
            'marginals' => array_map(static fn (array $e): array => ['id' => $e['id'], 'bike' => $e['bike'],
                'p1' => $e['position_1_probability'], 'p2' => $e['position_2_probability'], 'p3' => $e['position_3_probability']], $saved['prediction']['probabilities']['entries']),
            'decision' => $decision, 'manifest' => Files::identity($saved['path'].'/manifest.json')];
    }
}
