<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesLeadFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

class UpdateLeadRequest extends FormRequest
{
    use ValidatesLeadFields;

    public function authorize(): bool
    {
        return Gate::allows('update', $this->route('lead'));
    }

    protected function prepareForValidation(): void
    {
        $this->prepareLeadFields();
    }

    public function rules(): array
    {
        // owner / stage are intentionally absent: they have their own authorized endpoints.
        return $this->leadFieldRules();
    }

    public function after(): array
    {
        return [fn (Validator $v) => $this->validateContactMethods($v)];
    }

    /** @return array<string, mixed> */
    public function leadAttributes(): array
    {
        return $this->leadData();
    }
}
