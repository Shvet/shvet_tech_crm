<?php

namespace App\Http\Requests;

use App\Enums\Priority;
use App\Support\TenantRules;
use App\Support\TenantTime;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('createTask', $this->route('lead'));
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:2', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'priority' => ['nullable', Rule::enum(Priority::class)],
            'due_at' => ['required', 'date', 'after:2000-01-01', 'before:2100-01-01'],
            'assigned_to' => ['nullable', 'integer', TenantRules::activeMember()],
        ];
    }

    /** @return array<string, mixed> */
    public function taskData(): array
    {
        return [
            ...$this->safe()->only(['title', 'description', 'priority']),
            'due_at' => TenantTime::parseLocal($this->input('due_at')),
            'assigned_to' => $this->input('assigned_to') ?: $this->user()->getKey(),
        ];
    }

    public function dueAt(): CarbonImmutable
    {
        return TenantTime::parseLocal($this->input('due_at'));
    }
}
