<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Mailboxes reach us however the sender typed them — "Anders@Firma.DK",
 * " anders@firma.dk ", a client's already-clean "anders@firma.dk". They are
 * stored in exactly one shape, so that matching an inbound message to an
 * identity stays a plain equality lookup and the same person never ends up
 * with two conversations.
 *
 * Lowercased whole, local part included. RFC 5321 says the local part is the
 * receiving server's business and may be case-sensitive; in practice no mail
 * provider a Danish trade firm's customers use treats it that way, and the
 * cost of being wrong in the other direction — one person split across two
 * identities because they capitalized their own name — is far worse than the
 * theoretical collision. This is also what `customers.email` already did.
 *
 * The mirror of `PhoneNumber`: an address concern, not a channel one, so
 * everything reaching a mailbox normalizes identically.
 */
class EmailAddress
{
    /**
     * Normalize a mailbox to its stored form.
     *
     * @throws InvalidArgumentException when the value is not a usable
     *                                  mailbox
     */
    public static function normalize(string $value): string
    {
        $normalized = mb_strtolower(trim($value));

        if (! static::isValid($normalized)) {
            throw new InvalidArgumentException(__('errors.invalid_email_address', ['value' => $value]));
        }

        return $normalized;
    }

    /**
     * Whether the value can be normalized at all — for validating input
     * before committing to it.
     */
    public static function isValid(string $value): bool
    {
        return filter_var(trim($value), FILTER_VALIDATE_EMAIL) !== false;
    }
}
