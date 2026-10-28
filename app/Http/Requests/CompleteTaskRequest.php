<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class CompleteTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('complete', $this->route('task'));
    }

    public function rules(): array
    {
        return ['outcome' => ['nullable', 'string', 'max:2000']];
    }
}
