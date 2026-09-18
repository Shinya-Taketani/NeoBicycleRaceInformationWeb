<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\TacticalMeetingGradeAnalysis;

use App\Domain\Keirin\Backtest\Experiments\TacticalGradeAnalysis\AnalysisStore;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use Generator;
use RuntimeException;

class Sources
{
    public function __construct(private readonly AnalysisStore $oldStore) {}

    public function open(string $bundle): array
    {
        $manifest = $this->oldStore->verify($bundle);
        if (Files::identity($bundle.'/manifest.json')['sha256'] !== 'f2c796b179d195e2b1f7e66dcc834619942889abce11a8f982ca903aa5a4e77c') {
            throw new RuntimeException('Expected reviewed 50078-race rider analysis bundle.');
        }
        $source = Files::json($bundle.'/sources.json');
        $files = $source['files'];
        foreach ($manifest['files'] as $file => $seal) {
            $files[$bundle.'/'.$file] = $seal;
        }
        foreach (['manifest.json', 'LOCKED.json'] as $file) {
            $files[$bundle.'/'.$file] = Files::identity($bundle.'/'.$file);
        }
        $opened = ['bundle' => $bundle, 'input' => $bundle.'/analysis-input.jsonl', 'years' => $source['years'],
            'files' => $files, 'reference' => $source['reference'], 'expected_counts' => $source['expected_counts']];
        $this->verify($opened);

        return $opened;
    }

    public function verify(array $source): void
    {
        foreach ($source['files'] as $path => $seal) {
            Files::verify($path, $seal);
        }
    }

    public function rows(array $source, string $metadata): Generator
    {
        $meta = JsonlArtifact::read($metadata);
        $meta->rewind();
        $streams = [];
        foreach ([2024, 2025] as $year) {
            $streams[$year] = JsonlArtifact::read($source['years'][$year]['contributions']);
            $streams[$year]->rewind();
        }
        $seen = $counts = [];
        foreach (JsonlArtifact::read($source['input']) as $old) {
            $context = $old['context'];
            $year = $context['year'];
            if (! in_array($year, [2024, 2025], true) || isset($seen[$context['race_id']])) {
                throw new RuntimeException('Duplicate race or forbidden year.');
            }
            $seen[$context['race_id']] = true;
            $counts[$year] = ($counts[$year] ?? 0) + 1;
            $attributes = $meta->current();
            $saved = $streams[$year]->current();
            if (! $meta->valid() || ! $streams[$year]->valid() || $attributes['race_id'] !== $context['race_id']
                || $saved['race_id'] !== $context['race_id'] || $attributes['race_date'] !== $old['race_date']
                || $attributes['entrant_count'] !== $old['metadata']['entrant_count']
                || $attributes['race_type_raw'] !== $old['metadata']['race_type_raw']) {
                throw new RuntimeException('Saved source/metadata alignment mismatch.');
            }
            $values = [];
            foreach (Contract::METRICS as $key => $metric) {
                $values[$key] = $saved['C1-STAT01']['candidate'][$metric];
                if ($key !== 'H3') {
                    Files::same($old['saved_contributions'][(int) substr($key, 1)], $values[$key], 'saved rider contribution');
                }
            }
            yield ['context' => $context, 'decision' => $old['decision'], 'metadata' => $attributes, 'saved' => $values];
            $meta->next();
            $streams[$year]->next();
        }
        if ($meta->valid() || $streams[2024]->valid() || $streams[2025]->valid()) {
            throw new RuntimeException('Extra metadata/contribution rows.');
        }
        Files::same($source['expected_counts'], $counts, 'source year counts');
    }
}
