<?php

namespace App\Http\Requests;

use App\Rules\UsablePhoneNumber;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCustomerRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // A name is the only thing genuinely required; plenty of
            // customers are a name and a mobile number.
            //
            // Uniqueness is not checked here any more: a mailbox is an
            // identity, and one live owner per address is the identities
            // index. AddCustomerAddress is what enforces it, and it can say
            // *who* holds the address — or quietly fold in the unnamed
            // person who does, which a validation rule could never do.
            'email' => [
                'nullable',
                'string',
                'email',
                'max:255',
            ],
            'phone' => ['nullable', 'string', 'max:255', new UsablePhoneNumber],
            'address' => ['nullable', 'string', 'max:255'],
        ];
    }
}
