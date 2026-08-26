<?php

namespace Tests\Feature\Inbox;

use App\Support\PhoneNumber;
use InvalidArgumentException;
use Tests\TestCase;

class PhoneNumberTest extends TestCase
{
    public function test_it_reads_a_local_number_as_belonging_to_the_default_region()
    {
        $this->assertSame('+4520123456', PhoneNumber::normalize('20123456'));
    }

    public function test_it_strips_the_spacing_people_type()
    {
        $this->assertSame('+4520123456', PhoneNumber::normalize('+45 20 12 34 56'));
        $this->assertSame('+4520123456', PhoneNumber::normalize('2012 3456'));
    }

    public function test_an_already_normalized_number_survives_unchanged()
    {
        $this->assertSame('+4520123456', PhoneNumber::normalize('+4520123456'));
    }

    public function test_a_number_carrying_its_own_country_code_ignores_the_default_region()
    {
        $this->assertSame('+447400123456', PhoneNumber::normalize('+447400123456'));
    }

    public function test_the_default_region_is_configurable()
    {
        config(['inbox.default_phone_region' => 'GB']);

        $this->assertSame('+447400123456', PhoneNumber::normalize('07400123456'));
    }

    public function test_it_rejects_a_value_that_is_not_a_phone_number()
    {
        $this->expectException(InvalidArgumentException::class);

        PhoneNumber::normalize('not-a-number');
    }

    public function test_it_rejects_a_number_that_is_not_dialable_in_the_region()
    {
        // Eight digits, but 1xxxxxxx is a Danish service range, not a
        // subscriber number — normalizing it would invent a contactable
        // address that does not exist.
        $this->expectException(InvalidArgumentException::class);

        PhoneNumber::normalize('12345678');
    }

    public function test_it_reports_validity_without_throwing()
    {
        $this->assertTrue(PhoneNumber::isValid('20123456'));
        $this->assertFalse(PhoneNumber::isValid('123'));
    }
}
