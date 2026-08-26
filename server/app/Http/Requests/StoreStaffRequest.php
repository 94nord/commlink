<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStaffRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'nullable',
                'string',
                'email',
                'max:255',
                Rule::unique('staff', 'email')
                    ->where('corporation_id', $this->user()->corporation_id)
                    ->whereNull('deleted_at'),
            ],
            'is_admin' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => __('staff.email_taken'),
        ];
    }
}
