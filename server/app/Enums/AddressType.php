<?php

namespace App\Enums;

use App\Support\EmailAddress;
use App\Support\PhoneNumber;

/**
 * What kind of address an identity is — the space the value lives in, not
 * the way a message travelled to it.
 *
 * This is the distinction that keeps one person as one conversation. A phone
 * number is a phone number whether the customer texts it, sends a picture to
 * it, or rings it and leaves a recording; all of that belongs on the same
 * identity, in the same thread, under the same conversation. The transport
 * each message actually used is `Channel`, recorded on the message.
 */
enum AddressType: string
{
    case Phone = 'phone';
    case Email = 'email';

    /**
     * The channel a plain message goes out on for this kind of address.
     *
     * Richer sends will name their own channel instead — a picture to a
     * phone number is MMS, not SMS — but they still reach the same address
     * and belong to the same identity.
     */
    public function defaultChannel(): Channel
    {
        return match ($this) {
            self::Phone => Channel::Sms,
            self::Email => Channel::Email,
        };
    }

    /**
     * The module a firm has to be switched on for to use this kind of
     * address at all.
     */
    public function module(): Module
    {
        return match ($this) {
            self::Phone => Module::Sms,
            self::Email => Module::Email,
        };
    }

    /**
     * What kind of address somebody just typed.
     *
     * An `@` means a mailbox and anything else is read as a number. Crude on
     * purpose: it only has to route the value to the right validator, and
     * that validator is what actually decides whether the address is usable.
     * Returns null when it is neither — a typo, not a channel.
     */
    public static function sniff(string $value): ?self
    {
        $candidate = str_contains($value, '@') ? self::Email : self::Phone;

        return $candidate->isValid($value) ? $candidate : null;
    }

    /**
     * Whether this value is usable as an address of this kind — asked before
     * committing to it, so a typo is a field error rather than an exception.
     */
    public function isValid(string $value): bool
    {
        return match ($this) {
            self::Phone => PhoneNumber::isValid($value),
            self::Email => EmailAddress::isValid($value),
        };
    }

    /**
     * Put an address into the one shape this kind is stored in.
     *
     * Canonical form belongs to the address *space*, not to the transport:
     * a text and a picture message to the same number must normalize
     * identically or they would land on two identities and split one person
     * in half. So this is the single authority, and a channel adapter's
     * `normalizeAddress()` delegates here rather than deciding for itself.
     *
     * @throws \InvalidArgumentException when the value is not usable
     */
    public function normalize(string $value): string
    {
        return match ($this) {
            self::Phone => PhoneNumber::normalize($value),
            self::Email => EmailAddress::normalize($value),
        };
    }
}
