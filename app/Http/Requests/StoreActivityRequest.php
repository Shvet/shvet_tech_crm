<?php

namespace App\Http\Requests;

use App\Enums\ActivityType;
use App\Support\TenantTime;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('logActivity', $this->route('lead'));
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(ActivityType::class)],
            'subject' => ['nullable', 'string', 'max:200'],
            'body' => ['nullable', 'string', 'max:5000', Rule::requiredIf(fn () => $this->input('type') === ActivityType::Note->value)],
            'occurred_at' => ['nullable', 'date'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $v) {
            if ($this->filled('occurred_at') && ! $v->errors()->has('occurred_at') && $this->occurredAt()->greaterThan(CarbonImmutable::now()->addMinutes(5))) {
                $v->errors()->add('occurred_at', 'The time cannot be in the future.');
            }
        }];
    }

    /** When it happened (UTC). Defaults to now; input is interpreted in the tenant timezone. */
    public function occurredAt(): CarbonImmutable
    {
        return $this->filled('occurred_at') ? TenantTime::parseLocal($this->input('occurred_at')) : CarbonImmutable::now();
    }
}
