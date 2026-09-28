<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\AgariC1Context;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Statistics\AgariC1Input\Contract as InputContract;
use App\Domain\Keirin\Statistics\AgariC1Input\Sources;
use App\Domain\Keirin\Statistics\AgariC1Input\Stream;
use App\Domain\Keirin\Statistics\AgariRaceRelative\Artifacts;
use PDO;
use RuntimeException;

final class Builder
{
    public function __construct(private readonly array $pin = Sources::PINS['c1']) {}

    public function build(string $extraction, string $output, ?string $original = null): array
    {
        $code = Contract::code();
        $source = Contract::published($extraction, 'EXTRACTION');
        Files::same($this->pin, $source['source']['manifest'], 'fixed C1 identity');
        Files::same($code, $source['code'], 'extraction processing code');
        $previous = $original === null ? null : Contract::published($original, 'MAPPING');
        Contract::output($output, array_filter([$extraction, $original, Contract::C1_DIRECTORY]));
        try {
            Artifacts::create($output.'/candidate');
            $contexts = new Stream($output.'/candidate/entry-context.jsonl');
            $audit = new Stream($output.'/mapping-audit.jsonl');
            $sample = new Stream($output.'/samples.jsonl');
            $seen = new PDO('sqlite:'.$output.'/seen.sqlite', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $seen->exec('PRAGMA cache_size=-2048; PRAGMA temp_store=FILE; CREATE TABLE races(id INTEGER PRIMARY KEY); CREATE TABLE entries(id INTEGER PRIMARY KEY);');
            $racePut = $seen->prepare('INSERT INTO races VALUES (?)');
            $entryPut = $seen->prepare('INSERT INTO entries VALUES (?)');
            $seen->beginTransaction();
            $records = Artifacts::lines($extraction.'/db-records.jsonl');
            $years = array_fill_keys(InputContract::YEARS, self::counts());
            $samples = [];
            $line = 0;
            foreach (Artifacts::lines($extraction.'/targets.jsonl') as $race) {
                $racePut->execute([$race['race_id']]);
                $raws = [];
                foreach ($race['entries'] as $target) {
                    $entryPut->execute([$target['entry_id']]);
                    if (! $records->valid()) {
                        throw new RuntimeException('Truncated extraction records.');
                    }
                    $raws[] = $records->current();
                    $records->next();
                }
                $matches = Matcher::race($race, $raws);
                $count = &$years[$race['year']];
                $count['races']++;
                foreach ($matches as $i => $match) {
                    $line++;
                    $count['entries']++;
                    foreach ($match['checks'] as $key => $yes) {
                        $count[$key] += (int) $yes;
                    }
                    $count[$match['candidate'] ? 'candidates' : 'held']++;
                    $status = $match['player_id_status'];
                    $count['player_id_status'][$status] = ($count['player_id_status'][$status] ?? 0) + 1;
                    foreach ($match['reasons'] as $reason) {
                        $count['reasons'][$reason] = ($count['reasons'][$reason] ?? 0) + 1;
                    }
                    if ($match['candidate']) {
                        $contexts->row($match['context']);
                    }
                    $row = ['year' => $race['year'], 'race_id' => $race['race_id'], 'entry_id' => $match['target']['entry_id'],
                        'extraction_record_line' => $line, ...$match];
                    $audit->row($row);
                    // First example in fixed C1 order per year/reason, independent of DB order or outcomes.
                    foreach ($match['reasons'] ?: ['CANDIDATE'] as $reason) {
                        $key = $race['year'].':'.$reason;
                        if (! isset($samples[$key])) {
                            $sample->row(['sample_key' => $key, 'mapping' => $row, 'original_record' => $raws[$i]]);
                            $samples[$key] = true;
                        }
                    }
                }
                unset($count);
            }
            $seen->commit();
            if ($records->valid() || $line !== $source['completed_entries']) {
                throw new RuntimeException('Extra extraction records or count mismatch.');
            }
            $total = self::counts();
            foreach ($years as $year => &$count) {
                if ($count['races'] !== $source['source']['years'][$year]['races'] || $count['entries'] !== $source['source']['years'][$year]['entries']
                    || $count['entries'] !== $count['candidates'] + $count['held']) {
                    throw new RuntimeException('Cohort/held reconciliation failed.');
                }
                ksort($count['reasons']);
                ksort($count['player_id_status']);
                foreach ($count as $key => $value) {
                    if (is_array($value)) {
                        foreach ($value as $reason => $n) {
                            $total[$key][$reason] = ($total[$key][$reason] ?? 0) + $n;
                        }
                        ksort($total[$key]);
                    } else {
                        $total[$key] += $value;
                    }
                }
            }
            unset($count);
            $summary = ['status' => 'REVIEW_PENDING', 'historical_as_of_available' => false, 'reason_counts_nonexclusive' => true,
                'years' => $years, 'total' => $total, 'automatic_acceptance' => false];
            $files = ['candidate/entry-context.jsonl' => $contexts->finish(), 'mapping-audit.jsonl' => $audit->finish(),
                'samples.jsonl' => $sample->finish(), 'summary.json' => Artifacts::json($output, 'summary.json', $summary)];
            $provenance = ['extraction_directory' => realpath($extraction), 'extraction_manifest' => Files::identity($extraction.'/manifest.json'),
                'source' => $source['source'], 'connection' => $source['connection'], 'tables' => Contract::TABLES, 'columns' => Contract::COLUMNS,
                'field_mapping' => ['external_player_id' => 'race_entries.external_player_id', 'race_id' => 'races.id',
                    'entry_id' => 'race_entries.id', 'bike' => 'race_entries.bike_number', 'race_date' => 'races.race_date',
                    'meeting' => 'races.race_day_id -> race_days.id/race_meeting_id -> race_meetings.id/starts_on/ends_on',
                    'race_type' => 'races.race_type', 'player_id_check' => 'fixed target.player_id = race_entries.player_id'],
                'observed_at' => null, 'entry_fetched_at_meaning' => 'SAVED_ENTRY_FETCH_NOT_ALL_FIELDS_OBSERVATION',
                'historical_as_of_available' => false, 'missing_internal_identity_policy' => 'HELD_NOT_INFERRED'];
            $files['provenance.json'] = Artifacts::json($output, 'provenance.json', $provenance);
            Files::same($source, Contract::published($extraction, 'EXTRACTION'), 'extraction start/end');
            Files::same($code, Contract::code(), 'mapping code start/end');
            // Seal the exchange format, but do not register it as reviewed evidence in AgariC1Input.
            Artifacts::publish($output.'/candidate', ['version' => InputContract::CONTEXT_VERSION,
                'origin' => 'SAVED_ENTRY_AND_MEETING_METADATA', 'historical_as_of_available' => false,
                'files' => ['entry-context.jsonl' => $files['candidate/entry-context.jsonl']]]);
            foreach (['manifest.json', 'COMPLETE.json'] as $name) {
                $files['candidate/'.$name] = Files::identity($output.'/candidate/'.$name);
            }
            $manifest = ['version' => Contract::VERSION, 'kind' => 'MAPPING', 'status' => 'REVIEW_PENDING',
                'historical_as_of_available' => false, 'extraction_manifest' => $provenance['extraction_manifest'], 'code' => $code, 'files' => $files];
            if ($previous !== null) {
                Files::same($previous, $manifest, 'independent context reproduction');
                Files::same($previous, Contract::published($original, 'MAPPING'), 'original mapping start/end');
                Artifacts::json($output, 'reproduction.json', ['identical' => true, 'files' => array_keys($files),
                    'semantic_sha256' => $files['candidate/entry-context.jsonl']['sha256']]);
            }
            Artifacts::publish($output, $manifest);

            return $summary;
        } catch (\Throwable $e) {
            Artifacts::json($output, 'FAILED.json', ['error_class' => $e::class, 'message' => $e->getMessage()]);
            throw $e;
        }
    }

    private static function counts(): array
    {
        return ['races' => 0, 'entries' => 0, 'db_matched' => 0, 'valid_external_id' => 0, 'identity_matched' => 0,
            'meeting_matched' => 0, 'class_known' => 0, 'candidates' => 0, 'held' => 0, 'player_id_status' => [], 'reasons' => []];
    }
}
