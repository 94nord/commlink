<?php

namespace App\Support;

use InvalidArgumentException;
use Propaganistas\LaravelPhone\PhoneNumber as LibPhoneNumber;

/**
 * Phone numbers reach us in whatever shape the sender typed them — a local
 * "12345678", a spaced "+45 12 34 56 78", a provider's already-canonical
 * "+4512345678". They are stored in exactly one shape, E.164, so that
 * matching an inbound message to an identity stays a plain equality lookup
 * and the same person never ends up with two conversations.
 *
 * This is a phone concern, not a core one: only the SMS adapter calls it.
 */
class PhoneNumber
{
    /**
     * Normalize a number to E.164. Numbers written without a country code
     * are read as belonging to the configured default region.
     *
     * @throws InvalidArgumentException when the value is not a real,
     *                                  dialable number
     */
    public static function normalize(string $value, ?string $region = null): string
    {
        $number = new LibPhoneNumber($value, $region ?? config('inbox.default_phone_region'));

        if (! $number->isValid()) {
            throw new InvalidArgumentException(__('errors.invalid_phone_number', ['value' => $value]));
        }

        return $number->formatE164();
    }

    /**
     * Whether the value can be normalized at all — for validating input
     * before committing to it.
     */
    public static function isValid(string $value, ?string $region = null): bool
    {
        return (new LibPhoneNumber($value, $region ?? config('inbox.default_phone_region')))->isValid();
    }
}
