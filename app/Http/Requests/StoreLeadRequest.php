<?php

namespace App\Http\Requests;

use App\Enums\Permission;
use App\Http\Requests\Concerns\ValidatesLeadFields;
use App\Models\Lead;
use App\Support\TenantRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

class StoreLeadRequest extends FormRequest
{
    use ValidatesLeadFields;

    public function authorize(): bool
    {
        return Gate::allows('create', Lead::class);
    }

    protected function prepareForValidation(): void
    {
        $this->prepareLeadFields();
    }

    public function rules(): array
    {
        $canAssign = $this->user()->checkPermissionTo(Permission::LeadsAssign->value);

        return [
            ...$this->leadFieldRules(),
            'pipeline_stage_id' => ['nullable', 'integer', TenantRules::exists('pipeline_stages', 'id', activeOnly: true)],
            // Only assigners may choose an owner; everybody else always owns what they create.
            'owner_id' => $canAssign ? ['nullable', 'integer', TenantRules::activeMember()] : ['prohibited'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'submission_token' => ['nullable', 'uuid'],
        ];
    }

    public function after(): array
    {
        return [fn (Validator $v) => $this->validateContactMethods($v)];
    }

    /** @return array<string, mixed> */
    public function leadAttributes(): array
    {
        return [
            ...$this->leadData(),
            ...$this->safe()->only(['pipeline_stage_id', 'owner_id', 'notes', 'submission_token']),
        ];
    }
}
