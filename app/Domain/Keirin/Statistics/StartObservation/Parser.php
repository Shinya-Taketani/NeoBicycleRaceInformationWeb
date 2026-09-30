<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\StartObservation;

use App\Domain\Keirin\Audit\Stat35DataReadiness\Contract as Audit;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Scraping\Parsers\EmbeddedJsonExtractor;
use App\Domain\Keirin\Scraping\Support\HtmlTextNormalizer;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;

final class Parser
{
    public function parse(string $html, array $race, array $entries, array $import): array
    {
        Audit::date($race['race_date']);
        Audit::date($import['race_date']);
        $crawler = new Crawler;
        $crawler->addHtmlContent($html, 'UTF-8');
        $scripts = '';
        foreach ($crawler->filter('script') as $node) {
            if (preg_match('/jsonData\[["\x27](?:PC0201|PJ0326)["\x27]\]/', $node->textContent)) {
                $scripts .= $node->ownerDocument->saveHTML($node);
            }
        }
        $json = new EmbeddedJsonExtractor;
        // Malformed/missing source JSON is corruption, not ordinary missing observations.
        $context = $json->extract($scripts, 'PC0201')['C0201data'] ?? null;
        $page = $json->extract($scripts, 'PJ0326');
        if (! is_array($context)) {
            throw new RuntimeException('Invalid PC0201 context object.');
        }
        $identity = [];
        if (($context['selKaisai'] ?? null) !== str_replace('-', '', $race['race_date'])
            || (string) ($context['selKjyoCd'] ?? '') !== $race['track_code']
            || (string) ($context['selRaceNo'] ?? '') !== (string) $race['race_number']) {
            $identity[] = 'RACE_IDENTITY_MISMATCH';
        }
        $tables = $crawler->filter('#rrDispTyakuJyun');
        $headers = $tables->count() === 1 ? $tables->filter('thead tr td, thead tr th')->each(
            fn (Crawler $cell) => HtmlTextNormalizer::normalize($cell->text(null, false))) : [];
        $actual = $headers;
        $expected = Audit::HEADERS;
        sort($actual);
        sort($expected);
        $schema = $tables->count() === 1 && $tables->filter('thead tr')->count() === 1
            && $actual === $expected && $tables->filter('tbody tr')->count() === 0;
        $format = $schema ? 'PJ0326_NAMED_OBJECT_EMPTY_BODY_12_HEADERS' : 'UNSUPPORTED_DISPLAY_SCHEMA';
        $typedPage = PageJson::extract($crawler);
        $flagPresent = array_key_exists('tyakujyunDispFlg', $typedPage);
        $flag = $typedPage['tyakujyunDispFlg'] ?? null;
        $displayState = in_array($flag, [true, 1, '1'], true) ? 'RESULT_DISPLAY'
            : (in_array($flag, [false, 0, '0'], true) ? 'RESULT_UNPUBLISHED' : 'RESULT_DISPLAY_UNKNOWN');
        $typedRows = $typedPage['tyakujyunItemSubData'] ?? null;
        $resultPresence = $this->presence($typedPage, 'tyakujyunItemSubData');
        $resultState = match (true) {
            $resultPresence === 'MISSING', $resultPresence === 'NULL' => $resultPresence,
            ! is_array($typedRows) => 'UNSUPPORTED_RESULT_SCHEMA',
            $typedRows === [] => 'EMPTY_ARRAY',
            default => 'ROWS',
        };
        $pageEvidence = ['page_state_version' => Contract::PAGE_STATE_VERSION,
            'ledger_page_status' => $import['parsed_page_status'] ?? null,
            'result_display_flag' => ['presence' => ! $flagPresent ? 'MISSING' : ($flag === null ? 'NULL' : 'PRESENT'),
                'raw' => $flag, 'type' => ! $flagPresent ? 'MISSING' : (is_object($flag) ? 'object' : get_debug_type($flag)),
                'state' => $displayState, 'source_pointer' => 'PJ0326.tyakujyunDispFlg'],
            'result_presence' => $resultPresence, 'result_state' => $resultState];
        if ($displayState === 'RESULT_DISPLAY_UNKNOWN') {
            $identity[] = 'RESULT_DISPLAY_UNKNOWN';
        }
        if ($resultState === 'UNSUPPORTED_RESULT_SCHEMA') {
            return $this->emptyPage($format, 'UNSUPPORTED_RESULT_SCHEMA', $pageEvidence, $headers, $identity);
        }
        $pageState = $pageEvidence['ledger_page_status'] === 'CANCELLED' ? 'CANCELLED' : $displayState;
        if ($resultState !== 'ROWS') {
            return $this->emptyPage($format, $pageState === 'CANCELLED' ? 'CANCELLED_EMPTY' : $pageState, $pageEvidence, $headers, $identity);
        }
        $rawRows = $page['tyakujyunItemSubData'];
        $entryMap = [];
        foreach ($entries as $entry) {
            $entryMap[$entry['bike_number']] = $entry;
        }
        $bikeCounts = [];
        foreach ($rawRows as $row) {
            if (is_array($row) && is_string($row['syaban'] ?? null) && preg_match('/\A[1-9]\z/D', $row['syaban'])) {
                $bikeCounts[$row['syaban']] = ($bikeCounts[$row['syaban']] ?? 0) + 1;
            }
        }
        $actualBikes = array_keys($bikeCounts);
        $entryBikes = array_keys($entryMap);
        sort($actualBikes);
        sort($entryBikes);
        $pageFlags = $identity;
        if (! $schema) {
            $pageFlags[] = 'UNSUPPORTED_DISPLAY_SCHEMA';
        }
        if ($actualBikes !== $entryBikes || count($rawRows) !== count($entries)) {
            $pageFlags[] = 'PARTIAL_OR_DIFFERENT_BIKE_SET';
        }
        $rows = [];
        $markers = 0;
        $measurable = $schema;
        foreach ($rawRows as $offset => $row) {
            if (! is_array($row) || array_is_list($row)) {
                $rows[] = ['row_index' => $offset, 'bike_number' => null, 'external_player_id' => null,
                    'identity_status' => 'UNRESOLVED', 'display_state' => 'UNSUPPORTED_ROW_SCHEMA', 'observed_s_display' => null,
                    'fields' => [], 'issues' => ['UNSUPPORTED_ROW_SCHEMA'], 'start_acquired' => null];
                $measurable = false;

                continue;
            }
            $issues = $pageFlags;
            $bike = is_string($row['syaban'] ?? null) && preg_match('/\A[1-9]\z/D', $row['syaban']) ? (int) $row['syaban'] : null;
            $external = $row['sensyuRegistNo'] ?? null;
            if ($bike === null) {
                $issues[] = 'INVALID_BIKE';
            } elseif (($bikeCounts[$bike] ?? 0) > 1) {
                $issues[] = 'DUPLICATE_BIKE';
            }
            $entry = $entryMap[$bike] ?? null;
            if ($entry === null || ! is_string($external) || ! preg_match('/\A[0-9]{6}\z/D', $external)
                || $external !== $entry['external_player_id']) {
                $issues[] = 'PLAYER_IDENTITY_MISMATCH';
            }
            $identityStatus = array_intersect($issues, ['INVALID_BIKE', 'DUPLICATE_BIKE', 'PLAYER_IDENTITY_MISMATCH', 'RACE_IDENTITY_MISMATCH']) === []
                ? 'MATCHED_LEDGER_ENTRY' : 'UNRESOLVED';
            $unknown = array_values(array_diff(array_keys($row), Audit::ROW_KEYS));
            if ($unknown !== []) {
                $issues[] = 'UNSUPPORTED_ROW_SCHEMA';
            }
            $fields = [];
            foreach (Contract::FIELDS as $field) {
                $fields[$field] = ['presence' => $this->presence($row, $field),
                    'raw' => $field === 'kojinStateItemSubData' ? $this->states($row[$field] ?? null) : ($row[$field] ?? null),
                    'source_pointer' => 'PJ0326.tyakujyunItemSubData['.$offset.'].'.$field];
            }
            $display = $this->display($row);
            if (! $schema || $unknown !== []) {
                $display = ['display_state' => 'UNSUPPORTED_DISPLAY_SCHEMA', 'observed_s_display' => null];
            }
            $markers += (int) ($display['observed_s_display'] === true);
            $measurable = $measurable && $display['observed_s_display'] !== null;
            $rows[] = ['row_index' => $offset, 'bike_number' => $bike, 'external_player_id' => $external,
                'ledger_entry_id' => $identityStatus === 'MATCHED_LEDGER_ENTRY' ? $entry['id'] : null,
                'identity_status' => $identityStatus, 'fields' => $fields, ...$display,
                'start_acquired' => null, 'issues' => $issues,
                'individual_quality' => $this->quality($row['kojinStateItemSubData'] ?? null),
                'unknown_row_keys' => $unknown];
        }
        if ($markers > 1) {
            $pageFlags[] = 'MULTIPLE_S_DISPLAYS_UNINTERPRETED';
        }

        return $pageEvidence + ['format' => $format, 'page_status' => $pageState === 'CANCELLED' ? 'CANCELLED_PARTIAL_OR_NONEMPTY' : $pageState,
            'header_signature' => hash('sha256', Files::canonical($headers)),
            'issues' => $pageFlags, 'display_s_count' => $measurable ? $markers : null,
            'rows' => $rows, 'source_update_text' => is_string($page['lastUpdateTime'] ?? null) ? $page['lastUpdateTime'] : null];
    }

    public function display(array $row): array
    {
        $presence = $this->presence($row, 'kojinStateItemSubData');
        if ($presence !== 'VALUE' && $presence !== 'EMPTY_ARRAY') {
            return ['display_state' => 'FIELD_'.$presence, 'observed_s_display' => null];
        }
        $states = $row['kojinStateItemSubData'];
        if (! is_array($states) || ! array_is_list($states)) {
            return ['display_state' => 'UNSUPPORTED_FIELD_SCHEMA', 'observed_s_display' => null];
        }
        if ($states === []) {
            return ['display_state' => 'EMPTY_ARRAY_NO_S_DISPLAY', 'observed_s_display' => false];
        }
        $hasS = false;
        $hasBlank = false;
        $unknown = false;
        foreach ($states as $state) {
            if (! is_array($state) || ! array_key_exists('kojinState', $state) || ! is_string($state['kojinState'])
                || array_diff(array_keys($state), ['kojinState', 'kojinStateClass', 'tyakuNote']) !== []) {
                return ['display_state' => 'UNSUPPORTED_FIELD_SCHEMA', 'observed_s_display' => null];
            }
            $text = $state['kojinState'];
            $hasS = $hasS || $text === 'S';
            $hasBlank = $hasBlank || $text === '';
            $unknown = $unknown || ! in_array($text, ['S', '', '落車棄権', '失格'], true);
        }

        return ['display_state' => $hasS ? 'EXACT_S_DISPLAY_UNINTERPRETED'
            : ($unknown ? 'UNKNOWN_EXPRESSION' : ($hasBlank ? 'BLANK_DISPLAY_MEANING_UNKNOWN' : 'NON_S_QUALITY_DISPLAY')),
            'observed_s_display' => $hasS ? true : ($unknown || $hasBlank ? null : false)];
    }

    private function states(mixed $states): mixed
    {
        if (! is_array($states) || ! array_is_list($states)) {
            return $states;
        }

        // Preserve candidate display fields, not unrelated short commentary/name/rank/payout.
        return array_map(static fn ($state) => is_array($state)
            ? array_intersect_key($state, array_flip(['kojinState', 'kojinStateClass'])) : $state, $states);
    }

    private function quality(mixed $states): array
    {
        $quality = [];
        foreach (is_array($states) ? $states : [] as $state) {
            if (is_array($state) && in_array($state['kojinState'] ?? null, ['落車棄権', '失格'], true)) {
                $quality[] = $state['kojinState'];
            }
        }

        return array_values(array_unique($quality));
    }

    private function presence(array $row, string $field): string
    {
        return ! array_key_exists($field, $row) ? 'MISSING' : match ($row[$field]) {
            null => 'NULL', '' => 'BLANK', [] => 'EMPTY_ARRAY', default => 'VALUE',
        };
    }

    private function emptyPage(string $format, string $status, array $pageEvidence, array $headers, array $issues): array
    {
        return $pageEvidence + ['format' => $format, 'page_status' => $status,
            'header_signature' => hash('sha256', Files::canonical($headers)), 'issues' => $issues,
            'display_s_count' => null, 'rows' => [], 'source_update_text' => null];
    }
}
