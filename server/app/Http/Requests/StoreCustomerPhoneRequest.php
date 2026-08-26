<?php

namespace App\Http\Requests;

use App\Rules\UsablePhoneNumber;
use Illuminate\Foundation\Http\FormRequest;

class StoreCustomerPhoneRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'max:255', new UsablePhoneNumber],
        ];
    }
}
