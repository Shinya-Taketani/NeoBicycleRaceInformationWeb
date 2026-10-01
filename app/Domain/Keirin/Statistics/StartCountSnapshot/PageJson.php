<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\StartCountSnapshot;

use App\Domain\Keirin\Scraping\Exceptions\ParserException;
use App\Domain\Keirin\Scraping\Parsers\EmbeddedJsonExtractor;
use Symfony\Component\DomCrawler\Crawler;

final class PageJson
{
    public static function extract(string $html, string $key): \stdClass
    {
        // Reuse the production syntax/structure check; preserve native JSON types for evidence.
        (new EmbeddedJsonExtractor)->extract($html, $key);
        foreach ((new Crawler($html))->filter('script') as $node) {
            foreach (['jsonData["'.$key.'"]', "jsonData['".$key."']"] as $marker) {
                $p = strpos($node->textContent, $marker);
                if ($p === false) {
                    continue;
                }
                $equals = strpos($node->textContent, '=', $p + strlen($marker));
                if ($equals === false) {
                    continue;
                }
                $text = ltrim(substr($node->textContent, $equals + 1));
                $depth = 0;
                $quoted = $escaped = false;
                for ($i = 0, $n = strlen($text); $i < $n; $i++) {
                    $c = $text[$i];
                    if ($quoted) {
                        if ($escaped) {
                            $escaped = false;
                        } elseif ($c === '\\') {
                            $escaped = true;
                        } elseif ($c === '"') {
                            $quoted = false;
                        }
                    } elseif ($c === '"') {
                        $quoted = true;
                    } elseif ($c === '{' || $c === '[') {
                        $depth++;
                    } elseif (($c === '}' || $c === ']') && --$depth === 0) {
                        $value = json_decode(substr($text, 0, $i + 1), false, 512, JSON_THROW_ON_ERROR);
                        if (! $value instanceof \stdClass) {
                            throw new ParserException('Expected '.$key.' JSON object.');
                        }

                        return $value;
                    }
                }
            }
        }
        throw new ParserException('Missing typed '.$key.' JSON.');
    }
}
