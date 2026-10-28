<?php

namespace App\Http\Requests\Concerns;

use App\Enums\Priority;
use App\Services\Leads\PhoneNormalizer;
use App\Support\TenantRules;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Shared field rules for creating and editing a lead. */
trait ValidatesLeadFields
{
    /** @return array<string, mixed> */
    protected function leadFieldRules(): array
    {
        return [
            'full_name' => ['required', 'string', 'min:2', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'string', 'email:rfc', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'priority' => ['nullable', Rule::enum(Priority::class)],
            'estimated_value' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99', 'decimal:0,2'],
            'lead_source_id' => ['required', 'integer', TenantRules::exists('lead_sources', 'id', activeOnly: true)],
            'confirm_duplicate' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareLeadFields(): void
    {
        $this->merge([
            'full_name' => is_string($this->input('full_name')) ? trim($this->input('full_name')) : $this->input('full_name'),
            'email' => is_string($this->input('email')) && trim($this->input('email')) !== '' ? mb_strtolower(trim($this->input('email'))) : null,
            'company_name' => is_string($this->input('company_name')) && trim($this->input('company_name')) !== '' ? trim($this->input('company_name')) : null,
            'estimated_value' => $this->input('estimated_value') === '' ? null : $this->input('estimated_value'),
        ]);
    }

    /** Phone must be valid; at least one contact method is required. */
    protected function validateContactMethods(Validator $validator): void
    {
        $phone = trim((string) $this->input('phone'));

        if ($phone !== '' && PhoneNormalizer::normalize($phone) === null) {
            $validator->errors()->add('phone', 'Enter a valid phone number (with country code if it is not an Indian number).');
        }
        if ($phone === '' && blank($this->input('email'))) {
            $validator->errors()->add('phone', 'Provide a phone number or an e-mail address.');
        }
    }

    /** @return array<string, mixed> validated lead attributes with the phone normalized to E.164 */
    protected function leadData(): array
    {
        $data = $this->safe()->only([
            'full_name', 'email', 'company_name', 'priority', 'estimated_value', 'lead_source_id',
        ]);
        $data['phone_e164'] = PhoneNormalizer::normalize($this->input('phone'));

        return $data;
    }

    public function confirmsDuplicate(): bool
    {
        return $this->boolean('confirm_duplicate');
    }
}
