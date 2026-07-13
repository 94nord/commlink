<?php

namespace App\Http\Requests;

use App\Enums\VisitType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVisitRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(VisitType::class)],
            'staff_id' => ['required', 'integer'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'round_id' => ['nullable', 'integer'],
        ];
    }
}
