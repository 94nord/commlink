<?php

namespace App\Inbox\Contracts;

use App\Inbox\CanonicalInbound;
use App\Models\Identity;
use App\Models\Thread;

/**
 * An adapter that decides for itself which exchange an inbound message
 * belongs to.
 *
 * Optional on purpose. Most channels have nothing to decide: a phone number
 * is one unbroken exchange, so the core's default — the address's single
 * thread — is the whole truth, and SMS implements none of this. Email does
 * have something to decide, because a mail client follows a reference chain
 * and a person may have three unrelated exchanges running on one mailbox.
 *
 * Kept off `ChannelAdapter` so that adding it changed nothing about SMS:
 * `IngestInboundMessage` asks whether the adapter implements this and falls
 * back to the default when it does not.
 */
interface ResolvesThreads
{
    /**
     * Which exchange on this address the message belongs to — creating one
     * if it starts something new.
     */
    public function threadFor(Identity $identity, CanonicalInbound $inbound): Thread;
}
