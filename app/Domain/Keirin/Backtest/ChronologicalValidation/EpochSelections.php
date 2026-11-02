<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\ChronologicalValidation;

use Closure;

/** Append-only selection events within validation_run, not a mutable latest pointer. */
final class EpochSelections
{
    public static function chain(Store $store, string $day): array
    {
        $events = [];
        $parent = null;
        for ($n = 1; $n <= 256; $n++) {
            $id = self::id($day, $n);
            if (! $store->has($id)) {
                return $events;
            }
            $ref = $store->reference($id);
            $event = $store->get($ref)['data'];
            BundleRules::require($event['event'] === 'EPOCH_SELECTION' && $event['selection_day'] === $day && $event['selection_version'] === $n
                && $event['parent_selection'] === $parent && $event['header'] === $store->reference('run'), 'Invalid explicit epoch selection chain.');
            self::step($store, $event['base_epoch'], $event['selected_epoch'], $event['updated_result'], $day);
            if ($parent !== null) {
                BundleRules::require($event['base_epoch'] === $events[array_key_last($events)]['data']['selected_epoch'], 'Unrelated epoch selection parent.');
            }
            $events[] = ['ref' => $ref, 'data' => $event];
            $parent = $ref;
        }
        throw new \RuntimeException('Bounded epoch selection capacity exceeded.');
    }

    public static function resolve(Store $store, array $base, string $day): array
    {
        foreach (self::chain($store, $day) as $event) {
            BundleRules::require($event['data']['base_epoch'] === $base, 'Restart epoch drift: different selection lineage.');
            $base = $event['data']['selected_epoch'];
        }

        return $base;
    }

    public static function append(Store $store, array $parent, array $result, string $day, string $now, Closure $checkpoint): array
    {
        $run = $store->get($store->reference('run'))['data'];
        (new Scope(...$run['scope']))->target($day);
        BundleRules::require(! $store->has('d'.str_replace('-', '', $day).'-candidates'), 'Correction is not an unprocessed future version.');
        $events = self::chain($store, $day);
        foreach ($events as $event) {
            if ($event['data']['base_epoch'] === $parent && $event['data']['updated_result'] === $result) {
                return $event['data']['selected_epoch'];
            }
        }
        if ($events !== []) {
            $expected = $events[array_key_last($events)]['data']['selected_epoch'];
        } else {
            $expected = $run['initial_history'];
            $days = array_unique(array_column($run['candidate_scope'], 'race_date'));
            sort($days);
            foreach ($days as $priorDay) {
                if ($priorDay >= $day) {
                    break;
                }
                $id = 'd'.str_replace('-', '', $priorDay).'-next-epoch';
                BundleRules::require($store->has($id), 'Correction base requires preceding completed day.');
                $expected = $store->reference($id);
            }
        }
        BundleRules::require($parent === $expected, 'Unrelated epoch selection parent.');
        $previous = $store->get($parent)['data'];
        $changed = $store->get($result)['data'];
        BundleRules::require($changed['race_date'] < $day && in_array($changed['parent_result'], $previous['results'], true), 'Correction parent missing from fixed epoch.');
        $id = 'epoch-update-'.substr(Json::hash([$parent, $result, $day]), 0, 40);
        $created = $store->has($id) ? $store->get($store->reference($id))['data']['created_at'] : $now;
        $epoch = [...$previous, 'epoch_id' => $id, 'parent_epoch' => $parent, 'logical_available_from' => $day,
            'results' => ResultVersions::adopt($store, $previous['results'], [$result]), 'created_at' => $created];
        $selected = $store->put($id, 'history_epoch', $epoch);
        $checkpoint('AFTER_CORRECTION_EPOCH');
        $n = count($events) + 1;
        $event = [...$run, 'event' => 'EPOCH_SELECTION', 'header' => $store->reference('run'), 'selection_day' => $day, 'selection_version' => $n,
            'parent_selection' => $events === [] ? null : $events[array_key_last($events)]['ref'], 'base_epoch' => $parent, 'selected_epoch' => $selected, 'updated_result' => $result];
        $store->put(self::id($day, $n), 'validation_run', $event);
        $checkpoint('AFTER_CORRECTION_SELECTION');

        return $selected;
    }

    public static function step(Store $store, array $base, array $selected, array $result, string $day): void
    {
        $old = $store->get($base)['data'];
        $new = $store->get($selected)['data'];
        $r = $store->get($result)['data'];
        BundleRules::require($new['parent_epoch'] === $base && $new['logical_available_from'] === $day && $r['race_date'] < $day
            && in_array($r['parent_result'], $old['results'], true)
            && Json::hash($new['results']) === Json::hash(ResultVersions::adopt($store, $old['results'], [$result])), 'Invalid adopted result version in epoch lineage.');
        foreach (['sources', 'inputs', 'history_event_from', 'history_event_end_exclusive'] as $key) {
            BundleRules::require(Json::hash($old[$key]) === Json::hash($new[$key]), 'Correction changed fixed history scope.');
        }
    }

    private static function id(string $day, int $version): string
    {
        Scope::day($day);

        return 'd'.str_replace('-', '', $day).'-epoch-selection-'.$version;
    }
}
