<?php

namespace App\Http\Requests;

use App\Enums\Priority;
use App\Support\TenantRules;
use App\Support\TenantTime;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('update', $this->route('task'));
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'min:2', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'priority' => ['sometimes', Rule::enum(Priority::class)],
            'due_at' => ['sometimes', 'required', 'date', 'after:2000-01-01', 'before:2100-01-01'],
            'assigned_to' => ['sometimes', 'integer', TenantRules::activeMember()],
        ];
    }

    /** @return array<string, mixed> */
    public function taskData(): array
    {
        $data = $this->safe()->only(['title', 'description', 'priority', 'assigned_to']);
        if ($this->has('due_at')) {
            $data['due_at'] = TenantTime::parseLocal($this->input('due_at'));
        }

        return $data;
    }
}
