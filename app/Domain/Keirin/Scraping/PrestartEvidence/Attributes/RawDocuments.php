<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\PrestartEvidence\Attributes;

use App\Domain\Keirin\Scraping\PrestartEvidence\EvidenceFiles;
use DOMDocument;
use DOMXPath;
use RuntimeException;

final class RawDocuments
{
    public function decode(string $source, string $raw): array
    {
        if (strlen($raw) > EvidenceFiles::MAX_BYTES) {
            throw new RuntimeException('Finite synthetic Raw size exceeded.');
        }
        if ($source === 'JSJ017') {
            return ['JSJ017' => $this->document($raw, 'RAW_BYTES', 0, null)];
        }
        if ($source !== 'PJ0315') {
            throw new RuntimeException('Unsupported synthetic attribute source.');
        }
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            if (! $dom->loadHTML('<?xml encoding="UTF-8">'.$raw, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                throw new RuntimeException('Cannot parse synthetic HTML.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $documents = [];
        foreach ((new DOMXPath($dom))->query('//script') as $index => $script) {
            $text = $script->textContent;
            $length = strlen($text);
            $statementBoundary = true;
            for ($i = 0; $i < $length; $i++) {
                if (str_contains(" \t\r\n", $text[$i])) {
                    continue;
                }
                if (str_contains("'\"`", $text[$i])) {
                    $statementBoundary = false;
                    $quote = $text[$i];
                    for ($i++; $i < $length; $i++) {
                        if ($text[$i] === '\\') {
                            $i++;
                        } elseif ($text[$i] === $quote) {
                            break;
                        }
                    }

                    continue;
                }
                if (substr($text, $i, 2) === '//') {
                    $i = strpos($text, "\n", $i) ?: $length;

                    continue;
                }
                if (substr($text, $i, 2) === '/*') {
                    $end = strpos($text, '*/', $i + 2);
                    if ($end === false) {
                        throw new RuntimeException('Unterminated script comment.');
                    }
                    $i = $end + 1;

                    continue;
                }
                if (($i > 0 && preg_match('/[\w$.]/', $text[$i - 1]) === 1)
                    || preg_match('/\GjsonData\s*\[\s*([\'"])(PC0201|PJ0315)\1\s*\]\s*=\s*/A', $text, $match, 0, $i) !== 1) {
                    $statementBoundary = $text[$i] === ';';

                    continue;
                }
                if (! $statementBoundary) {
                    throw new RuntimeException('Embedded assignment is not a standalone literal statement.');
                }
                $key = $match[2];
                if (isset($documents[$key])) {
                    throw new RuntimeException('Ambiguous embedded document assignment.');
                }
                $start = $i + strlen($match[0]);
                $end = $this->objectEnd($text, $start);
                $suffix = $end;
                while ($suffix < $length && str_contains(" \t\r\n", $text[$suffix])) {
                    $suffix++;
                }
                if ($suffix < $length && $text[$suffix] !== ';') {
                    throw new RuntimeException('Embedded JSON expression suffix is unsupported.');
                }
                $documents[$key] = $this->document(substr($text, $start, $end - $start), 'DOM_SCRIPT_TEXT_BYTES', $start, $index);
                $statementBoundary = false;
                $i = $end - 1;
            }
        }
        if (! isset($documents['PC0201'], $documents['PJ0315'])) {
            throw new RuntimeException('Both embedded documents are required.');
        }

        return $documents;
    }

    private function document(string $json, string $basis, int $start, ?int $script): array
    {
        return ['node' => (new LosslessJson)->parse($json), 'bytes' => $json,
            'offset_basis' => $basis, 'document_offset' => $start, 'script_index' => $script];
    }

    private function objectEnd(string $text, int $start): int
    {
        if (($text[$start] ?? '') !== '{') {
            throw new RuntimeException('Embedded document must be a JSON object literal.');
        }
        $depth = 0;
        $quoted = false;
        for ($i = $start; $i < strlen($text); $i++) {
            $byte = $text[$i];
            if ($quoted) {
                if ($byte === '\\') {
                    $i++;
                } elseif ($byte === '"') {
                    $quoted = false;
                }
            } elseif ($byte === '"') {
                $quoted = true;
            } elseif ($byte === '{' || $byte === '[') {
                $depth++;
            } elseif (($byte === '}' || $byte === ']') && --$depth === 0) {
                return $i + 1;
            }
        }
        throw new RuntimeException('Incomplete embedded JSON object.');
    }
}
