<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreConversationRequest extends FormRequest
{
    /**
     * Compose needs to know who it is writing to: an address, a known
     * customer, or both (which is how an address gets attached to a customer
     * we hold none for).
     *
     * The address may be a phone number or a mailbox, and which one decides
     * the channel. Which it is falls to `AddressType::sniff()`, and whether
     * it is usable to that kind's validator — a form request cannot rule on
     * the shape of a value before knowing what kind of thing it is.
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string'],
            'address' => ['nullable', 'string', 'max:255', 'required_without:customerId'],
            'customerId' => [
                'nullable',
                'integer',
                'required_without:address',
                Rule::exists('customers', 'id')
                    ->where('corporation_id', $this->user()->corporation_id)
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'address.required_without' => __('inbox.say_who_this_is_going_to'),
            'customerId.required_without' => __('inbox.say_who_this_is_going_to'),
            'customerId.exists' => __('customers.not_found'),
        ];
    }
}
