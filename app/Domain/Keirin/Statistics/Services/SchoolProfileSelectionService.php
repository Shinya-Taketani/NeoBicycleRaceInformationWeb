<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Services;

use App\Domain\Keirin\Statistics\DTO\SchoolProfileIndexOptionsDto;
use App\Domain\Keirin\Statistics\Support\AcquiredHistoryTimestamp;
use App\Domain\Keirin\Statistics\Support\SchoolProfiles\Files;
use App\Domain\Keirin\Statistics\Support\SchoolProfiles\Projection;
use RuntimeException;

final class SchoolProfileSelectionService
{
    public const VERSION = 'SCHOOL-PROFILE-STAT44-SELECTION-v1';

    public function __construct(private readonly SchoolProfileCatalogService $catalogs) {}

    public function build(string $manifest, string $sha, string $output, bool $dryRun = false): array
    {
        $catalog = $this->catalogs->load($manifest, $sha);
        $plan = $this->plan($catalog, Files::seal($manifest));
        if (! $dryRun) {
            Files::write($output, Files::json($plan));
        }

        return $plan;
    }

    public function load(SchoolProfileIndexOptionsDto $options): array
    {
        $plan = Files::read($options->path, $options->sha256);
        if (($plan['contract'] ?? null) !== self::VERSION || ($plan['scope'] ?? null) !== 'STAT44_DEBUT_ONLY') {
            throw new RuntimeException('Unknown school STAT44 selection contract.');
        }
        $ref = $plan['catalog'] ?? [];
        $catalog = $this->catalogs->load(Files::verify($ref), $ref['sha256']);
        // Validate the whole candidate set before trusting any selected member.
        if ($plan !== $this->plan($catalog, $ref)) {
            throw new RuntimeException('School selection identity, seal or fixed-rule mismatch.');
        }
        if (Files::seal($options->path)['sha256'] !== $options->sha256) {
            throw new RuntimeException('School selection changed during loading.');
        }

        return ['plan' => $plan, 'catalog' => $catalog, 'metadata' => ['catalog_contract' => SchoolProfileCatalogService::VERSION,
            'catalog_sha256' => $ref['sha256'], 'selection_contract' => self::VERSION, 'selection_sha256' => $options->sha256,
            'scope' => 'STAT44_DEBUT_ONLY']];
    }

    public function profile(array $loaded, string $externalId): array
    {
        $choice = $loaded['plan']['players'][$externalId] ?? null;
        if ($choice === null) {
            return ['profile' => null, 'reason' => 'NO_CANDIDATE'];
        }
        if ($choice['selected'] === null) {
            return ['profile' => null, 'reason' => $choice['reason']];
        }
        $s = $choice['selected'];
        $record = $this->catalogs->record($loaded['catalog'], $s['fetch_log_id']);
        if ($record['external_player_id'] !== $externalId || $record['projection_sha256'] !== $s['projection_sha256']
            || $record['projection']['source']['sha256'] !== $s['raw_sha256']) {
            throw new RuntimeException('Selected school player/fetch-log/projection mismatch.');
        }

        return ['profile' => $record['projection'], 'reason' => 'CONNECTED'];
    }

    private function plan(array $catalog, array $catalogRef): array
    {
        $players = [];
        $summary = ['versions' => 0, 'players' => 0, 'selected' => 0, 'unselected' => 0, 'unselected_reasons' => [],
            'field_states' => [], 'players_with_field_conflicts' => [], 'graduation_period_raw_not_recorded' => 0];
        foreach ($catalog['index']['players'] as $externalId => $ids) {
            $candidates = $fields = $valid = [];
            $invalid = false;
            foreach ($ids as $id) {
                $r = $this->catalogs->record($catalog, $id);
                $p = $r['projection'];
                if ($p['external_player_id'] !== (string) $externalId) {
                    throw new RuntimeException('School catalog player index mismatch.');
                }
                $instant = AcquiredHistoryTimestamp::parse($p['source']['fetched_at']);
                $state = Projection::state($p['debut_date'], $p['original_fields']['debut_date_raw'] ?? null);
                $candidate = ['fetch_log_id' => $id, 'debut_date' => $p['debut_date'], 'debut_state' => $state,
                    'fetched_at' => $p['source']['fetched_at'], 'raw_sha256' => $p['source']['sha256'],
                    'projection_sha256' => $r['projection_sha256']];
                $candidates[] = $candidate;
                $summary['versions']++;
                foreach (Projection::fields($p) as $field => $value) {
                    $fieldState = Projection::state($value['value'], $value['raw'] ?? null);
                    $summary['field_states'][$field][$fieldState] = ($summary['field_states'][$field][$fieldState] ?? 0) + 1;
                    if ($value['value'] !== null) {
                        $fields[$field]['known_values'][serialize($value['value'])] = $value['value'];
                    }
                    if (array_key_exists('raw', $value)) {
                        $fields[$field]['original_variants'][serialize($value['raw'])] = $value['raw'];
                    }
                }
                $summary['graduation_period_raw_not_recorded']++;
                $invalid = $invalid || $state === 'INVALID';
                if ($state === 'VALID') {
                    $valid[] = ['instant' => $instant, 'candidate' => $candidate];
                }
            }
            $conflicts = [];
            foreach ($fields as $field => $values) {
                if (count($values['known_values'] ?? []) > 1) {
                    $conflicts[$field] = array_values($values['known_values']);
                    $summary['players_with_field_conflicts'][$field] = ($summary['players_with_field_conflicts'][$field] ?? 0) + 1;
                }
            }
            $selected = null;
            if (isset($conflicts['debut_date'])) {
                $reason = 'DEBUT_DATE_CONFLICT';
            } elseif ($invalid) {
                $reason = 'INVALID_DEBUT_DATE';
            } elseif ($valid === []) {
                $reason = 'MISSING_DEBUT_DATE';
            } else {
                usort($valid, fn ($a, $b) => [$a['instant'], $a['candidate']['fetch_log_id']] <=> [$b['instant'], $b['candidate']['fetch_log_id']]);
                $selected = $valid[0]['candidate'];
                $reason = count($valid) === 1 ? 'SINGLE_VALID_DEBUT' : 'CONSISTENT_DEBUT_EARLIEST_FETCH_THEN_ID';
            }
            $summary[$selected === null ? 'unselected' : 'selected']++;
            $summary['players']++;
            if ($selected === null) {
                $summary['unselected_reasons'][$reason] = ($summary['unselected_reasons'][$reason] ?? 0) + 1;
            }
            $players[(string) $externalId] = ['selected' => $selected, 'reason' => $reason, 'candidates' => $candidates,
                'field_conflicts' => $conflicts, 'valid_and_invalid_debut_mixed' => $invalid && $valid !== [],
                'graduation_period_raw_status' => 'NOT_RECORDED_IN_PROJECTION_V1'];
        }

        return ['contract' => self::VERSION, 'scope' => 'STAT44_DEBUT_ONLY', 'catalog' => $catalogRef,
            'selection_rule' => 'VALIDATE_ALL_THEN_SAME_DEBUT_EARLIEST_INSTANT_THEN_FETCH_LOG_ID',
            'summary' => $summary, 'players' => $players, 'historical_as_of_available' => false,
            'prediction_use' => 'NOT_AUTHORIZED', 'points' => null, 'confidence' => null];
    }
}
