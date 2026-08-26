<?php

namespace Tests\Feature\Inbox;

use App\Enums\AddressType;
use App\Enums\Channel;
use App\Inbox\ChannelRegistry;
use App\Inbox\Sms\SmsChannelAdapter;
use Tests\TestCase;

class SmsChannelAdapterTest extends TestCase
{
    public function test_it_normalizes_the_sender_into_a_canonical_address()
    {
        $inbound = app(SmsChannelAdapter::class)->receive([
            'From' => '20123456',
            'To' => '+4533221100',
            'Body' => 'Hello',
            'MessageSid' => 'SM123',
        ]);

        $this->assertSame('+4520123456', $inbound->address);
        $this->assertSame('Hello', $inbound->body);
        $this->assertNull($inbound->threadKey);
    }

    public function test_it_keeps_the_provider_details_out_of_the_core_and_in_channel_data()
    {
        $inbound = app(SmsChannelAdapter::class)->receive([
            'From' => '+4520123456',
            'To' => '+4533221100',
            'Body' => 'Hello',
            'MessageSid' => 'SM123',
        ]);

        $this->assertSame([
            'from' => '+4520123456',
            'to' => '+4533221100',
            'message_sid' => 'SM123',
        ], $inbound->channelData);
    }

    public function test_an_empty_text_is_still_a_message()
    {
        $inbound = app(SmsChannelAdapter::class)->receive([
            'From' => '+4520123456',
            'To' => '+4533221100',
            'Body' => '',
        ]);

        $this->assertSame('', $inbound->body);
        $this->assertArrayNotHasKey('message_sid', $inbound->channelData);
    }

    public function test_sms_declares_that_it_does_not_thread()
    {
        $capabilities = app(SmsChannelAdapter::class)->capabilities();

        $this->assertFalse($capabilities->threadsByKey);
        // MMS pictures arrive on the same number.
        $this->assertTrue($capabilities->supportsAttachments);
        $this->assertSame(1600, $capabilities->maxBodyLength);
    }

    public function test_sms_reaches_a_phone_address_not_an_sms_address()
    {
        // The distinction that keeps one person as one conversation: a text,
        // a picture message and a voicemail are three channels reaching one
        // kind of address, so they share an identity, a thread and a
        // conversation rather than fragmenting the person.
        $this->assertSame(AddressType::Phone, app(SmsChannelAdapter::class)->addressType());
        $this->assertSame(AddressType::Phone, Channel::Sms->addressType());
        $this->assertSame(Channel::Sms, AddressType::Phone->defaultChannel());
    }

    public function test_the_registry_resolves_the_sms_adapter()
    {
        $registry = app(ChannelRegistry::class);

        $this->assertTrue($registry->supports(Channel::Sms));
        $this->assertSame(Channel::Sms, $registry->for(Channel::Sms)->channel());
        $this->assertContains(Channel::Sms, $registry->channels());
    }
}
