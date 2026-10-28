<?php

namespace App\Http\Requests;

use App\Support\TenantRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ChangeLeadStageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('changeStage', $this->route('lead'));
    }

    public function rules(): array
    {
        return [
            'pipeline_stage_id' => ['required', 'integer', TenantRules::exists('pipeline_stages', 'id', activeOnly: true)],
            // Optimistic concurrency: the stage the client believes the lead is currently in.
            'expected_stage_id' => ['nullable', 'integer'],
        ];
    }
}
