<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\DTO\Bt03eBinRuleDto;
use App\Domain\Keirin\Backtest\DTO\Bt03eCandidateDto;
use App\Domain\Keirin\Backtest\Services\Bt03eArtifactWriter;
use App\Domain\Keirin\Backtest\Services\Bt03eContract;
use Tests\TestCase;

class Bt03eArtifactWriterTest extends TestCase
{
    public function test_json_and_csv_preserve_full_rule_identity_and_integer_points(): void
    {
        $directory = sys_get_temp_dir().'/bt03e-artifact-test-'.bin2hex(random_bytes(6));
        $weights = array_fill_keys(Bt03eContract::STAT_CODES, 0);
        $weights['STAT-07'] = 10;
        $candidate = new Bt03eCandidateDto(20, $weights);
        $rule = new Bt03eBinRuleDto(
            'STAT-07', 1, 'TRAINING_BIN', 'NUMERIC_RANGE', null, 0.5, null,
            71, str_repeat('a', 64), 100, -2,
        );
        $summary = ['point_rules' => [[...$rule->canonical(), 'stat_weight' => 10, 'final_points' => -20, 'source_fold' => 'WF_2023']]];

        try {
            $paths = (new Bt03eArtifactWriter)->write($directory, $summary, [$rule], $candidate);
            $json = json_decode((string) file_get_contents($paths['json']), true, flags: JSON_THROW_ON_ERROR);
            $csv = (string) file_get_contents($paths['csv']);

            $this->assertSame('TRAINING_BIN', $json['point_rules'][0]['bin_origin']);
            $this->assertSame(-20, $json['point_rules'][0]['final_points']);
            $this->assertStringContainsString('bin_origin', $csv);
            $this->assertStringContainsString('STAT-01,,BASE_RANK,BASE_RANK', $csv);
            $this->assertStringContainsString('STAT-07,1,TRAINING_BIN,NUMERIC_RANGE', $csv);
            $this->assertStringContainsString(',-2,10,-20,WF_2023,71,', $csv);
        } finally {
            if (is_dir($directory)) {
                foreach (glob($directory.'/*') ?: [] as $path) {
                    unlink($path);
                }
                rmdir($directory);
            }
        }
    }
}
