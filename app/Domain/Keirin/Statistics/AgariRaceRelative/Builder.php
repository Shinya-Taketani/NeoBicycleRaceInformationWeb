<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\AgariRaceRelative;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalMeetingGradeAnalysis\Classification;
use App\Domain\Keirin\TrackContext\TrackContextMaster;
use Generator;
use RuntimeException;

final class Builder
{
    public function __construct(private readonly Calculator $calculator, private readonly Classification $classification) {}

    public function build(string $input, string $version, string $output): array
    {
        if ($version !== 'v2') {
            throw new RuntimeException('This calculation contract requires explicit master v2.');
        }
        $manifest = Artifacts::input($input);
        $code = Artifacts::code();
        $masterDirectory = resource_path('data/keirin/track-context/'.$version);
        $master = TrackContextMaster::load($masterDirectory, $version);
        Artifacts::create($output);
        $summary = new Summary;
        $files = ['details.jsonl' => Artifacts::write($output, 'details.jsonl', $this->lines($input, $manifest, $master, $summary))];
        $data = $summary->data();
        if ($data['totals']['races'] !== $manifest['race_count'] || $data['totals']['current_result_rows'] !== $manifest['result_count']) {
            throw new RuntimeException('Input row counts do not match manifest.');
        }
        $files['summary.json'] = Artifacts::json($output, 'summary.json', $data);
        $files['summary.csv'] = Artifacts::write($output, 'summary.csv', $summary->csv());
        Files::same($manifest, Artifacts::input($input), 'sealed input start/end');
        Files::same($code, Artifacts::code(), 'build code start/end');
        if ($master->manifestSha256 !== TrackContextMaster::load($masterDirectory, $version)->manifestSha256) {
            throw new RuntimeException('Master changed during build.');
        }
        Artifacts::publish($output, [...Contract::DISCLOSURE, 'kind' => 'RESULT', 'version' => Contract::VERSION,
            'input_manifest' => Files::identity($input.'/manifest.json'), 'master_version' => $version,
            'master_sha256' => $master->manifestSha256, 'code' => $code, 'files' => $files]);

        return $data;
    }

    private function lines(string $input, array $manifest, TrackContextMaster $master, Summary $summary): Generator
    {
        $last = 0;
        foreach (Artifacts::lines($input.'/races.jsonl') as $race) {
            Contract::race($race, $manifest['from'], $manifest['to'], $last);
            $last = $race['race_id'];
            $context = $race['context'];
            if (! is_array($context['meeting_race_grade_raw_values'] ?? null) || $context['meeting_race_grade_raw_values'] === []
                || ! in_array($context['race_grade_raw'] ?? null, $context['meeting_race_grade_raw_values'], true)) {
                throw new RuntimeException('Missing/inconsistent meeting header inventory.');
            }
            $headers = [];
            foreach ($context['meeting_race_grade_raw_values'] as $grade) {
                $headers[] = ['race_id' => $race['race_id'], ...$context, 'race_grade_raw' => $grade];
            }
            $meeting = array_values($this->classification->meetings($headers))[0];
            $classification = ['year' => (int) substr($race['race_date'], 0, 4), 'meeting_id' => $context['meeting_id'],
                'meeting_grade' => $meeting['grade'], 'meeting_grade_reason' => $meeting['reason'],
                'meeting_grade_raw' => $context['meeting_grade_raw'], 'race_grade_raw' => $context['race_grade_raw'],
                'meeting_race_grade_raw_values' => $context['meeting_race_grade_raw_values'],
                'track_code' => $context['track_code'], 'race_class' => Classification::raceClass($race['race_type'])];
            if (($context['day_date'] !== null && $context['day_date'] !== $race['race_date'])
                || ($context['meeting_id'] !== null && ($context['meeting_track_id'] !== $context['racetrack_id']
                    || $race['race_date'] < $context['starts_on'] || $race['race_date'] > $context['ends_on']))) {
                $classification['meeting_grade'] = 'UNKNOWN';
                $classification['meeting_grade_reason'] = 'MEETING_RELATION_CONFLICT';
            }
            $result = $this->calculator->calculate($race, $master);
            $result['classification'] = $classification;
            $summary->add($result);
            yield Files::canonical($result)."\n";
        }
    }
}
