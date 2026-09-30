<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\StartObservation;

use App\Domain\Keirin\Scraping\Exceptions\ParserException;
use JsonException;
use Symfony\Component\DomCrawler\Crawler;

final class PageJson
{
    // The shared extractor decodes objects as arrays. Keep native JSON types for page evidence only.
    public static function extract(Crawler $crawler): array
    {
        foreach ($crawler->filter('script') as $node) {
            $source = $node->textContent;
            foreach (['jsonData["PJ0326"]', "jsonData['PJ0326']"] as $marker) {
                $position = strpos($source, $marker);
                if ($position === false || ($equals = strpos($source, '=', $position + strlen($marker))) === false) {
                    continue;
                }
                $offset = $equals + 1;
                $length = strlen($source);
                while ($offset < $length && ctype_space($source[$offset])) {
                    $offset++;
                }
                if ($offset >= $length || ! in_array($source[$offset], ['{', '['], true)) {
                    throw new ParserException('Embedded JSON PJ0326 did not start with an object or array.');
                }
                $depth = 0;
                $inString = $escaped = false;
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
                        if (--$depth === 0) {
                            try {
                                return (array) json_decode(substr($source, $offset, $index - $offset + 1), false, 512, JSON_THROW_ON_ERROR);
                            } catch (JsonException $error) {
                                throw new ParserException('Embedded JSON PJ0326 was invalid.', previous: $error);
                            }
                        }
                    }
                }
                throw new ParserException('Embedded JSON PJ0326 was incomplete.');
            }
        }

        throw new ParserException('Embedded JSON PJ0326 was not found.');
    }
}
