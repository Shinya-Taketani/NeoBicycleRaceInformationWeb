<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\SavedWeather;

use RuntimeException;
use Throwable;

final class Derive
{
    public function __construct(private readonly LegacyInput $legacy, private readonly Snapshot $snapshot, private readonly Repository $repository) {}

    public function run(string $output): array
    {
        $old = $this->legacy->load();
        $code = Files::code();
        Files::directory($output);
        try {
            $m = $old['input'];
            $db = Files::sqlite($m['sqlite']['path']);
            $parent = Files::sqlite($m['original_sqlite']['path']);
            $audit = $this->snapshot->scope($db, $old['snapshot'], $output.'/scope.jsonl');
            $states = $audit['tables']['race_results'];
            if ($states['EXPLICIT_NULL'] !== 0 || $states['NOT_RECORDED'] !== 0) {
                throw new RuntimeException('OFFLINE_DERIVATION_REQUIRES_ALL_A');
            }
            $h = Files::create($output.'/candidates.jsonl');
            try {
                foreach ($this->repository->inventory($db, $parent, $output.'/scope.jsonl', $m['raw_roots']) as $group) {
                    Files::append($h, $group);
                }
            } finally {
                fclose($h);
            }
            Files::write($output.'/source-schema-audit.json', $audit);
            Files::write($output.'/historical-code.json', $old['historical_code']);
            Files::write($output.'/offline-derivation.json', ['old_input' => $old['reference'], 'data_seals' => $old['data_seals'],
                'row_audit' => $old['row_audit'], 'policy' => 'SOURCE_IDENTITY_AND_RESULT_STRUCTURE_WITHOUT_METHOD_SEMANTICS',
                'source_connections' => 0, 'HTTP' => 0, 'sqlite_copies' => 0, 'old_input_writes' => 0]);
            $files = [];
            foreach (glob($output.'/*') as $path) {
                $files[basename($path)] = Files::seal($path);
            }
            foreach (array_merge($old['data_seals'], $code) as $seal) {
                Files::verify($seal);
            }
            $input = ['contract' => Files::VERSION.'-INPUT', 'parent' => $m['parent'], 'original_sqlite' => $m['original_sqlite'],
                'sqlite' => $m['sqlite'], 'scope' => $audit['scope'], 'races' => $audit['races'], 'files' => $files,
                'raw_roots' => $m['raw_roots'], 'code' => $code, 'historical_input' => $old['reference'],
                'extraction' => ['connections' => 0, 'writes' => 0, 'mode' => 'REVIEWED_ALL_A_OFFLINE_DERIVATION'],
                'acceptance_policy' => 'SOURCE_IDENTITY_AND_RESULT_STRUCTURE_WITHOUT_METHOD_SEMANTICS',
                'historical_as_of_available' => false, 'prediction_use' => 'NOT_AUTHORIZED'];
            Files::write($output.'/manifest.json', $input);
            Files::write($output.'/COMPLETE.json', ['manifest' => Files::seal($output.'/manifest.json')]);

            return $input;
        } catch (Throwable $e) {
            Files::write($output.'/FAILED.json', ['exception' => $e::class, 'exit_code' => 1]);
            throw $e;
        }
    }
}
