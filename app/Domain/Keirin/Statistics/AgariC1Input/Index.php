<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\AgariC1Input;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalMeetingGradeAnalysis\Classification;
use App\Domain\Keirin\Statistics\AgariPlayerHistory\Exact;
use App\Domain\Keirin\Statistics\AgariPlayerHistory\History;
use App\Domain\Keirin\Statistics\AgariRaceRelative\Artifacts;
use PDO;
use RuntimeException;

final class Index
{
    public readonly PDO $db;

    public function __construct(string $path)
    {
        if (file_exists($path) || is_link($path)) {
            throw new RuntimeException('Existing workspace cannot be used.');
        }
        $this->db = new PDO('sqlite:'.$path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $this->db->exec('PRAGMA cache_size=-2048; PRAGMA temp_store=FILE;
            CREATE TABLE history(id TEXT PRIMARY KEY, external TEXT, class TEXT, ends TEXT, starts TEXT, meeting INTEGER, body TEXT);
            CREATE INDEX past ON history(external,class,ends,starts);
            CREATE UNIQUE INDEX series_meeting ON history(external,class,meeting);
            CREATE TABLE contexts(entry INTEGER PRIMARY KEY, body TEXT, duplicate INTEGER NOT NULL DEFAULT 0, conflict INTEGER NOT NULL DEFAULT 0);
            CREATE TABLE targets(entry INTEGER PRIMARY KEY, year INTEGER, body TEXT);
            CREATE TABLE races(id INTEGER PRIMARY KEY);
            CREATE TABLE entries(id INTEGER PRIMARY KEY);');
    }

    public function ingest(array $source): void
    {
        $put = $this->db->prepare('INSERT INTO history VALUES (?,?,?,?,?,?,?)');
        $this->db->beginTransaction();
        $targetPut = $this->db->prepare('INSERT INTO targets VALUES (?,?,?)');
        foreach (Contract::YEARS as $year) {
            $count = 0;
            foreach (Artifacts::lines($source['c1'].'/history-'.$year.'.jsonl') as $row) {
                $target = [];
                // Deliberately project target metadata only, never aggregate/cache/result values.
                foreach (['race_id', 'entry_id', 'bike', 'race_date', 'meeting_id', 'meeting_start', 'meeting_end'] as $field) {
                    if (! is_array($row['target'] ?? null) || ! array_key_exists($field, $row['target'])) {
                        throw new RuntimeException('Missing fixed C1 target field.');
                    }
                    $target[$field] = $row['target'][$field];
                }
                if (! Contract::id($target['race_id']) || ! Contract::id($target['entry_id']) || ! Contract::id($target['bike'])
                    || $target['bike'] > 9 || ! Contract::date($target['race_date']) || (int) substr($target['race_date'], 0, 4) !== $year) {
                    throw new RuntimeException('Invalid fixed C1 target identity/date.');
                }
                $targetPut->execute([$target['entry_id'], $year, Files::canonical($target)]);
                $count++;
            }
            if ($count !== $source['expected_targets'][$year]) {
                throw new RuntimeException('C1 target manifest row count mismatch.');
            }
        }
        foreach (Artifacts::lines($source['history'].'/player-meetings.jsonl') as $row) {
            if (($row['source'] ?? null) !== 'keirin_jp' || ($row['measurement_definition_id'] ?? null) !== Contract::DEFINITION
                || ! is_bool($row['history_context_eligible'] ?? null) || ! is_string($row['id'] ?? null)
                || ! in_array($row['race_class'] ?? null, ['S_CLASS', 'A1_A2', 'A_CHALLENGE', 'UNKNOWN'], true)) {
                throw new RuntimeException('Invalid saved history identity/definition.');
            }
            $date = $row['first_observed_date'] ?? null;
            if (! Contract::date($date) || $date < '2022-01-01' || $date > '2025-12-31') {
                throw new RuntimeException('History outside authorized event years.');
            }
            self::number($row['meeting_percentile_mean']);
            if (! $row['history_context_eligible']) {
                continue;
            }
            $m = $row['meeting'];
            if (! self::external($row['external_player_id']) || ! Contract::id($m['meeting_id']) || ! Contract::date($m['starts_on'])
                || ! Contract::date($m['ends_on']) || $m['ends_on'] < $m['starts_on'] || ! is_array($row['context_flags'])
                || ! is_int($row['adopted_races']) || $row['adopted_races'] < 0 || ! is_array($row['exclusion_reasons'])) {
                throw new RuntimeException('Invalid eligible meeting.');
            }
            $body = array_intersect_key($row, array_flip(['id', 'meeting', 'context_flags', 'adopted_races', 'exclusion_reasons', 'meeting_percentile_mean']));
            $put->execute([$row['id'], $row['external_player_id'], $row['race_class'], $m['ends_on'], $m['starts_on'], $m['meeting_id'], Files::canonical($body)]);
        }
        if ($source['context'] !== null) {
            $put = $this->db->prepare('INSERT INTO contexts(entry,body) VALUES (?,?) ON CONFLICT(entry) DO UPDATE SET duplicate=1, conflict=MAX(conflict,body<>excluded.body)');
            foreach (Artifacts::lines($source['context'].'/entry-context.jsonl') as $row) {
                $fields = ['source', 'external_player_id', 'race_id', 'entry_id', 'bike', 'race_date', 'meeting', 'race_type', 'observed_at', 'source_record_id'];
                Contract::keys($row, $fields);
                Contract::keys($row['meeting'], ['meeting_id', 'starts_on', 'ends_on']);
                if ($row['source'] !== 'keirin_jp' || ! Contract::id($row['race_id']) || ! Contract::id($row['entry_id'])
                    || ! Contract::id($row['bike']) || $row['bike'] > 9 || ! Contract::date($row['race_date'])
                    || ! in_array((int) substr($row['race_date'], 0, 4), Contract::YEARS, true)
                    || ! is_string($row['source_record_id']) || $row['source_record_id'] === ''
                    || ($row['race_type'] !== null && ! is_string($row['race_type']))
                    || ($row['external_player_id'] !== null && ! is_string($row['external_player_id']))
                    || ($row['observed_at'] !== null && (! is_string($row['observed_at'])
                        || ! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})\z/', $row['observed_at'])))) {
                    throw new RuntimeException('Invalid context evidence schema.');
                }
                // Object member order is not evidence of a conflicting record.
                $row = array_replace(array_fill_keys($fields, null), $row);
                $row['meeting'] = ['meeting_id' => $row['meeting']['meeting_id'], 'starts_on' => $row['meeting']['starts_on'], 'ends_on' => $row['meeting']['ends_on']];
                $put->execute([$row['entry_id'], Files::canonical($row)]);
            }
        }
        $this->db->commit();
    }

    public function context(array $race, array $entry): array
    {
        $get = $this->db->prepare('SELECT year,body FROM targets WHERE entry=?');
        $get->execute([$entry['id']]);
        $fixed = $get->fetch();
        $target = $fixed ? json_decode($fixed['body'], true, flags: JSON_THROW_ON_ERROR) : null;
        if ($target === null || $target['race_id'] !== $race['race_id'] || $target['bike'] !== $entry['bike'] || $fixed['year'] !== $race['year']) {
            throw new RuntimeException('Fixed C1 target/input identity mismatch.');
        }
        $get = $this->db->prepare('SELECT * FROM contexts WHERE entry=?');
        $get->execute([$entry['id']]);
        $row = $get->fetch();
        if (! $row) {
            return ['reasons' => ['MISSING_IDENTITY_CONTEXT_EVIDENCE'], 'evidence' => null, 'target' => $target,
                'validity' => ['identity' => false, 'meeting' => false, 'class' => false]];
        }
        $c = json_decode($row['body'], true, flags: JSON_THROW_ON_ERROR);
        $reasons = [];
        if ($row['duplicate']) {
            $reasons[] = 'DUPLICATE_CONTEXT';
        }
        if ($row['conflict']) {
            $reasons[] = 'CONTEXT_IDENTITY_CONFLICT';
        }
        $associated = $c['race_id'] === $target['race_id']
            && $c['entry_id'] === $target['entry_id'] && $c['bike'] === $target['bike'];
        if (! $associated) {
            $reasons[] = 'CONTEXT_IDENTITY_CONFLICT';
        }
        if (! self::external($c['external_player_id'])) {
            $reasons[] = 'UNRESOLVED_EXTERNAL_ID';
        }
        $m = $c['meeting'];
        if (! Contract::id($m['meeting_id']) || ! Contract::date($m['starts_on']) || ! Contract::date($m['ends_on'])
            || $m['starts_on'] > $c['race_date'] || $m['ends_on'] < $c['race_date']) {
            $reasons[] = 'INVALID_MEETING_CONTEXT';
        }
        $targetMatches = $c['race_date'] === $target['race_date'] && $m['meeting_id'] === $target['meeting_id']
            && $m['starts_on'] === $target['meeting_start'] && $m['ends_on'] === $target['meeting_end'];
        if (! $targetMatches) {
            $reasons[] = 'CONTEXT_TARGET_MISMATCH';
        }
        $meetingValid = $associated && ! $row['duplicate'] && $targetMatches && ! in_array('INVALID_MEETING_CONTEXT', $reasons, true);
        $class = Classification::raceClass($c['race_type']);
        if ($class === 'UNKNOWN') {
            $reasons[] = 'UNKNOWN_RACE_CLASS';
        }

        return ['reasons' => array_values(array_unique($reasons)), 'evidence' => $c, 'class' => $class, 'target' => $target,
            'validity' => ['identity' => $associated && ! $row['duplicate'] && $targetMatches && self::external($c['external_player_id']),
                'meeting' => $meetingValid, 'class' => $meetingValid && $class !== 'UNKNOWN']];
    }

    public function calculate(array $context): array
    {
        $c = $context['evidence'];
        $target = $context['target'];
        $cutoff = Contract::date($target['meeting_start']) ? $target['meeting_start'] : null;
        $reasons = $context['reasons'];
        $candidates = [];
        if ($reasons === []) {
            $q = $this->db->prepare('SELECT body FROM history WHERE external=? AND class=? AND ends<? AND meeting<>? ORDER BY ends DESC,starts DESC,id LIMIT 13');
            $q->execute([$c['external_player_id'], $context['class'], $cutoff, $target['meeting_id']]);
            while ($row = $q->fetch()) {
                $candidates[] = json_decode($row['body'], true, flags: JSON_THROW_ON_ERROR);
            }
        }
        $window = History::calculate($candidates, $cutoff, '2022-01-01', $reasons)['windows'][6];
        $value = self::number($window['mean']);
        $reason = $window['blocking_reasons'] !== [] ? $window['blocking_reasons']
            : ($value === null ? [$window['observed_meetings'] === 0 ? 'NO_OBSERVED_HISTORY' : 'NO_VALID_HISTORY'] : []);
        // Only the requested mean is an input. Quality, references and exact arithmetic are separate audit.
        unset($window['median'], $window['population_variance']);

        return ['value' => $value, 'reasons' => $reason, 'window' => $window];
    }

    public static function number(?array $exact): ?float
    {
        if ($exact === null) {
            return null;
        }
        Contract::keys($exact, ['numerator', 'denominator', 'decimal']);
        if (! is_string($exact['numerator']) || ! preg_match('/\A\d+\z/', $exact['numerator'])
            || ! is_string($exact['denominator']) || ! preg_match('/\A[1-9]\d*\z/', $exact['denominator'])) {
            throw new RuntimeException('Invalid exact fraction.');
        }
        $rational = Exact::rational($exact);
        if ($rational->isLessThan(0) || $rational->isGreaterThan(1) || Exact::value($rational)['decimal'] !== $exact['decimal']) {
            throw new RuntimeException('Exact fraction range/decimal mismatch.');
        }
        $value = (float) $exact['decimal'];
        if (! is_finite($value)) {
            throw new RuntimeException('Non-finite converted fraction.');
        }

        return $value;
    }

    public static function external(mixed $id): bool
    {
        return is_string($id) && preg_match('/\A\d{6}\z/', $id) === 1;
    }
}
