<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\PrestartEvidence\Attributes;

use App\Domain\Keirin\Scraping\PrestartEvidence\EvidenceFiles;
use DOMDocument;
use DOMXPath;
use RuntimeException;

final class RawDocuments
{
    public const VERSION = 'STAT39-RAW-DOCUMENTS-TOPLEVEL-v1';

    private const RESERVED_BINDINGS = ['await', 'break', 'case', 'catch', 'class', 'const', 'continue', 'debugger',
        'default', 'delete', 'do', 'else', 'enum', 'export', 'extends', 'false', 'finally', 'for', 'function',
        'if', 'implements', 'import', 'in', 'instanceof', 'interface', 'let', 'new', 'null', 'package',
        'private', 'protected', 'public', 'return', 'static', 'super', 'switch', 'this', 'throw', 'true',
        'try', 'typeof', 'var', 'void', 'while', 'with', 'yield', 'eval', 'arguments', 'jsonData'];

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
        $documents = $bindings = [];
        foreach ((new DOMXPath($dom))->query('//script') as $index => $script) {
            $text = $script->textContent;
            $this->script($text, $index, $documents, $bindings);
        }
        if (! isset($documents['PC0201'], $documents['PJ0315'])) {
            throw new RuntimeException('Both embedded documents are required.');
        }

        return $documents;
    }

    private function script(string $text, int $index, array &$documents, array &$bindings): void
    {
        $cursor = 0;
        $tokens = 0;
        $stack = [];
        $statement = [];
        while (($token = $this->token($text, $cursor, $tokens)) !== null) {
            if ($token['kind'] === 'IDENTIFIER' && $token['value'] === 'jsonData') {
                if ($stack !== []) {
                    throw new RuntimeException('NON_TOP_LEVEL_DOCUMENT_ASSIGNMENT: nested JavaScript context.');
                }
                if ($statement !== []) {
                    throw new RuntimeException('NON_STANDALONE_DOCUMENT_ASSIGNMENT: preceding statement or expression.');
                }
                $this->assignment($text, $cursor, $tokens, $index, $documents);

                continue;
            }
            if ($token['kind'] === 'PUNCTUATION') {
                $value = $token['value'];
                if (str_contains('{([', $value)) {
                    $stack[] = $value;
                    if (count($stack) > 64) {
                        throw new RuntimeException('SCRIPT_NESTING_LIMIT: unsupported JavaScript depth.');
                    }
                } elseif (isset(['}' => '{', ')' => '(', ']' => '['][$value])) {
                    $opening = ['}' => '{', ')' => '(', ']' => '['][$value];
                    if (array_pop($stack) !== $opening) {
                        throw new RuntimeException('UNBALANCED_SCRIPT_DELIMITER: unmatched closing delimiter.');
                    }
                } elseif ($value === ';' && $stack === []) {
                    $this->nonEvidenceStatement($statement, $bindings);
                    $statement = [];

                    continue;
                }
            }
            // Keep only enough tokens to recognise inert decoys; never buffer arbitrary programs.
            if (count($statement) < 5) {
                $statement[] = $token;
            }
        }
        if ($stack !== []) {
            throw new RuntimeException('UNBALANCED_SCRIPT_DELIMITER: unclosed delimiter.');
        }
        $this->nonEvidenceStatement($statement, $bindings);
    }

    private function assignment(string $text, int &$cursor, int &$tokens, int $index, array &$documents): void
    {
        $bracket = $this->token($text, $cursor, $tokens);
        $key = $this->token($text, $cursor, $tokens);
        $close = $this->token($text, $cursor, $tokens);
        $equals = $this->token($text, $cursor, $tokens);
        if (($bracket['value'] ?? null) !== '[' || ($key['kind'] ?? null) !== 'STRING'
            || ! in_array($key['value'], ['"PC0201"', "'PC0201'", '"PJ0315"', "'PJ0315'"], true)
            || ($close['value'] ?? null) !== ']' || ($equals['value'] ?? null) !== '=') {
            throw new RuntimeException('UNSUPPORTED_DOCUMENT_ASSIGNMENT: literal target key and simple assignment required.');
        }
        $name = substr($key['value'], 1, -1);
        if (isset($documents[$name])) {
            throw new RuntimeException('DUPLICATE_DOCUMENT_ASSIGNMENT: ambiguous embedded document.');
        }
        $object = $this->token($text, $cursor, $tokens);
        if (($object['value'] ?? null) !== '{') {
            throw new RuntimeException('NON_STATIC_DOCUMENT_RHS: JSON object literal required.');
        }
        $start = $object['start'];
        $end = $this->objectEnd($text, $start);
        $cursor = $end;
        $suffix = $this->token($text, $cursor, $tokens);
        if ($suffix !== null && $suffix['value'] !== ';') {
            throw new RuntimeException('UNSUPPORTED_DOCUMENT_EXPRESSION: suffix after JSON object.');
        }
        $documents[$name] = $this->document(substr($text, $start, $end - $start), 'DOM_SCRIPT_TEXT_BYTES', $start, $index);
    }

    private function nonEvidenceStatement(array $statement, array &$bindings): void
    {
        if ($statement === [] || (count($statement) === 1 && $statement[0]['kind'] === 'STRING')) {
            return;
        }
        if (count($statement) === 4 && in_array($statement[0]['value'], ['const', 'let', 'var'], true)
            && $statement[1]['kind'] === 'IDENTIFIER'
            && $statement[2]['value'] === '=' && $statement[3]['kind'] === 'STRING') {
            $binding = $statement[1]['value'];
            if (in_array($binding, self::RESERVED_BINDINGS, true) || isset($bindings[$binding])) {
                throw new RuntimeException('UNSUPPORTED_DECOY_BINDING: reserved or repeated declaration.');
            }
            $bindings[$binding] = true;

            return;
        }
        throw new RuntimeException('UNSUPPORTED_TOP_LEVEL_STATEMENT: only inert string decoys and literal document assignments supported.');
    }

    private function token(string $text, int &$cursor, int &$tokens): ?array
    {
        $length = strlen($text);
        while ($cursor < $length) {
            if (str_contains(" \t\r\n", $text[$cursor])) {
                $cursor++;
            } elseif (substr($text, $cursor, 2) === '//') {
                $cursor += strcspn($text, "\r\n", $cursor);
            } elseif (substr($text, $cursor, 2) === '/*') {
                $end = strpos($text, '*/', $cursor + 2);
                if ($end === false) {
                    throw new RuntimeException('UNTERMINATED_SCRIPT_COMMENT: missing closing comment.');
                }
                $cursor = $end + 2;
            } else {
                break;
            }
        }
        if ($cursor === $length) {
            return null;
        }
        if (++$tokens > 20000) {
            throw new RuntimeException('SCRIPT_TOKEN_LIMIT: finite synthetic script required.');
        }
        $start = $cursor;
        $byte = $text[$cursor++];
        if ($byte === '`') {
            throw new RuntimeException('UNSUPPORTED_TEMPLATE_LITERAL: interpolation is not interpreted.');
        }
        if ($byte === '/') {
            throw new RuntimeException('UNSUPPORTED_REGEXP_OR_DIVISION: slash outside comments.');
        }
        if ($byte === "'" || $byte === '"') {
            while ($cursor < $length) {
                $next = $text[$cursor++];
                if ($next === "\r" || $next === "\n") {
                    throw new RuntimeException('UNSUPPORTED_SCRIPT_STRING: unescaped line break.');
                }
                if ($next === '\\') {
                    if ($cursor === $length || str_contains("\r\n", $text[$cursor])) {
                        throw new RuntimeException('UNSUPPORTED_SCRIPT_STRING: incomplete escape or line continuation.');
                    }
                    $escape = $text[$cursor++];
                    if ($escape === 'x' || $escape === 'u') {
                        $digits = $escape === 'x' ? 2 : 4;
                        $hex = substr($text, $cursor, $digits);
                        if (strlen($hex) !== $digits || ! ctype_xdigit($hex)) {
                            throw new RuntimeException('UNSUPPORTED_SCRIPT_STRING: invalid hexadecimal escape.');
                        }
                        $cursor += $digits;
                    } elseif (! str_contains("'\"\\/bfnrtv", $escape)) {
                        throw new RuntimeException('UNSUPPORTED_SCRIPT_STRING: unsupported escape.');
                    }
                } elseif ($next === $byte) {
                    return ['kind' => 'STRING', 'value' => substr($text, $start, $cursor - $start), 'start' => $start];
                }
            }
            throw new RuntimeException('UNTERMINATED_SCRIPT_STRING: closing quote required.');
        }
        if (preg_match('/[A-Za-z_$]/D', $byte) === 1) {
            $cursor += strspn($text, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz_$0123456789', $cursor);

            return ['kind' => 'IDENTIFIER', 'value' => substr($text, $start, $cursor - $start), 'start' => $start];
        }
        if (str_contains('{}()[];=,:.<>!+-*?&|0123456789', $byte)) {
            return ['kind' => 'PUNCTUATION', 'value' => $byte, 'start' => $start];
        }
        throw new RuntimeException('UNSUPPORTED_SCRIPT_TOKEN: unsupported JavaScript lexical context.');
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
