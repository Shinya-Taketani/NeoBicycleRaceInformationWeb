<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\StartCountSnapshot;

use App\Domain\Keirin\Audit\Stat35DataReadiness\RawReader;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Statistics\AgariRaceRelative\Artifacts;
use App\Domain\Keirin\Statistics\StartObservation\Ledger;
use Symfony\Component\DomCrawler\Crawler;

final class Inspector
{
    public function __construct(private readonly Ledger $ledger, private readonly RawReader $raw, private readonly Parser $parser) {}

    public function inspect(string $source, string $output): array
    {
        $manifest = Bundle::verify($source, 'SOURCE');
        Artifacts::create($output);
        $index = new Index($output.'/index.sqlite');
        $index->load($source, $manifest, $this->ledger);
        $selected = [];
        $years = array_fill_keys(Contract::YEARS, 0);
        foreach (Artifacts::lines($source.'/targets.jsonl') as $target) {
            $year = (int) substr($target['race']['race_date'], 0, 4);
            if ($years[$year]++ < 2) {
                $selected[$target['race']['race_id']] = $target;
            }
        }
        $reports = [];
        foreach (Artifacts::lines($source.'/sources.jsonl') as $fetch) {
            // Inventory coverage is collected by fixed target below; no Raw-wide probe.
            if (! isset($selected[$fetch['race_id']]) || isset($reports[$fetch['race_id']])) {
                continue;
            }
            $index->candidate($fetch);
            $target = $selected[$fetch['race_id']];
            $html = $this->raw->read(Builder::rawSource($manifest, $fetch, $target['race']));
            $page = $this->parser->parse($html, $target);
            $headers = (new Crawler($html))->filter('th')->each(static fn (Crawler $c) => trim($c->text()));
            $reports[$fetch['race_id']] = ['target' => $target, 'fetch_log_id' => $fetch['id'],
                'original_sha256' => $fetch['sha256'], 'converted_sha256' => hash('sha256', $html),
                'fetched_at' => $fetch['fetched_at'], 'headers' => $headers,
                'pc_context_keys' => array_keys(get_object_vars(PageJson::extract($html, 'PC0201')->C0201data ?? new \stdClass)),
                'pj_keys' => array_keys(get_object_vars(PageJson::extract($html, 'PJ0315'))),
                'parsed' => $page];
        }
        foreach ($selected as $id => $target) {
            $reports[$id] ??= ['target' => $target, 'status' => 'NO_PJ0315_CANDIDATE'];
        }
        ksort($reports, SORT_NUMERIC);
        $files = ['samples.json' => Artifacts::json($output, 'samples.json', ['rule' => Contract::plan()['sample'], 'races' => $reports])];
        Bundle::verify($source, 'SOURCE');
        Artifacts::publish($output, ['version' => Contract::VERSION, 'kind' => 'PREINSPECTION',
            'source' => Files::identity($source.'/manifest.json'), 'files' => $files]);
        unset($index);
        unlink($output.'/index.sqlite');

        return ['path' => $output, 'samples' => count($reports),
            'missing' => count(array_filter($reports, static fn ($r) => isset($r['status'])))];
    }
}
