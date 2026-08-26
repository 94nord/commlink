<?php

namespace App\Rules;

use App\Support\PhoneNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A number we could actually text. Checked here so a typo comes back as a
 * field error rather than blowing up in the model's normalizer.
 */
class UsablePhoneNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value)) {
            return;
        }

        if (! PhoneNumber::isValid((string) $value)) {
            $fail(__('inbox.invalid_phone'));
        }
    }
}
