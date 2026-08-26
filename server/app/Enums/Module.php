<?php

namespace App\Enums;

/**
 * A part of the product a firm has been switched on for.
 *
 * Modules are opt-in for everything: nothing is enabled when a corporation
 * is created, and ops turns each one on explicitly. Presence of a row in
 * `corporation_modules` is the whole of "enabled".
 *
 * Opting out freezes rather than deletes. History stays readable, nothing
 * new goes out, and inbound is swallowed silently — a firm that stops paying
 * for SMS does not lose the texts it already had, and a customer who writes
 * in anyway is never told the firm has gone away.
 *
 * One module per address space, not per transport: switching SMS off has to
 * take everything that reaches a phone number with it, or a firm that opted
 * out of texting would still be sending picture messages.
 */
enum Module: string
{
    case Sms = 'sms';
    case Email = 'email';

    /**
     * The kind of address this module governs.
     */
    public function addressType(): AddressType
    {
        return match ($this) {
            self::Sms => AddressType::Phone,
            self::Email => AddressType::Email,
        };
    }
}
