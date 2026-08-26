<?php

namespace App\Enums;

/**
 * How a message travelled — the transport, recorded per message.
 *
 * Distinct from `AddressType`, which is what the message travelled *to*.
 * Several channels can share one address space: a text, a picture message
 * and a voicemail all reach the same phone number, and therefore all belong
 * to the same identity and the same conversation.
 *
 * Everything a channel actually *does* lives behind its adapter, so adding a
 * case here is the start of a new adapter, never a change to the
 * conversation model.
 */
enum Channel: string
{
    case Sms = 'sms';
    case Email = 'email';

    /**
     * The kind of address this channel reaches.
     */
    public function addressType(): AddressType
    {
        return match ($this) {
            self::Sms => AddressType::Phone,
            self::Email => AddressType::Email,
        };
    }
}
