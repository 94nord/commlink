<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVisitRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'staff_id' => ['required', 'integer'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'round_id' => ['nullable', 'integer'],
        ];
    }
}
