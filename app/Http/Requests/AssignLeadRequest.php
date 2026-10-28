<?php

namespace App\Http\Requests;

use App\Support\TenantRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class AssignLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('assign', $this->route('lead'));
    }

    public function rules(): array
    {
        return ['owner_id' => ['present', 'nullable', 'integer', TenantRules::activeMember()]];
    }
}
