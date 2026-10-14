<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\PrestartEvidence\Attributes;

use App\Domain\Keirin\Scraping\PrestartEvidence\RaceTarget;
use RuntimeException;

final class AttributeProjector
{
    public const VERSION = 'STAT39-ATTRIBUTE-PROJECTION-v1';

    /** @param list<RaceTarget> $targets */
    public function project(string $source, string $raw, array $targets, string $captureId, string $manifestHash): array
    {
        $documents = (new RawDocuments)->decode($source, $raw);
        $cards = [];
        if ($source === 'JSJ017') {
            $root = $documents['JSJ017']['node'];
            foreach ($root->required('rInfo')->items() as $race) {
                $cards[] = ['date' => $root->required('kaisaihi')->text(), 'track' => $root->required('keirinCd')->text(),
                    'number' => $this->raceNumber($race->required('raceNo')), 'schedule' => $race->field('stTime'),
                    'entries' => $race->required('sInfo')->items(), 'document' => 'JSJ017', 'identity_document' => 'JSJ017',
                    'identity_nodes' => ['race_date' => $root->required('kaisaihi'), 'track_code' => $root->required('keirinCd'), 'race_number' => $race->required('raceNo')]];
            }
        } else {
            $context = $documents['PC0201']['node']->required('C0201data');
            $detail = $context->required('C0201racedtl');
            $cards[] = ['date' => $context->required('selKaisai')->text(), 'track' => $context->required('selKjyoCd')->text(),
                'number' => $this->raceNumber($context->required('selRaceNo')), 'schedule' => $detail->field('aftStartTime'),
                'entries' => $documents['PJ0315']['node']->required('sensyuTypeInfo')->items(), 'document' => 'PJ0315', 'identity_document' => 'PC0201',
                'identity_nodes' => ['race_date' => $context->required('selKaisai'), 'track_code' => $context->required('selKjyoCd'), 'race_number' => $context->required('selRaceNo')]];
            $summary = [];
            $summaryEvidence = [];
            foreach ($detail->required('C0201sensyu')->items() as $entry) {
                $bike = $entry->required('carNum')->digit();
                $registration = $entry->required('numPlayer')->text();
                if (isset($summary[$bike]) || in_array($registration, $summary, true)) {
                    throw new RuntimeException('Duplicate summary entrant identity.');
                }
                $summary[$bike] = $registration;
                $summaryEvidence[$bike] = [
                    'bike_number' => $this->attribute('bike_number', $entry->required('carNum'), $documents['PC0201'], 'PC0201', false),
                    'external_registration_number' => $this->attribute('external_registration_number', $entry->required('numPlayer'), $documents['PC0201'], 'PC0201', false),
                ];
            }
        }
        $seen = [];
        $result = [];
        foreach ($cards as $card) {
            $key = $card['date'].':'.$card['track'].':'.$card['number'];
            if (isset($seen[$key])) {
                throw new RuntimeException('Duplicate race identity.');
            }
            $seen[$key] = true;
            $target = null;
            foreach ($targets as $candidate) {
                if ($candidate->raceDate.':'.$candidate->trackCode.':'.$candidate->raceNumber === $key) {
                    $target = $candidate;
                }
            }
            if ($target === null) {
                continue;
            }
            $identities = [];
            $entries = [];
            foreach ($card['entries'] as $entry) {
                $bikeNode = $entry->required('syaban');
                $registrationNode = $entry->required($source === 'JSJ017' ? 'senNo' : 'sensyuRegistNo');
                $bike = $bikeNode->digit();
                $registration = $registrationNode->text();
                if (preg_match('/^[0-9]+$/D', $registration) !== 1 || isset($identities[$bike]) || in_array($registration, $identities, true)) {
                    throw new RuntimeException('Duplicate or invalid entrant identity.');
                }
                $identities[$bike] = $registration;
                $attributes = [];
                foreach (['bike_number' => $bikeNode, 'external_registration_number' => $registrationNode,
                    'frame_number' => $source === 'PJ0315' ? $entry->field('wakuban') : null,
                    'race_score' => $source === 'PJ0315' ? $entry->field('heikinTokuten') : null,
                    'scheduled_start' => $card['schedule']] as $attribute => $node) {
                    $documentKey = $source === 'PJ0315' && $attribute === 'scheduled_start' ? 'PC0201' : $card['document'];
                    $attributes[$attribute] = $this->attribute($attribute, $node, $documents[$documentKey], $documentKey,
                        $source === 'JSJ017' && in_array($attribute, ['frame_number', 'race_score'], true));
                }
                $entries[] = ['bike_number' => $bike, 'external_registration_number' => $registration, 'attributes' => $attributes,
                    'summary_identity_evidence' => $source === 'PJ0315' ? ($summaryEvidence[$bike] ?? null) : null];
            }
            $expected = $target->registrations;
            ksort($expected);
            ksort($identities);
            if ($expected !== $identities) {
                throw new RuntimeException('Raw identity set does not match immutable capture target.');
            }
            if ($source === 'PJ0315') {
                ksort($summary);
                if ($summary !== $identities) {
                    throw new RuntimeException('PJ0315/PC0201 entrant identity mismatch.');
                }
            }
            $identityEvidence = [];
            foreach ($card['identity_nodes'] as $name => $node) {
                $identityEvidence[$name] = $this->attribute($name, $node, $documents[$card['identity_document']], $card['identity_document'], false);
            }
            $result[] = ['version' => self::VERSION, 'fixture_kind' => 'SYNTHETIC', 'capture_id' => $captureId,
                'source_manifest_sha256' => $manifestHash, 'raw_sha256' => hash('sha256', $raw), 'source' => $source,
                'race_date' => $card['date'], 'track_code' => $card['track'], 'race_number' => $card['number'],
                'race_identity_evidence' => $identityEvidence, 'entries' => $entries];
        }
        if (count($result) !== count($targets)) {
            throw new RuntimeException('One or more capture target races are absent.');
        }

        return $result;
    }

    private function attribute(string $name, ?JsonNode $node, array $document, string $key, bool $notProvided): array
    {
        $normalized = null;
        $status = 'NORMALIZATION_UNVERIFIED';
        $value = $node?->value();
        if ($notProvided) {
            $presence = 'SOURCE_NOT_PROVIDED';
        } elseif ($node === null) {
            $presence = 'MISSING';
        } elseif ($node->type === 'NULL') {
            $presence = 'NULL';
        } elseif ($node->type === 'STRING' && $value === '') {
            $presence = 'EMPTY_STRING';
        } elseif ($node->type === 'STRING' && $value === '－') {
            $presence = 'FULLWIDTH_HYPHEN';
        } else {
            $presence = 'PRESENT';
            if ($name === 'external_registration_number' && $node->type === 'STRING' && preg_match('/^[0-9]+$/D', $value) === 1) {
                $normalized = $value;
                $status = 'VERIFIED';
            } elseif (in_array($name, ['race_date', 'track_code'], true) && $node->type === 'STRING') {
                $normalized = $value;
                $status = 'VERIFIED';
            } elseif ($name === 'race_number') {
                $normalized = $this->raceNumber($node);
                $status = 'VERIFIED';
            } elseif (in_array($name, ['bike_number', 'frame_number'], true) && in_array($node->type, ['INTEGER', 'STRING'], true)
                && preg_match('/^0*[1-9]$/D', $value) === 1) {
                $normalized = (int) $value;
                $status = 'VERIFIED';
            } elseif ($name === 'race_score' && in_array($node->type, ['STRING', 'INTEGER', 'DECIMAL'], true)
                && preg_match('/^[0-9]+(?:\.[0-9]+)?$/D', $value) === 1) {
                $parts = explode('.', $value);
                $integer = ltrim($parts[0], '0');
                $fraction = rtrim($parts[1] ?? '', '0');
                $normalized = ($integer === '' ? '0' : $integer).($fraction === '' ? '' : '.'.$fraction);
                $status = 'VERIFIED';
            } elseif ($name === 'scheduled_start' && $node->type === 'STRING' && preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $value) === 1) {
                $normalized = $value;
                $status = 'VERIFIED';
            }
        }
        $pointer = null;
        if ($node !== null && ! $notProvided) {
            if (substr($document['bytes'], $node->offset, strlen($node->lexeme)) !== $node->lexeme) {
                throw new RuntimeException('Token offset verification failed.');
            }
            $pointer = ['document_key' => $key, 'json_pointer' => $node->pointer, 'decoder_version' => LosslessJson::VERSION,
                'offset_basis' => $document['offset_basis'], 'script_index' => $document['script_index'],
                'document_offset' => $document['document_offset'], 'token_offset' => $node->offset, 'token_length' => strlen($node->lexeme)];
        }
        $allowedTypes = match ($name) {
            'bike_number', 'frame_number', 'race_number' => ['STRING', 'INTEGER'],
            'race_score' => ['STRING', 'INTEGER', 'DECIMAL'],
            default => ['STRING'],
        };
        $reason = match (true) {
            $presence !== 'PRESENT' => $presence,
            $status === 'VERIFIED' => 'KNOWN_EXACT_VALUE',
            ! in_array($node?->type, $allowedTypes, true) => 'INVALID_TYPE',
            default => 'UNKNOWN_OR_UNSUPPORTED_LEXEME',
        };

        return ['presence' => $presence, 'raw_type' => $node?->type, 'raw_value' => $value, 'raw_lexeme' => $node?->lexeme,
            'raw_value_encoding' => $node === null ? null : match ($node->type) {
                'INTEGER', 'DECIMAL' => 'JSON_NUMBER_LEXEME', 'OBJECT', 'ARRAY' => 'JSON_FRAGMENT', default => 'JSON_SCALAR',
            }, 'normalized_value' => $normalized, 'normalization_status' => $status, 'normalization_reason' => $reason, 'pointer' => $pointer,
            'official_applicability' => 'UNKNOWN', 'stat01_usable' => $name === 'race_score' && $status === 'VERIFIED' && $normalized !== '0'];
    }

    private function raceNumber(JsonNode $node): int
    {
        if (! in_array($node->type, ['INTEGER', 'STRING'], true) || preg_match('/^[0-9]+$/D', $node->value()) !== 1
            || (int) $node->value() < 1 || (int) $node->value() > 99) {
            throw new RuntimeException('Invalid race number.');
        }

        return (int) $node->value();
    }
}
