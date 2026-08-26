<?php

namespace Tests\Feature\Inbox;

use App\Enums\AddressType;
use App\Models\Corporation;
use App\Models\CorporationAddress;
use App\Models\Event;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CorporationAddressCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_attaches_a_number_normalized_to_e164()
    {
        $corporation = Corporation::factory()->create(['name' => 'Jensen VVS']);

        $this->artisan('app:attach-address', [
            '--corporation' => 'Jensen VVS',
            '--address' => '33 22 11 00',
        ])->assertSuccessful();

        $this->assertSame('+4533221100', $this->primaryPhone($corporation));
        $this->assertNotNull(Event::where('type', 'corporation.address_attached')->first());
    }

    public function test_the_first_address_of_its_kind_becomes_primary_without_being_asked()
    {
        $corporation = Corporation::factory()->create();

        $this->artisan('app:attach-address', [
            '--corporation' => (string) $corporation->id,
            '--address' => '+4533221100',
        ])->assertSuccessful();

        $this->assertTrue($corporation->addresses()->sole()->is_primary);
        $this->assertNotNull(Event::where('type', 'corporation.address_primary_changed')->first());
    }

    public function test_a_second_number_is_attached_without_taking_over_as_primary()
    {
        $corporation = Corporation::factory()->withPhone('+4533221100')->create();

        $this->artisan('app:attach-address', [
            '--corporation' => (string) $corporation->id,
            '--address' => '+4533221101',
        ])->assertSuccessful();

        $this->assertSame(2, $corporation->addresses()->count());
        $this->assertSame('+4533221100', $this->primaryPhone($corporation));
    }

    public function test_the_primary_flag_moves_and_the_move_is_recorded()
    {
        $corporation = Corporation::factory()->withPhone('+4533221100')->create();

        $this->artisan('app:attach-address', [
            '--corporation' => (string) $corporation->id,
            '--address' => '+4533221101',
            '--primary' => true,
        ])->assertSuccessful();

        $this->assertSame('+4533221101', $this->primaryPhone($corporation));
        $this->assertSame(1, $corporation->addresses()->where('is_primary', true)->count());

        $event = Event::where('type', 'corporation.address_primary_changed')->latest('id')->first();
        $this->assertSame('+4533221100', $event->payload['previous']);
    }

    public function test_it_accepts_a_corporation_id()
    {
        $corporation = Corporation::factory()->create();

        $this->artisan('app:attach-address', [
            '--corporation' => (string) $corporation->id,
            '--address' => '+4533221100',
        ])->assertSuccessful();

        $this->assertSame('+4533221100', $this->primaryPhone($corporation));
    }

    public function test_it_refuses_a_number_that_is_not_dialable()
    {
        $corporation = Corporation::factory()->create();

        $this->artisan('app:attach-address', [
            '--corporation' => (string) $corporation->id,
            '--address' => 'not-a-number',
        ])->assertFailed();

        $this->assertNull($this->primaryPhone($corporation));
    }

    public function test_it_refuses_a_kind_of_address_that_does_not_exist()
    {
        $corporation = Corporation::factory()->create();

        $this->artisan('app:attach-address', [
            '--corporation' => (string) $corporation->id,
            '--type' => 'carrier-pigeon',
            '--address' => '+4533221100',
        ])->assertFailed();

        $this->assertSame(0, $corporation->addresses()->count());
    }

    public function test_it_refuses_a_number_another_firm_already_texts_from()
    {
        Corporation::factory()->withPhone('+4533221100')->create(['name' => 'First']);
        $second = Corporation::factory()->create(['name' => 'Second']);

        $this->artisan('app:attach-address', [
            '--corporation' => 'Second',
            '--address' => '+4533221100',
        ])->assertFailed();

        $this->assertNull($this->primaryPhone($second));
    }

    public function test_reattaching_the_same_number_to_the_same_firm_is_fine()
    {
        $corporation = Corporation::factory()->withPhone('+4533221100')->create();

        $this->artisan('app:attach-address', [
            '--corporation' => (string) $corporation->id,
            '--address' => '+4533221100',
        ])->assertSuccessful();

        $this->assertSame(1, $corporation->addresses()->count());
    }

    public function test_it_reports_an_unknown_corporation()
    {
        $this->artisan('app:attach-address', [
            '--corporation' => 'Nobody',
            '--address' => '+4533221100',
        ])->assertFailed();
    }

    public function test_detaching_releases_the_number_for_another_firm()
    {
        $first = Corporation::factory()->withPhone('+4533221100')->create(['name' => 'First']);
        $second = Corporation::factory()->create(['name' => 'Second']);

        $this->artisan('app:detach-address', [
            '--corporation' => 'First',
            '--address' => '+4533221100',
        ])->assertSuccessful();

        $this->assertNull($this->primaryPhone($first));
        $this->assertNotNull(Event::where('type', 'corporation.address_detached')->first());

        $this->artisan('app:attach-address', [
            '--corporation' => 'Second',
            '--address' => '+4533221100',
        ])->assertSuccessful();

        $this->assertSame('+4533221100', $this->primaryPhone($second));
    }

    public function test_a_detached_address_leaves_the_primary_slot_free()
    {
        $corporation = Corporation::factory()->withPhone('+4533221100')->create();

        $this->artisan('app:detach-address', [
            '--corporation' => (string) $corporation->id,
            '--address' => '+4533221100',
        ])->assertSuccessful();

        // The soft-deleted row must not keep occupying the one primary slot
        // its kind is allowed, or nothing can take its place.
        $this->artisan('app:attach-address', [
            '--corporation' => (string) $corporation->id,
            '--address' => '+4533221101',
        ])->assertSuccessful();

        $this->assertSame('+4533221101', $this->primaryPhone($corporation));
    }

    public function test_it_refuses_to_detach_an_address_the_firm_does_not_have()
    {
        $corporation = Corporation::factory()->withPhone('+4533221100')->create();

        $this->artisan('app:detach-address', [
            '--corporation' => (string) $corporation->id,
            '--address' => '+4533221199',
        ])->assertFailed();

        $this->assertSame('+4533221100', $this->primaryPhone($corporation));
    }

    public function test_one_firm_cannot_hold_two_primaries_of_the_same_kind()
    {
        $corporation = Corporation::factory()->withPhone('+4533221100')->create();

        $this->expectException(QueryException::class);

        CorporationAddress::create([
            'corporation_id' => $corporation->id,
            'address_type' => AddressType::Phone,
            'value' => '+4533221101',
            'is_primary' => true,
        ]);
    }

    private function primaryPhone(Corporation $corporation): ?string
    {
        return $corporation->primaryAddress(AddressType::Phone)?->value;
    }
}
