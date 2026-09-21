<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Audit\Stat35DataReadiness;

use App\Domain\Keirin\Scraping\Parsers\EmbeddedJsonExtractor;
use App\Domain\Keirin\Scraping\Support\HtmlTextNormalizer;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;

final class Extractor
{
    public function parse(string $html, array $race, array $entries, array $results, ?string $pageStatus = null): array
    {
        Contract::date($race['race_date']);
        $crawler = new Crawler;
        $crawler->addHtmlContent($html, 'UTF-8');
        $tables = $crawler->filter('#rrDispTyakuJyun');
        if ($tables->count() !== 1) {
            throw new RuntimeException('RESULT_TABLE_MISSING_OR_AMBIGUOUS');
        }
        $headerRows = $tables->filter('thead tr');
        if ($headerRows->count() !== 1) {
            throw new RuntimeException('AMBIGUOUS_AGARI_COLUMN');
        }
        $headers = $headerRows->filter('td,th')->each(fn (Crawler $cell) => HtmlTextNormalizer::normalize($cell->text(null, false)));
        $sorted = $headers;
        sort($sorted);
        $expected = Contract::HEADERS;
        sort($expected);
        if ($sorted !== $expected) {
            throw new RuntimeException('INCOMPATIBLE_TABLE_DEFINITION');
        }
        $signature = hash('sha256', json_encode($headers, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $scriptHtml = '';
        foreach ($crawler->filter('script') as $node) {
            if (str_contains($node->textContent, 'jsonData["PC0201"]') || str_contains($node->textContent, "jsonData['PC0201']")
                || str_contains($node->textContent, 'jsonData["PJ0326"]') || str_contains($node->textContent, "jsonData['PJ0326']")) {
                $scriptHtml .= $node->ownerDocument->saveHTML($node);
            }
        }
        $embedded = new EmbeddedJsonExtractor;
        $context = $embedded->extract($scriptHtml, 'PC0201')['C0201data'] ?? [];
        if (($context['selKaisai'] ?? null) !== str_replace('-', '', $race['race_date'])
            || (string) ($context['selKjyoCd'] ?? '') !== $race['track_code']
            || (string) ($context['selRaceNo'] ?? '') !== (string) $race['race_number']) {
            throw new RuntimeException('RAW_RACE_IDENTITY_MISMATCH');
        }
        $page = $embedded->extract($scriptHtml, 'PJ0326');
        $rows = $page['tyakujyunItemSubData'] ?? [];
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new RuntimeException('RESULT_ROWS_UNAVAILABLE');
        }
        // These pages render the header in HTML and named result objects through PJ0326.
        // Do not treat JSON's 16 keys as the HTML's 12 logical columns or consume tyaku.
        if ($tables->filter('tbody tr')->count() > 0) {
            foreach ($tables->filter('tbody tr') as $node) {
                if ((new Crawler($node))->filter('td')->count() !== count($headers)) {
                    throw new RuntimeException('ROW_HEADER_CELL_COUNT_MISMATCH');
                }
            }
            throw new RuntimeException('UNSUPPORTED_RENDERED_ROW_SCHEMA');
        }
        $entryMap = $this->map($entries);
        if ($pageStatus === 'CANCELLED') {
            return ['headers' => $headers, 'header_signature' => $signature, 'rows' => [],
                'cancelled' => $this->cancelled($rows, $entryMap, $results)];
        }
        if ($rows === []) {
            throw new RuntimeException('RESULT_ROWS_UNAVAILABLE');
        }
        $resultMap = $this->map($results);
        $parsed = [];
        foreach ($rows as $row) {
            $bike = $this->bike($row, $parsed);
            $entry = $entryMap[$bike] ?? null;
            $result = $resultMap[$bike] ?? null;
            if ($entry === null || $result === null) {
                throw new RuntimeException('ENTRY_RESULT_BIKE_MISMATCH');
            }
            if (! is_string($row['sensyuRegistNo']) || ! preg_match('/\A[0-9]+\z/D', $row['sensyuRegistNo'])
                || $row['sensyuRegistNo'] !== $entry['external_player_id']
                || ($result['race_entry_id'] !== null && $result['race_entry_id'] !== $entry['id'])
                || ($result['player_id'] !== null && $result['player_id'] !== $entry['player_id'])) {
                throw new RuntimeException('PLAYER_IDENTITY_MISMATCH');
            }
            if (! in_array($result['result_status'], Contract::STATUSES, true)) {
                throw new RuntimeException('UNKNOWN_RESULT_STATUS');
            }
            $parsed[$bike] = ['bike_number' => (int) $bike, 'race_entry_id' => $entry['id'], 'player_id' => $entry['player_id'],
                'raw_agari_text' => $row['agari'], ...$this->value($row['agari']),
                'result_status' => $result['result_status'], 'status_source' => 'CURRENT_DB_QUALITY_ONLY',
                'status_import_id' => $result['race_result_import_id'], 'header_signature' => $signature,
                'row_cell_count' => null, 'logical_header_count' => count($headers), 'row_key_count' => count($row)];
        }
        ksort($parsed, SORT_NUMERIC);
        if (array_keys($parsed) !== array_keys($entryMap) || array_keys($parsed) !== array_keys($resultMap)) {
            throw new RuntimeException('ROW_COUNT_OR_BIKE_SET_MISMATCH');
        }

        return ['headers' => $headers, 'header_signature' => $signature, 'rows' => array_values($parsed)];
    }

    private function cancelled(array $rows, array $entryMap, array $results): array
    {
        $seen = [];
        $nonempty = $entryErrors = $registrationErrors = 0;
        foreach ($rows as $row) {
            $bike = $this->bike($row, $seen);
            $seen[$bike] = true;
            // Never coerce malformed/non-string values into a blank cancellation row.
            $text = is_string($row['agari']) ? HtmlTextNormalizer::normalize($row['agari']) : $row['agari'];
            $nonempty += (int) ($text !== null && (! is_string($text) || preg_match('/\A\s*\z/uD', $text) !== 1));
            $entry = $entryMap[$bike] ?? null;
            $entryErrors += (int) ($entry === null);
            $registrationErrors += (int) (! is_string($row['sensyuRegistNo']) || ! preg_match('/\A[0-9]+\z/D', $row['sensyuRegistNo'])
                || ($entry !== null && $row['sensyuRegistNo'] !== $entry['external_player_id']));
        }

        return ['status' => $results !== [] ? 'CANCELLED_WITH_DB_RESULTS_CONFLICT'
            : ($nonempty > 0 ? 'CANCELLED_WITH_AGARI_DATA_REQUIRES_REVIEW'
                : ($rows === [] ? 'EXPLICIT_CANCELLED_NO_RESULT_ROWS' : 'EXPLICIT_CANCELLED_PARTIAL_ROWS_NO_AGARI')),
            'partial_rows' => count($rows), 'nonempty_agari_rows' => $nonempty, 'db_result_rows' => count($results),
            'entry_mapping_errors' => $entryErrors, 'registration_identity_errors' => $registrationErrors];
    }

    private function bike(mixed $row, array $seen): string
    {
        if (! is_array($row)) {
            throw new RuntimeException('INVALID_RESULT_ROW');
        }
        $keys = Contract::ROW_KEYS;
        $actualKeys = array_keys($row);
        sort($keys);
        sort($actualKeys);
        if ($actualKeys !== $keys) {
            throw new RuntimeException('INCOMPATIBLE_RESULT_OBJECT_DEFINITION');
        }
        $bike = $row['syaban'];
        if (! is_string($bike) || ! preg_match('/\A[1-9]\z/D', $bike) || isset($seen[$bike])) {
            throw new RuntimeException('INVALID_OR_DUPLICATE_BIKE');
        }

        return $bike;
    }

    public function value(mixed $raw): array
    {
        if ($raw !== null && ! is_string($raw)) {
            return ['normalized_agari_seconds' => null, 'agari_status' => 'INVALID_AGARI_FORMAT'];
        }
        $text = HtmlTextNormalizer::normalize($raw);
        if ($text === null) {
            return ['normalized_agari_seconds' => null, 'agari_status' => 'MISSING_AGARI'];
        }
        if (! preg_match('/\A[0-9]+(?:\.[0-9]+)?\z/D', $text)) {
            return ['normalized_agari_seconds' => null, 'agari_status' => is_numeric($text) ? 'INVALID_AGARI_FORMAT' : 'NON_NUMERIC_AGARI'];
        }
        if (! is_finite((float) $text) || (float) $text <= 0) {
            return ['normalized_agari_seconds' => null, 'agari_status' => 'OUT_OF_RANGE_AGARI'];
        }

        return ['normalized_agari_seconds' => $text, 'agari_status' => 'VALID'];
    }

    private function map(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $bike = $row['bike_number'];
            if (! is_int($bike) || $bike < 1 || $bike > 9 || isset($map[$bike])) {
                throw new RuntimeException('INVALID_OR_DUPLICATE_DB_BIKE');
            }
            $map[$bike] = $row;
        }
        ksort($map, SORT_NUMERIC);

        return $map;
    }
}
