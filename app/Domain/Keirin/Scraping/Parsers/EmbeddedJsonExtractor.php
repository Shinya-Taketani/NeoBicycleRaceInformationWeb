<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Parsers;

use App\Domain\Keirin\Scraping\Exceptions\ParserException;
use DOMDocument;
use DOMXPath;
use JsonException;

class EmbeddedJsonExtractor
{
    public function extract(string $html, string $key): array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw new ParserException('Race page HTML could not be parsed.');
        }

        $xpath = new DOMXPath($document);
        foreach ($xpath->query('//script') ?: [] as $script) {
            $source = $script->textContent;
            foreach (["jsonData[\"{$key}\"]", "jsonData['{$key}']"] as $marker) {
                $position = strpos($source, $marker);
                if ($position === false) {
                    continue;
                }

                $equals = strpos($source, '=', $position + strlen($marker));
                if ($equals === false) {
                    continue;
                }

                return $this->decodeBalancedValue($source, $equals + 1, $key);
            }
        }

        throw new ParserException("Embedded JSON {$key} was not found.");
    }

    private function decodeBalancedValue(string $source, int $offset, string $key): array
    {
        $length = strlen($source);
        while ($offset < $length && ctype_space($source[$offset])) {
            $offset++;
        }

        if ($offset >= $length || ! in_array($source[$offset], ['{', '['], true)) {
            throw new ParserException("Embedded JSON {$key} did not start with an object or array.");
        }

        $depth = 0;
        $inString = false;
        $escaped = false;
        for ($index = $offset; $index < $length; $index++) {
            $character = $source[$index];
            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($character === '"') {
                $inString = true;
            } elseif ($character === '{' || $character === '[') {
                $depth++;
            } elseif ($character === '}' || $character === ']') {
                $depth--;
                if ($depth === 0) {
                    try {
                        $decoded = json_decode(substr($source, $offset, $index - $offset + 1), true, 512, JSON_THROW_ON_ERROR);
                    } catch (JsonException $exception) {
                        throw new ParserException("Embedded JSON {$key} was invalid.", previous: $exception);
                    }

                    if (! is_array($decoded)) {
                        throw new ParserException("Embedded JSON {$key} was not structured data.");
                    }

                    return $decoded;
                }
            }
        }

        throw new ParserException("Embedded JSON {$key} was incomplete.");
    }
}
