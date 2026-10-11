<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\PrestartEvidence;

use DOMDocument;
use DOMXPath;
use RuntimeException;
use stdClass;

/** Native JSON objects preserve the distinction between {} and [] in original optional fields. */
final class RawObservationDecoder
{
    public function object(string $json): array
    {
        $object = json_decode($json, false, 64, JSON_THROW_ON_ERROR);
        if (! $object instanceof stdClass) {
            throw new RuntimeException('Raw JSON object required.');
        }

        return (array) $object;
    }

    public function embedded(string $html, string $key): array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (! $loaded) {
            throw new RuntimeException('Raw HTML invalid.');
        }
        foreach ((new DOMXPath($document))->query('//script') as $script) {
            $source = $script->textContent;
            foreach (["jsonData[\"{$key}\"]", "jsonData['{$key}']"] as $marker) {
                $position = strpos($source, $marker);
                if ($position === false || ($equals = strpos($source, '=', $position + strlen($marker))) === false) {
                    continue;
                }
                $offset = $equals + 1;
                while ($offset < strlen($source) && ctype_space($source[$offset])) {
                    $offset++;
                }
                if (($source[$offset] ?? '') !== '{') {
                    throw new RuntimeException('Embedded raw object required.');
                }
                // Scan a JSON value, not HTML: braces inside quoted strings do not terminate it.
                $depth = 0;
                $quoted = $escaped = false;
                for ($index = $offset; $index < strlen($source); $index++) {
                    $character = $source[$index];
                    if ($quoted) {
                        if ($escaped) {
                            $escaped = false;
                        } elseif ($character === '\\') {
                            $escaped = true;
                        } elseif ($character === '"') {
                            $quoted = false;
                        }
                    } elseif ($character === '"') {
                        $quoted = true;
                    } elseif ($character === '{' || $character === '[') {
                        $depth++;
                    } elseif ($character === '}' || $character === ']') {
                        $depth--;
                        if ($depth === 0) {
                            return $this->object(substr($source, $offset, $index - $offset + 1));
                        }
                    }
                }
                throw new RuntimeException('Embedded raw JSON incomplete.');
            }
        }

        throw new RuntimeException('Embedded raw object missing.');
    }
}
