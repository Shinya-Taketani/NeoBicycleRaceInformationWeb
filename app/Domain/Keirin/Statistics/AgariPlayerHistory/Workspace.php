<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\AgariPlayerHistory;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Statistics\AgariRaceRelative\Contract as Relative;
use DateTimeImmutable;
use PDO;

final class Workspace
{
    public readonly PDO $db;

    public function __construct(string $path)
    {
        $this->db = new PDO('sqlite:'.$path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $this->db->exec('PRAGMA cache_size=-8192; PRAGMA temp_store=FILE;
            CREATE TABLE meetings (id TEXT PRIMARY KEY, signature TEXT NOT NULL, conflict INTEGER NOT NULL DEFAULT 0);
            CREATE TABLE races (id INTEGER PRIMARY KEY, year INTEGER, grade TEXT, class TEXT, meeting TEXT, payload TEXT NOT NULL);
            CREATE TABLE entries (id INTEGER PRIMARY KEY, race_id INTEGER NOT NULL, bike INTEGER NOT NULL, observation INTEGER UNIQUE,
                group_key TEXT NOT NULL, payload TEXT NOT NULL, UNIQUE(race_id,bike));
            CREATE INDEX entry_group ON entries(group_key,race_id,id);
            CREATE TABLE groups (id TEXT PRIMARY KEY, external TEXT, class TEXT, meeting TEXT, starts TEXT, ends TEXT,
                eligible INTEGER, payload TEXT NOT NULL, history TEXT);
            CREATE INDEX history_series ON groups(external,class,eligible,ends DESC,starts DESC);
            CREATE INDEX player_period ON groups(external,starts,ends);');
    }

    public function ingest(iterable $rows, array $source): void
    {
        $meetingGet = $this->db->prepare('SELECT signature FROM meetings WHERE id=?');
        $meetingPut = $this->db->prepare('INSERT INTO meetings(id,signature) VALUES (?,?)');
        $meetingConflict = $this->db->prepare('UPDATE meetings SET conflict=1 WHERE id=?');
        $racePut = $this->db->prepare('INSERT INTO races VALUES (?,?,?,?,?,?)');
        $entryPut = $this->db->prepare('INSERT INTO entries VALUES (?,?,?,?,?,?)');
        $this->db->beginTransaction();
        foreach ($rows as [$race, $detail]) {
            $context = $race['context'];
            $c = $detail['classification'];
            $meeting = is_int($context['meeting_id']) && $context['meeting_id'] > 0 ? (string) $context['meeting_id'] : 'unknown:'.$race['race_id'];
            $metadata = array_intersect_key($context, array_flip(['meeting_id', 'starts_on', 'ends_on', 'racetrack_id', 'meeting_track_id', 'track_code']));
            $signature = Files::canonical($metadata);
            $meetingGet->execute([$meeting]);
            $previous = $meetingGet->fetchColumn();
            if ($previous === false) {
                $meetingPut->execute([$meeting, $signature]);
            } elseif ($previous !== $signature) {
                $meetingConflict->execute([$meeting]);
            }
            $flags = self::contextFlags($race, $source);
            $racePayload = ['race_id' => $race['race_id'], 'race_date' => $race['race_date'],
                'classification' => $c, 'meeting' => $metadata, 'context_flags' => $flags];
            $racePut->execute([$race['race_id'], $c['year'], $c['meeting_grade'], $c['race_class'], $meeting, Files::canonical($racePayload)]);
            $externals = [];
            foreach ($detail['results'] as $row) {
                if (self::external($row['external_player_id'])) {
                    $externals[$row['external_player_id']] = ($externals[$row['external_player_id']] ?? 0) + 1;
                }
            }
            foreach ($detail['results'] as $row) {
                $external = self::external($row['external_player_id']) ? $row['external_player_id'] : null;
                $identity = $external === null ? 'UNRESOLVED_EXTERNAL_ID' : ($externals[$external] > 1 ? 'IDENTITY_CONFLICT' : 'IDENTIFIED');
                $key = hash('sha256', Files::canonical(['keirin_jp', $external ?? 'unknown:'.$row['result_id'], Relative::DEFINITION, $c['race_class'], $meeting]));
                $payload = ['external_player_id' => $external, 'observed_external_player_id' => $row['external_player_id'],
                    'identity_status' => $identity, 'group_key' => $key, 'audit' => $row,
                    'comparison_completeness' => $detail['comparison_completeness'], 'race_exclusion_reasons' => $detail['exclusion_reasons']];
                $entryPut->execute([$row['result_id'], $race['race_id'], $row['bike_number'], $row['observation_id'], $key, Files::canonical($payload)]);
            }
        }
        $this->db->commit();
    }

    public static function external(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[0-9]{6}\z/', $value) === 1;
    }

    private static function contextFlags(array $race, array $source): array
    {
        $c = $race['context'];
        $flags = [];
        if (! is_int($c['meeting_id']) || $c['meeting_id'] <= 0) {
            $flags[] = 'UNKNOWN_MEETING';
        }
        foreach (['starts_on', 'ends_on'] as $field) {
            if (! self::date($c[$field])) {
                $flags[] = 'UNKNOWN_MEETING_PERIOD';
            }
        }
        if (! in_array('UNKNOWN_MEETING_PERIOD', $flags, true)) {
            if ($c['starts_on'] > $c['ends_on'] || $race['race_date'] < $c['starts_on'] || $race['race_date'] > $c['ends_on']) {
                $flags[] = 'MEETING_PERIOD_CONFLICT';
            }
            if ($c['starts_on'] < $source['input']['from']) {
                $flags[] = 'MEETING_CROSSES_INPUT_START';
            }
            if ($c['ends_on'] > $source['input']['to']) {
                $flags[] = 'MEETING_CROSSES_INPUT_END';
            }
        }
        if (! is_int($c['racetrack_id']) || $c['racetrack_id'] <= 0 || $c['racetrack_id'] !== $c['meeting_track_id']
            || ! is_string($c['track_code']) || ! preg_match('/\A[0-9]{2}\z/', $c['track_code'])
            || $c['day_date'] !== $race['race_date']) {
            $flags[] = 'MEETING_RELATION_CONFLICT';
        }

        return array_values(array_unique($flags));
    }

    private static function date(mixed $date): bool
    {
        return is_string($date) && preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $date)
            && ($parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date)) !== false && $parsed->format('Y-m-d') === $date;
    }
}
