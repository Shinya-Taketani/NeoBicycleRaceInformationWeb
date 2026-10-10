<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\C1CompositionArchive;

use App\Domain\Keirin\Backtest\Calculators\Bt03e05MetricEvaluator;
use App\Domain\Keirin\Backtest\Experiments\C1CompositionRequest\PredictionVerifier;
use App\Domain\Keirin\Backtest\Experiments\C1CompositionRequest\Store;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Input;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Publication;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact as Jsonl;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionResult\Matcher;
use PDO;
use RuntimeException;
use Throwable;

final class Builder
{
    public function __construct(private readonly Sources $sources, private readonly Publication $publication,
        private readonly Matcher $matcher, private readonly PredictionVerifier $predictions,
        private readonly Bt03e05MetricEvaluator $evaluator, private readonly Metrics $metrics) {}

    public function build(string $output): array
    {
        Store::safe($output);
        $definitions = $this->sources->definitions();
        $output = $this->publication->destination($output, array_column($definitions, 'path'));
        $lock = $this->publication->acquire($output);
        $stage = '';
        try {
            $code = Contract::code();
            $start = $this->sources->start();
            $stage = $this->publication->stage($output);
            Files::directory($stage.'/pages');
            $db = new PDO('sqlite:'.$stage.'/work.sqlite');
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $db->exec('PRAGMA cache_size=-2048');
            $db->exec('CREATE TABLE races (id INTEGER PRIMARY KEY, input TEXT NOT NULL, prediction TEXT, ordinal INTEGER UNIQUE, result TEXT)');
            $db->exec('CREATE TABLE predicted_entries (id INTEGER PRIMARY KEY)');
            $db->beginTransaction();
            $insert = $db->prepare('INSERT INTO races (id,input) VALUES (?,?)');
            foreach (Input::read($definitions['input']['path']) as $input) {
                Input::validate($input, [2025]);
                $insert->execute([$input['race_id'], Files::canonical($input)]);
            }
            $get = $db->prepare('SELECT input,prediction FROM races WHERE id=?');
            $set = $db->prepare('UPDATE races SET prediction=?,ordinal=? WHERE id=?');
            $entryIds = $db->prepare('INSERT INTO predicted_entries (id) VALUES (?)');
            $count = 0;
            foreach (Jsonl::read($definitions['predictions']['path']) as $prediction) {
                $this->predictions->verify($prediction);
                $id = $prediction['probabilities']['race_id'];
                $get->execute([$id]);
                $row = $get->fetch(PDO::FETCH_ASSOC);
                if ($row === false || $row['prediction'] !== null) {
                    throw new RuntimeException('Extra or duplicate saved prediction.');
                }
                $input = json_decode($row['input'], true, 512, JSON_THROW_ON_ERROR);
                $this->matcher->prediction($input, $prediction);
                foreach ($prediction['probabilities']['entries'] as $entry) {
                    $entryIds->execute([$entry['id']]);
                }
                $set->execute([Files::canonical($prediction), ++$count, $id]);
            }
            if ($count !== $definitions['predictions']['seal']['rows'] || $count < 1
                || (int) $db->query('SELECT COUNT(*) FROM races WHERE prediction IS NULL')->fetchColumn() !== 0) {
                throw new RuntimeException('Incomplete annual input / prediction correspondence.');
            }
            // Outcome parsing is deliberately gated by the completed input/prediction validation above.
            $setResult = $db->prepare('UPDATE races SET result=? WHERE id=? AND result IS NULL');
            $resultCount = 0;
            foreach (Jsonl::read($definitions['labels']['path']) as $label) {
                $result = $this->matcher->result($label);
                if ($result['year'] !== 2025) {
                    throw new RuntimeException('Archive year must be 2025.');
                }
                $setResult->execute([Files::canonical($result), $result['race_id']]);
                if ($setResult->rowCount() !== 1) {
                    throw new RuntimeException('Extra or duplicate annual result.');
                }
                $resultCount++;
            }
            if ($resultCount !== $count || (int) $db->query('SELECT COUNT(*) FROM races WHERE result IS NULL')->fetchColumn() !== 0) {
                throw new RuntimeException('Incomplete annual results.');
            }
            $db->commit();
            $accumulator = $this->evaluator->emptySummary();
            $excluded = array_fill_keys(Bt03e05MetricEvaluator::METRIC_CODES, []);
            $files = [];
            $page = [];
            $pageNumber = 0;
            foreach ($db->query('SELECT * FROM races ORDER BY ordinal') as $row) {
                $fixed = ['request_id' => 'archive-2025-r'.$row['id'],
                    'input' => json_decode($row['input'], true, 512, JSON_THROW_ON_ERROR),
                    'prediction' => json_decode($row['prediction'], true, 512, JSON_THROW_ON_ERROR)];
                $result = json_decode($row['result'], true, 512, JSON_THROW_ON_ERROR);
                $joined = $this->matcher->join($fixed, $result);
                $contribution = $this->matcher->comparison($joined);
                $this->evaluator->add($accumulator, $contribution['comparison']);
                foreach ($contribution['unevaluable'] as $metric => $reason) {
                    $excluded[$metric][$reason] = ($excluded[$metric][$reason] ?? 0) + 1;
                }
                $page[] = ['ordinal' => (int) $row['ordinal'], 'input' => $fixed['input'], 'result' => $result,
                    'joined' => $joined, 'contribution' => $this->metrics->display($joined, $contribution)];
                if (count($page) === Contract::PAGE_SIZE) {
                    $this->page($stage, ++$pageNumber, $page, $files);
                    $page = [];
                }
            }
            if ($page !== []) {
                $this->page($stage, ++$pageNumber, $page, $files);
            }
            unset($page, $joined, $fixed, $row);
            $index = (function () use ($db): iterable {
                foreach ($db->query('SELECT id,ordinal FROM races ORDER BY ordinal') as $row) {
                    $ordinal = (int) $row['ordinal'];
                    yield ['race_id' => (int) $row['id'], 'page' => intdiv($ordinal - 1, Contract::PAGE_SIZE) + 1,
                        'offset' => ($ordinal - 1) % Contract::PAGE_SIZE, 'ordinal' => $ordinal];
                }
            })();
            $indexSeal = $this->publication->rows($stage.'/race-index.jsonl', $index);
            Files::same($indexSeal, Files::json($stage.'/race-index.jsonl.manifest.json'), 'index write seal');
            $files['race-index.jsonl'] = ['bytes' => $indexSeal['bytes'], 'sha256' => $indexSeal['sha256']];
            $this->record($stage, 'race-index.jsonl.manifest.json', $files);
            $summary = $this->metrics->summary($accumulator, $excluded);
            $files['summary.json'] = $this->json($stage.'/summary.json', $summary);
            $this->validateSaved($stage, $pageNumber, $summary, $db);
            $get = $set = $insert = $entryIds = $setResult = $db = null;
            $this->record($stage, 'work.sqlite', $files);
            $manifest = ['version' => Contract::VERSION, 'status' => 'COMPLETE_SAVED_PREDICTION_ARCHIVE', 'year' => 2025,
                'count' => $count, 'page_count' => $pageNumber, 'page_size' => Contract::PAGE_SIZE,
                'order' => Contract::plan()['order'], 'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'sources' => $start, 'code' => $code, 'use' => Contract::plan(), 'files' => $files];
            $pin = $this->json($stage.'/manifest.json', $manifest);
            $this->json($stage.'/COMPLETE.json', $pin);
            foreach ($files as $name => $seal) {
                Files::verify($stage.'/'.$name, $seal);
            }
            Files::verify($stage.'/manifest.json', $pin);
            Files::same($pin, Files::json($stage.'/COMPLETE.json'), 'archive completion');
            $this->sources->end($start);
            Files::same($code, Contract::code(), 'archive generating code END');
            $this->publication->commit($stage, $output);

            return ['status' => 'CREATED', 'path' => $output, 'manifest' => $pin, 'matched' => $count,
                'pages' => $pageNumber, 'summary' => $summary, 'peak_memory_bytes' => memory_get_peak_usage(true)];
        } catch (Throwable $error) {
            if ($stage !== '') {
                $this->publication->failed($stage, $error);
            }
            throw $error;
        } finally {
            fclose($lock);
        }
    }

    private function page(string $stage, int $number, array $page, array &$files): void
    {
        $name = 'pages/'.sprintf('%06d', $number).'.jsonl';
        $seal = $this->publication->rows($stage.'/'.$name, $page);
        Files::verify($stage.'/'.$name, $seal);
        Files::same($seal, Files::json($stage.'/'.$name.'.manifest.json'), 'page write seal');
        $files[$name] = ['bytes' => $seal['bytes'], 'sha256' => $seal['sha256']];
        $this->record($stage, $name.'.manifest.json', $files);
    }

    private function record(string $stage, string $name, array &$files): void
    {
        $files[$name] = Files::identity($stage.'/'.$name);
    }

    private function json(string $path, array $value): array
    {
        $text = json_encode($value, JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        $expected = ['bytes' => strlen($text), 'sha256' => hash('sha256', $text)];
        $this->publication->json($path, $value);
        Files::verify($path, $expected);

        return $expected;
    }

    private function validateSaved(string $stage, int $pages, array $summary, PDO $db): void
    {
        $accumulator = $this->evaluator->emptySummary();
        $excluded = array_fill_keys(Bt03e05MetricEvaluator::METRIC_CODES, []);
        $get = $db->prepare('SELECT * FROM races WHERE ordinal=?');
        $ordinal = 0;
        for ($page = 1; $page <= $pages; $page++) {
            $rows = 0;
            foreach (Jsonl::read($stage.'/pages/'.sprintf('%06d', $page).'.jsonl') as $row) {
                $get->execute([++$ordinal]);
                $original = $get->fetch(PDO::FETCH_ASSOC);
                if ($original === false || $row['ordinal'] !== $ordinal || ++$rows > Contract::PAGE_SIZE) {
                    throw new RuntimeException('Generated page count / order mismatch.');
                }
                foreach (['input', 'result'] as $key) {
                    Files::same(json_decode($original[$key], true, 512, JSON_THROW_ON_ERROR), $row[$key], 'saved '.$key);
                }
                $fixed = ['request_id' => 'archive-2025-r'.$original['id'], 'input' => $row['input'],
                    'prediction' => json_decode($original['prediction'], true, 512, JSON_THROW_ON_ERROR)];
                $joined = $this->matcher->join($fixed, $row['result']);
                $contribution = $this->matcher->comparison($joined);
                Files::same($joined, $row['joined'], 'generated saved prediction');
                Files::same($this->metrics->display($joined, $contribution), $row['contribution'], 'generated contribution');
                $this->evaluator->add($accumulator, $contribution['comparison']);
                foreach ($contribution['unevaluable'] as $metric => $reason) {
                    $excluded[$metric][$reason] = ($excluded[$metric][$reason] ?? 0) + 1;
                }
            }
            if ($rows !== min(Contract::PAGE_SIZE, $summary['matched'] - ($page - 1) * Contract::PAGE_SIZE)) {
                throw new RuntimeException('Generated page boundary mismatch.');
            }
        }
        Files::same($summary, $this->metrics->summary($accumulator, $excluded), 'generated summary aggregation');
        $count = 0;
        foreach (Jsonl::read($stage.'/race-index.jsonl') as $row) {
            $get->execute([++$count]);
            $original = $get->fetch(PDO::FETCH_ASSOC);
            Files::same(['race_id' => (int) $original['id'], 'page' => intdiv($count - 1, Contract::PAGE_SIZE) + 1,
                'offset' => ($count - 1) % Contract::PAGE_SIZE, 'ordinal' => $count], $row, 'generated race index');
        }
        if ($ordinal !== $summary['matched'] || $count !== $ordinal) {
            throw new RuntimeException('Generated archive completeness mismatch.');
        }
    }
}
