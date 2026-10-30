<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Support;

use App\Domain\Keirin\Scraping\Enums\RaceCategory;
use App\Domain\Keirin\Scraping\Parsers\RetiredPlayerDetailParser;
use App\Domain\Keirin\Scraping\Support\HtmlTextNormalizer;
use App\Domain\Keirin\Scraping\Support\RaceCategoryPolicy;
use App\Domain\Keirin\Statistics\DTO\SchoolProfileDto;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;

final class SchoolProfileParser
{
    public const VERSION = 'school-profile-projection-v1';

    public function parse(string $html, string $externalId): SchoolProfileDto
    {
        $page = new Crawler;
        $page->addHtmlContent($html, 'UTF-8');
        $marker = $page->filter('#PlayerNo');
        if ($marker->count() !== 1 || preg_match('/^[0-9]{6}$/D', $externalId) !== 1) {
            throw new RuntimeException('Missing or ambiguous profile identity.');
        }
        $rows = $marker->ancestors()->filter('table')->first()->filter('tr');
        $basic = $rows->eq(1)->filter('td')->each(fn ($c) => HtmlTextNormalizer::normalize($c->text()));
        $headers = $rows->eq(0)->filter('td')->each(fn ($c) => HtmlTextNormalizer::normalize($c->text()));
        $retired = $headers === ['氏名', '府県', '年齢', '期別', '級班', '登録番号'];
        if ($retired) {
            $identity = (new RetiredPlayerDetailParser)->parse($html, 'https://keirin.jp/pc/racerprofile', $externalId);
            if ((new RaceCategoryPolicy)->classify($basic[4] ?? null) !== RaceCategory::Men) {
                throw new RuntimeException('SKIPPED_UNSUPPORTED_CATEGORY');
            }
            $periodText = $identity->graduationPeriod;
        } else {
            if (($basic[6] ?? null) !== $externalId) {
                throw new RuntimeException('School profile player mismatch.');
            }
            if (! in_array($basic[5] ?? null, ['男', '男子'], true)) {
                throw new RuntimeException('SKIPPED_UNSUPPORTED_CATEGORY');
            }
            $periodText = $rows->eq(3)->filter('td')->eq(0)->text();
        }
        $school = $page->filterXPath('//p[normalize-space(.)="■競輪学校時代"]/following-sibling::table[1]');
        $fields = [];
        if ($school->count() > 1) {
            throw new RuntimeException('Ambiguous school profile section.');
        }
        if ($school->count() === 1) {
            foreach (['競輪学校順位', '200mタイム', '受験区分'] as $label) {
                $nodes = $school->filterXPath('.//tr[td[normalize-space(.)="'.$label.'"]]');
                if ($nodes->count() !== 1) {
                    $fields[$label] = null;

                    continue;
                }
                $headers = $nodes->filter('td')->each(fn ($c) => HtmlTextNormalizer::normalize($c->text()));
                $index = array_search($label, $headers, true);
                $values = $nodes->nextAll()->filter('tr')->first()->filter('td');
                $fields[$label] = $index !== false && $values->count() > $index ? HtmlTextNormalizer::normalize($values->eq($index)->text()) : null;
            }
        }
        $rankText = mb_convert_kana($fields['競輪学校順位'] ?? '', 'n');
        $rank = preg_match('/^([1-9][0-9]*)位$/uD', $rankText, $match) === 1 ? (int) $match[1] : null;
        $timeText = mb_convert_kana($fields['200mタイム'] ?? '', 'n');
        $seconds = preg_match('/^([0-9]+)分([0-9]{1,2})秒([0-9]{2})$/uD', $timeText, $match) === 1
            && (int) $match[2] < 60 ? (int) $match[1] * 60 + (int) $match[2] + (int) $match[3] / 100 : null;
        if ($seconds !== null && $seconds <= 0) {
            $seconds = null;
        }
        $debut = $page->filterXPath('//p[normalize-space(.)="■デビュー"]/following-sibling::table[1]//tr[2]/td[1]');
        $debutText = $debut->count() === 1 ? HtmlTextNormalizer::normalize($debut->text()) : null;
        $date = $debutText === null ? false : DateTimeImmutable::createFromFormat('!Y/m/d', $debutText, new DateTimeZone('Asia/Tokyo'));
        $date = $date !== false && $date->format('Y/m/d') === $debutText ? $date : null;

        $period = preg_match('/^([1-9][0-9]*)(?:期)?$/uD', mb_convert_kana($periodText ?? '', 'n'), $match) === 1 ? (int) $match[1] : null;

        return new SchoolProfileDto($externalId, $period,
            $rank, $seconds, $date, $fields + ['debut_date_raw' => $debutText, 'profile_format' => $retired ? 'RETIRED_HEADER_VERIFIED' : 'ACTIVE_HEADER_VERIFIED']);
    }
}
