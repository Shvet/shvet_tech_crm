<?php

namespace App\Services\Meta;

use App\Models\MetaFormMapping;
use App\Services\Leads\PhoneNormalizer;

/**
 * Turns the Graph "lead" payload into CreateLead input. Pure (no I/O, no tenant access): easy to test with fixtures.
 *
 * - Standard Meta field names are understood by default (full_name, first_name, last_name, email, phone_number,
 *   company_name); a form mapping's field_map ({"email": "work_email"}) can point a CRM target at another question key.
 * - Invalid phone/e-mail values are dropped (never stored raw) and reported as warning codes.
 * - Every other answer goes into a note, truncated, so nothing a prospect typed is silently lost or trusted as structure.
 */
class MetaLeadMapper
{
    private const MAX_NOTE_ANSWERS = 25;

    private const MAX_ANSWER_LENGTH = 500;

    /** CRM target => Meta question keys tried in order. */
    private const DEFAULTS = [
        'full_name' => ['full_name'],
        'first_name' => ['first_name'],
        'last_name' => ['last_name'],
        'email' => ['email', 'work_email'],
        'phone' => ['phone_number', 'phone', 'work_phone_number'],
        'company_name' => ['company_name'],
    ];

    /**
     * @param  array<string, mixed>  $lead  Graph lead payload
     * @return array{data: array<string, mixed>, warnings: list<string>}
     */
    public function map(array $lead, MetaFormMapping $mapping): array
    {
        $answers = $this->answers($lead);
        $override = is_array($mapping->field_map) ? $mapping->field_map : [];
        $used = [];
        $warnings = [];

        $pick = function (string $target) use ($answers, $override, &$used): ?string {
            $keys = isset($override[$target]) && is_string($override[$target]) && $override[$target] !== ''
                ? [$override[$target]]
                : self::DEFAULTS[$target];
            foreach ($keys as $key) {
                if (isset($answers[$key]) && $answers[$key] !== '') {
                    $used[$key] = true;

                    return $answers[$key];
                }
            }

            return null;
        };

        $name = $pick('full_name');
        $first = $pick('first_name');
        $last = $pick('last_name');
        $rawEmail = $pick('email');
        $rawPhone = $pick('phone');
        $company = $pick('company_name');

        $email = null;
        if ($rawEmail !== null) {
            $candidate = mb_strtolower(trim($rawEmail));
            if (mb_strlen($candidate) <= 190 && filter_var($candidate, FILTER_VALIDATE_EMAIL) !== false) {
                $email = $candidate;
            } else {
                $warnings[] = 'email_invalid';
            }
        }

        $phone = null;
        if ($rawPhone !== null) {
            $phone = PhoneNormalizer::normalize($rawPhone);
            if ($phone === null) {
                $warnings[] = 'phone_invalid';
            }
        }

        $fullName = $name ?? trim(($first ?? '').' '.($last ?? ''));
        if ($fullName === '') {
            $fullName = $email ?? $phone ?? 'Unnamed Meta lead';
            $warnings[] = 'name_missing';
        }

        $data = [
            'full_name' => mb_substr($fullName, 0, 190),
            'email' => $email,
            'phone_e164' => $phone,
            'company_name' => $company !== null ? mb_substr($company, 0, 190) : null,
            'lead_source_id' => $mapping->lead_source_id,
            'pipeline_stage_id' => $mapping->pipeline_stage_id,
            'owner_id' => $mapping->default_owner_id,
            'notes' => $this->note($lead, $answers, $used),
            'external_provider' => 'meta',
            'external_id' => (string) ($lead['id'] ?? ''),
        ];

        return ['data' => $data, 'warnings' => $warnings];
    }

    /** @return array<string, string> question key => first value */
    private function answers(array $lead): array
    {
        $out = [];
        foreach (is_array($lead['field_data'] ?? null) ? $lead['field_data'] : [] as $field) {
            if (! is_array($field) || ! isset($field['name']) || ! is_string($field['name'])) {
                continue;
            }
            $values = array_filter(array_map(fn ($v) => is_scalar($v) ? trim((string) $v) : '', is_array($field['values'] ?? null) ? $field['values'] : []), fn ($v) => $v !== '');
            if ($values !== []) {
                $out[mb_substr($field['name'], 0, 100)] = $this->clean(implode(', ', $values));
            }
        }

        return $out;
    }

    /** @param  array<string, string>  $answers */
    private function note(array $lead, array $answers, array $used): string
    {
        $platform = ($lead['platform'] ?? null) === 'ig' ? 'Instagram' : 'Facebook';
        $lines = ["Imported from {$platform} Lead Ads."];

        foreach (['campaign_name' => 'Campaign', 'adset_name' => 'Ad set', 'ad_name' => 'Ad'] as $key => $label) {
            if (! empty($lead[$key]) && is_string($lead[$key])) {
                $lines[] = $label.': '.$this->clean(mb_substr($lead[$key], 0, 150));
            }
        }
        if (! empty($lead['is_organic'])) {
            $lines[] = 'Organic lead (not from a paid ad).';
        }

        $extra = array_diff_key($answers, $used);
        if ($extra !== []) {
            $lines[] = 'Form answers:';
            foreach (array_slice($extra, 0, self::MAX_NOTE_ANSWERS, true) as $key => $value) {
                $lines[] = '- '.$this->clean($key).': '.mb_substr($value, 0, self::MAX_ANSWER_LENGTH);
            }
        }

        return implode("\n", $lines);
    }

    private function clean(string $value): string
    {
        return trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '');
    }
}
