<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\AgariPlayerHistory;

use App\Console\Commands\Keirin\BuildAgariPlayerHistoryCommand;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Statistics\AgariRaceRelative\Artifacts;
use App\Domain\Keirin\Statistics\AgariRaceRelative\Contract as Relative;
use App\Domain\Keirin\TrackContext\StructureValues;
use Brick\Math\BigRational;
use Generator;
use PDO;
use RuntimeException;

final class Builder
{
    public function __construct(private readonly Source $source) {}

    public function build(string $input, string $result, string $output): array
    {
        $source = $this->source->verify($input, $result);
        $code = self::code();
        Artifacts::create($output);
        $workspace = new Workspace($output.'/workspace.sqlite');
        $workspace->ingest($this->source->rows($input, $result, $source), $source);
        (new Meetings)->build($workspace->db);
        (new History)->build($workspace->db, $source['input']['from']);
        $summary = new Summary;
        $reasons = ['context' => [], 'entry_identity' => [], 'meeting_exclusions' => [], 'trend_null' => []];
        $provenance = ['version' => Contract::VERSION, 'source_input_manifest' => $source['input_identity'], 'source_result_manifest' => $source['result_identity']];
        $files['player-meetings.jsonl'] = Artifacts::write($output, 'player-meetings.jsonl', $this->meetings($workspace->db, $provenance, $summary, $reasons));
        $files['entry-history.jsonl'] = Artifacts::write($output, 'entry-history.jsonl', $this->entries($workspace->db, $provenance, $summary, $reasons, $source['input']['from']));
        $inventory = [];
        foreach (['identified_players' => 'SELECT COUNT(DISTINCT external) FROM groups WHERE external IS NOT NULL',
            'player_meetings' => 'SELECT COUNT(*) FROM groups',
            'identified_player_meetings' => 'SELECT COUNT(*) FROM groups WHERE external IS NOT NULL',
            'context_eligible_player_meetings' => 'SELECT COUNT(*) FROM groups WHERE eligible=1',
            'observed_meeting_ids' => "SELECT COUNT(*) FROM meetings WHERE id NOT LIKE 'unknown:%'"] as $key => $sql) {
            $inventory[$key] = (int) $workspace->db->query($sql)->fetchColumn();
        }
        $data = $summary->data($inventory, $reasons);
        if ($data['totals']['races'] !== $source['input']['race_count'] || $data['totals']['result_rows'] !== $source['input']['result_count']) {
            throw new RuntimeException('Output inventory differs from sealed input.');
        }
        $files['summary.json'] = Artifacts::json($output, 'summary.json', $data);
        $files['summary.csv'] = Artifacts::write($output, 'summary.csv', Summary::csv($data));
        Files::same($source, $this->source->verify($input, $result), 'source start/end');
        Files::same($code, self::code(), 'player history code start/end');
        Artifacts::publish($output, [...Contract::DISCLOSURE, 'version' => Contract::VERSION, 'kind' => 'PLAYER_HISTORY',
            'source' => 'keirin_jp', 'from' => $source['input']['from'], 'to' => $source['input']['to'],
            'source_manifests' => $source, 'code' => $code, 'files' => $files]);

        return $data;
    }

    private function meetings(PDO $db, array $provenance, Summary $summary, array &$reasons): Generator
    {
        foreach ($db->query('SELECT payload FROM groups ORDER BY external,starts,ends,meeting,class,id') as $row) {
            $group = json_decode($row['payload'], true, flags: JSON_THROW_ON_ERROR);
            $summary->add(['year' => (int) substr($group['first_observed_date'], 0, 4),
                'meeting_grade' => $group['meeting_grade'], 'race_class' => $group['race_class']],
                ['player_meetings' => 1, 'valid_player_meetings' => $group['meeting_percentile_mean'] !== null ? 1 : 0]);
            foreach ($group['exclusion_reasons'] as $reason => $count) {
                $reasons['meeting_exclusions'][$reason] = ($reasons['meeting_exclusions'][$reason] ?? 0) + $count;
            }
            yield Files::canonical([...$group, 'provenance' => $provenance])."\n";
        }
    }

    private function entries(PDO $db, array $provenance, Summary $summary, array &$reasons, string $from): Generator
    {
        foreach ($db->query('SELECT r.*,COUNT(e.id) AS rows FROM races r LEFT JOIN entries e ON e.race_id=r.id GROUP BY r.id ORDER BY r.id') as $row) {
            $race = json_decode($row['payload'], true, flags: JSON_THROW_ON_ERROR);
            $summary->add($race['classification'], ['races' => 1, 'zero_result_races' => $row['rows'] === 0 ? 1 : 0]);
        }
        $sql = 'SELECT e.payload,r.payload AS race,g.payload AS meeting,g.history FROM entries e
            JOIN races r ON r.id=e.race_id JOIN groups g ON g.id=e.group_key ORDER BY e.race_id,e.bike,e.id';
        foreach ($db->query($sql) as $row) {
            $entry = json_decode($row['payload'], true, flags: JSON_THROW_ON_ERROR);
            $race = json_decode($row['race'], true, flags: JSON_THROW_ON_ERROR);
            $meeting = json_decode($row['meeting'], true, flags: JSON_THROW_ON_ERROR);
            $history = json_decode($row['history'], true, flags: JSON_THROW_ON_ERROR);
            if ($entry['identity_status'] !== 'IDENTIFIED') {
                $history = History::calculate([], $race['meeting']['starts_on'], $from, [$entry['identity_status']]);
            }
            $counts = ['result_rows' => 1,
                match ($entry['identity_status']) {
                    'IDENTIFIED' => 'identified_rows', 'IDENTITY_CONFLICT' => 'identity_conflict_rows', default => 'unresolved_rows'
                } => 1,
                $history['trend_null_reason'] === null ? 'trend_calculated' : 'trend_null' => 1];
            foreach ($history['windows'] as $n => $window) {
                $counts['window_'.$n.'_eligible'] = $window['blocking_reasons'] === [] ? 1 : 0;
                $counts['window_'.$n.'_blocked'] = $window['blocking_reasons'] !== [] ? 1 : 0;
                $counts['window_'.$n.'_full_observed'] = $window['observed_meetings'] === $n ? 1 : 0;
                $counts['window_'.$n.'_full_valid'] = $window['valid_meetings'] === $n ? 1 : 0;
                foreach ($window['flags'] as $flag => $value) {
                    $counts['window_'.$n.'_'.$flag] = (int) $value;
                }
            }
            foreach ($meeting['context_flags'] as $reason) {
                $reasons['context'][$reason] = ($reasons['context'][$reason] ?? 0) + 1;
            }
            $identity = $entry['identity_status'];
            $reasons['entry_identity'][$identity] = ($reasons['entry_identity'][$identity] ?? 0) + 1;
            if ($history['trend_null_reason'] !== null) {
                $reason = $history['trend_null_reason'];
                $reasons['trend_null'][$reason] = ($reasons['trend_null'][$reason] ?? 0) + 1;
            }
            $summary->add($race['classification'], $counts);
            yield Files::canonical([...Contract::DISCLOSURE, 'race_id' => $race['race_id'], 'race_date' => $race['race_date'],
                'result_id' => $entry['audit']['result_id'], 'bike_number' => $entry['audit']['bike_number'],
                'source' => 'keirin_jp', 'external_player_id' => $entry['external_player_id'], 'identity_status' => $identity,
                'player_meeting_id' => $entry['group_key'], 'meeting' => $race['meeting'], 'classification' => $race['classification'],
                'context_flags' => $meeting['context_flags'], 'measurement_definition_id' => Relative::DEFINITION,
                'history' => $history, 'target_result_audit' => $entry['audit'], 'provenance' => $provenance])."\n";
        }
    }

    public static function code(): array
    {
        $paths = glob(__DIR__.'/*.php');
        foreach ([BuildAgariPlayerHistoryCommand::class, Artifacts::class, Relative::class, Files::class, StructureValues::class] as $class) {
            $paths[] = (new \ReflectionClass($class))->getFileName();
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname((new \ReflectionClass(BigRational::class))->getFileName()), \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $paths[] = $file->getPathname();
            }
        }
        $paths[] = base_path('composer.lock');
        sort($paths, SORT_STRING);
        $hashes = [];
        foreach ($paths as $path) {
            $hashes[str_replace(base_path().'/', '', $path)] = hash_file('sha256', $path);
        }

        return $hashes;
    }
}
