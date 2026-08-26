<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LinkConversationRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'customerId' => [
                'required',
                'integer',
                Rule::exists('customers', 'id')
                    ->where('corporation_id', $this->user()->corporation_id)
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'customerId.exists' => __('customers.not_found'),
        ];
    }
}
