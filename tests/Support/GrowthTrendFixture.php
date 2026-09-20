<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Keirin\Backtest\Experiments\GrowthTrendAnalysis\Sources;
use App\Domain\Keirin\Backtest\Experiments\GrowthTrendScoreSource\Bundle;
use App\Domain\Keirin\Backtest\Experiments\GrowthTrendScoreSource\OuterSource;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

trait GrowthTrendFixture
{
    private string $root;

    private array $outer;

    private array $labels = [];

    private function fixture(): void
    {
        $this->root = sys_get_temp_dir().'/growth-trend-test-'.bin2hex(random_bytes(8));
        mkdir($this->root);
        foreach (['outer', 'score', 'analysis', 'meeting'] as $dir) {
            mkdir($this->root.'/'.$dir);
        }
        config(['tactical_prediction_pipeline.artifact_base' => $this->root, 'database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Schema::create('races', function (Blueprint $t): void {
            $t->integer('id')->primary();
            $t->date('race_date');
            $t->string('scheduled_start_at')->nullable();
            $t->integer('race_day_id')->nullable();
            $t->string('race_type');
        });
        Schema::create('race_days', function (Blueprint $t): void {
            $t->integer('id')->primary();
            $t->date('race_date');
            $t->integer('race_meeting_id');
        });
        Schema::create('race_meetings', function (Blueprint $t): void {
            $t->integer('id')->primary();
            $t->date('starts_on')->nullable();
            $t->date('ends_on');
            $t->string('grade');
        });
        Schema::create('race_entries', function (Blueprint $t): void {
            $t->integer('id')->primary();
            $t->integer('race_id');
            $t->integer('player_id')->nullable();
            $t->integer('bike_number');
            $t->string('race_score')->nullable();
            $t->string('fetched_at');
        });
        $metadata = $meetings = $seals = $projection = [];
        foreach ([2024, 2025] as $year) {
            $race = $year;
            $date = $year.'-06-15';
            $this->dbRace($race, $date, $race, 100.0);
            $entries = $predicted = $labels = [];
            for ($bike = 1; $bike <= 7; $bike++) {
                $id = $race * 10 + $bike;
                $raw = 100.0 + $bike;
                $entries[] = ['id' => $id, 'bike' => $bike, 'raw' => $raw, 'stat01_rank' => 8 - $bike, 'anchor' => 0.0,
                    'anchor_status' => 'AVAILABLE', 'signals' => array_fill(0, 12, 0.0), 'history' => [1, 2, 3, 4], 'history_status' => 'AVAILABLE'];
                $predicted[] = ['id' => $id, 'bike' => $bike, 'raw' => $raw, 'position_1_probability' => $bike / 28.0];
                $labels[] = ['id' => $id, 'bike' => $bike, 'raw' => $raw, 'rank' => 8 - $bike, 'status' => 'FINISHED'];
            }
            $paths = [];
            foreach (['input' => ['year' => $year, 'race_id' => $race, 'entries' => $entries],
                'prediction' => ['probabilities' => ['year' => $year, 'race_id' => $race, 'entries' => $predicted],
                    'decision' => ['year' => $year, 'race_id' => $race, 'primary_position_1_bike' => 7]],
                'labels' => ['year' => $year, 'race_id' => $race, 'entries' => $labels]] as $kind => $value) {
                $relative = match ($kind) {
                    'input' => 'inputs-v2/inputs-'.$year.'.jsonl',
                    'prediction' => 'run-01/C1-fit-'.$year.'/predictions.jsonl',
                    'labels' => 'run-01/labels-'.$year.'.jsonl',
                };
                $path = $this->root.'/outer/'.$relative;
                if (! is_dir(dirname($path))) {
                    mkdir(dirname($path), 0755, true);
                }
                JsonlArtifact::write($path, [$value]);
                $paths[$kind] = $path;
                foreach ([$relative, $relative.'.manifest.json'] as $name) {
                    $seals[$name] = Files::identity($this->root.'/outer/'.$name);
                    if ($kind !== 'labels') {
                        $projection[$name] = $seals[$name];
                    }
                }
            }
            $this->labels[$year] = $paths['labels'];
            $metadata[] = ['year' => $year, 'race_id' => $race, 'race_date' => $date, 'entrant_count' => 7, 'meeting_id' => $race, 'race_type_raw' => 'A級予選'];
            $meetings[$race] = ['grade' => 'F2'];
            for ($i = 1; $i <= 12; $i++) {
                $prior = $year * 100 + $i;
                $d = (new \DateTimeImmutable($date))->modify('-'.($i * 15).' days')->format('Y-m-d');
                $this->dbRace($prior, $d, $prior, 100.0 - $i);
            }
        }
        JsonlArtifact::write($this->root.'/meeting/metadata.jsonl', $metadata);
        JsonlArtifact::json($this->root.'/meeting/meetings.json', $meetings);
        JsonlArtifact::json($this->root.'/outer/report-export-manifest.json', ['included' => $seals]);
        $hash = hash('sha256', Files::canonical(['run' => 'run-01', 'files' => $projection]));
        $this->app->instance(OuterSource::class, new class($hash, $projection) extends OuterSource
        {
            public function __construct(private readonly string $hash, private readonly array $files) {}

            protected function fixedFiles(): array
            {
                return $this->files;
            }

            protected function projectionHash(): string
            {
                return $this->hash;
            }

            public function openOutcomeFree(string $root): array
            {
                $source = parent::openOutcomeFree($root);
                $source['counts'] = [2024 => 1, 2025 => 1];
                $source['entries'] = [2024 => 7, 2025 => 7];

                return $source;
            }
        });
        $this->outer = app(OuterSource::class)->openOutcomeFree($this->root.'/outer');
        $files = [];
        foreach (['metadata.jsonl', 'metadata.jsonl.manifest.json', 'meetings.json'] as $name) {
            $files[$name] = Files::identity($this->root.'/meeting/'.$name);
        }
        JsonlArtifact::json($this->root.'/meeting/manifest.json', ['files' => $files]);
        $hash = hash('sha256', Files::canonical($files));
        $this->app->instance(Sources::class, new class(app(OuterSource::class), app(Bundle::class), $hash, $files) extends Sources
        {
            public function __construct(OuterSource $outer, Bundle $bundle, private readonly string $hash, private readonly array $files)
            {
                parent::__construct($outer, $bundle);
            }

            protected function meetingProjectionHash(): string
            {
                return $this->hash;
            }

            protected function meetingFiles(): array
            {
                return $this->files;
            }
        });
    }

    private function dbRace(int $id, string $date, int $meeting, float $score): void
    {
        DB::table('race_meetings')->insert(['id' => $meeting, 'starts_on' => $date, 'ends_on' => $date, 'grade' => 'F2']);
        DB::table('race_days')->insert(['id' => $id, 'race_date' => $date, 'race_meeting_id' => $meeting]);
        DB::table('races')->insert(['id' => $id, 'race_date' => $date, 'scheduled_start_at' => $date.' 12:00:00+09:00', 'race_day_id' => $id, 'race_type' => "\u{ff21}級予選"]);
        for ($bike = 1; $bike <= 7; $bike++) {
            DB::table('race_entries')->insert(['id' => $id * 10 + $bike, 'race_id' => $id, 'player_id' => $bike, 'bike_number' => $bike,
                'race_score' => sprintf('%.2f', $score + $bike * ($id > 100000 ? 0.5 : 1)), 'fetched_at' => '2026-09-19 01:00:00']);
        }
    }

    private function cleanupFixture(): void
    {
        DB::purge('sqlite');
        if (isset($this->root)) {
            File::deleteDirectory($this->root);
        }
    }
}
