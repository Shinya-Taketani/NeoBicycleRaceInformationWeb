<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\PrestartEvidence\Attributes;

use App\Domain\Keirin\Scraping\PrestartEvidence\EvidenceFiles;
use RuntimeException;

final class LosslessJson
{
    public const VERSION = 'STAT39-LOSSLESS-JSON-v1';

    private int $cursor = 0;

    private int $nodes = 0;

    private int $lexicalBytes = 0;

    private int $pointerBytes = 0;

    public function parse(string $json): JsonNode
    {
        if (strlen($json) > EvidenceFiles::MAX_BYTES || ! json_validate($json, 64)) {
            throw new RuntimeException('Invalid or oversized JSON.');
        }
        $this->cursor = 0;
        $this->nodes = 0;
        $this->lexicalBytes = 0;
        $this->pointerBytes = 0;
        $node = $this->node($json, '');
        $this->space($json);
        if ($this->cursor !== strlen($json) || $node->type !== 'OBJECT') {
            throw new RuntimeException('A complete JSON document object is required.');
        }

        return $node;
    }

    private function node(string $json, string $pointer): JsonNode
    {
        if (++$this->nodes > 20000) {
            throw new RuntimeException('Finite JSON node budget exceeded.');
        }
        $this->pointerBytes += strlen($pointer);
        if ($this->pointerBytes > EvidenceFiles::MAX_BYTES) {
            throw new RuntimeException('Finite structural pointer budget exceeded.');
        }
        $this->space($json);
        $start = $this->cursor;
        $first = $json[$this->cursor];
        $children = [];
        if ($first === '{' || $first === '[') {
            $object = $first === '{';
            $type = $object ? 'OBJECT' : 'ARRAY';
            $end = $object ? '}' : ']';
            $this->cursor++;
            $this->space($json);
            while ($json[$this->cursor] !== $end) {
                if ($object) {
                    $keyStart = $this->cursor;
                    $this->stringEnd($json);
                    $key = json_decode(substr($json, $keyStart, $this->cursor - $keyStart), true, 2, JSON_THROW_ON_ERROR);
                    if (array_key_exists($key, $children)) {
                        throw new RuntimeException('Duplicate JSON key; no unambiguous attribute evidence.');
                    }
                    $this->space($json);
                    $this->cursor++; // Colon, already checked by json_validate().
                } else {
                    $key = count($children);
                }
                $children[$key] = $this->node($json, $pointer.'/'.str_replace(['~', '/'], ['~0', '~1'], (string) $key));
                $this->space($json);
                if ($json[$this->cursor] === ',') {
                    $this->cursor++;
                    $this->space($json);
                }
            }
            $this->cursor++;
        } elseif ($first === '"') {
            $type = 'STRING';
            $this->stringEnd($json);
        } else {
            while ($this->cursor < strlen($json) && ! str_contains(" \t\r\n,]}", $json[$this->cursor])) {
                $this->cursor++;
            }
            $token = substr($json, $start, $this->cursor - $start);
            $type = match (true) {
                $token === 'null' => 'NULL',
                in_array($token, ['true', 'false'], true) => 'BOOLEAN',
                strpbrk($token, '.eE') !== false => 'DECIMAL',
                default => 'INTEGER',
            };
        }

        $length = $this->cursor - $start;
        $this->lexicalBytes += $length;
        if ($this->lexicalBytes > 2 * EvidenceFiles::MAX_BYTES) {
            throw new RuntimeException('Finite lexical evidence budget exceeded.');
        }

        return new JsonNode($type, substr($json, $start, $length), $start, $pointer, $children);
    }

    private function stringEnd(string $json): void
    {
        $this->cursor++;
        while ($json[$this->cursor] !== '"') {
            $this->cursor += $json[$this->cursor] === '\\' ? 2 : 1;
        }
        $this->cursor++;
    }

    private function space(string $json): void
    {
        while ($this->cursor < strlen($json) && str_contains(" \t\r\n", $json[$this->cursor])) {
            $this->cursor++;
        }
    }
}
