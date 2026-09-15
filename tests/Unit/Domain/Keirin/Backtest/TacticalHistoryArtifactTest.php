<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Contracts\Bt02OutcomeContextSnapshot;
use App\Domain\Keirin\Backtest\DTO\Bt02OutcomeContextRaceDto;
use App\Domain\Keirin\Backtest\DTO\FoldDefinitionDto;
use App\Domain\Keirin\Backtest\DTO\LabelResultDto;
use App\Domain\Keirin\Backtest\DTO\RaceContextDto;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Dataset;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class TacticalHistoryArtifactTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/tactical-history-test-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function test_atomic_publication_and_same_length_drift_rejection(): void
    {
        $path = $this->directory.'/rows.jsonl';
        $rows = [['race_id' => 3], ['race_id' => 1]];
        JsonlArtifact::write($path, $rows);
        $this->assertSame($rows, iterator_to_array(JsonlArtifact::read($path)));
        $this->assertFileDoesNotExist($path.'.partial');
        $content = file_get_contents($path);
        file_put_contents($path, str_replace('3', '4', $content));
        $this->expectException(RuntimeException::class);
        iterator_to_array(JsonlArtifact::read($path));
    }

    public function test_failed_producer_is_not_published(): void
    {
        $path = $this->directory.'/rows.jsonl';
        try {
            JsonlArtifact::write($path, (function (): \Generator {
                yield ['race_id' => 1];
                throw new RuntimeException('Synthetic source drift.');
            })());
            $this->fail('A source error must propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame('Synthetic source drift.', $e->getMessage());
            $this->assertFileDoesNotExist($path);
            $this->assertFileDoesNotExist($path.'.manifest.json');
            $this->assertFileExists($path.'.partial');
        }
    }

    public function test_prediction_seals_precede_label_access_and_keep_chronological_not_id_order(): void
    {
        $inputs = [];
        foreach ([90, 20] as $id) {
            $inputs[] = ['year' => 2024, 'race_id' => $id, 'entries' => array_map(fn ($bike) => ['id' => $id * 10 + $bike, 'bike' => $bike], range(1, 5))];
        }
        $input = $this->directory.'/inputs.jsonl';
        JsonlArtifact::write($input, $inputs);
        $snapshot = new class implements Bt02OutcomeContextSnapshot
        {
            public int $reads = 0;

            public function chunks(FoldDefinitionDto $fold, int $chunkSize): \Generator
            {
                $this->reads++;
                foreach ([90, 80, 20] as $id) {
                    yield [new Bt02OutcomeContextRaceDto(new RaceContextDto($id, new DateTimeImmutable('2024-06-01'), null, null, 5, 'CONFIRMED'), 'A',
                        array_map(fn ($bike) => new LabelResultDto($id, $bike, $bike, 'FINISHED'), range(1, 5)))];
                }
            }

            public function auditParameters(): array
            {
                return [];
            }

            public function manifestHash(): string
            {
                return str_repeat('a', 64);
            }
        };
        $dataset = new Dataset;
        try {
            $dataset->releaseLabels(2024, $input, [], $snapshot, $this->directory.'/bad.jsonl');
            $this->fail('Missing seals must be rejected.');
        } catch (RuntimeException) {
            $this->assertSame(0, $snapshot->reads);
        }
        $sealed = [];
        foreach (['C0', 'C1'] as $model) {
            $path = $this->directory.'/'.$model.'.jsonl';
            JsonlArtifact::write($path, array_map(fn ($r) => ['probabilities' => $r], $inputs));
            $sealed[] = $path;
        }
        $output = $this->directory.'/labelled.jsonl';
        $dataset->releaseLabels(2024, $input, $sealed, $snapshot, $output);
        $this->assertSame([90, 20], array_column(iterator_to_array(JsonlArtifact::read($output)), 'race_id'));
        $this->assertSame(1, $snapshot->reads);
    }

    public function test_2026_dataset_is_rejected(): void
    {
        $path = $this->directory.'/inputs.jsonl';
        JsonlArtifact::write($path, [['year' => 2026, 'race_id' => 1, 'entries' => []]]);
        $this->expectException(RuntimeException::class);
        iterator_to_array((new Dataset)->raw([$path], true));
    }
}
