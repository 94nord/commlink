<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The addresses a firm is reachable *at* — its own numbers and, later,
     * its own mailboxes. The mirror image of `identities`, which are the
     * addresses a customer is reachable at.
     *
     * This used to be one column, `corporations.sms_number`, which could
     * hold exactly one number on exactly one channel. A firm may have
     * several: a second number, and an email address once that channel
     * exists. So the same shape identities already have — a row per address,
     * keyed by the *kind* of address rather than by transport.
     *
     * `is_primary` governs where *new* exchanges start; it is not "the
     * current address". Old addresses stay live for inbound indefinitely,
     * because a customer replying to a months-old message replies to
     * whatever we sent from at the time.
     *
     * `verified_at` records that the address is actually ours to send from.
     * A number bought at the provider is that by construction; a mailbox on
     * the firm's own domain is not, and will have to prove it via DNS.
     */
    public function up(): void
    {
        Schema::create('corporation_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('corporation_id')->constrained()->cascadeOnDelete();
            $table->string('address_type');
            $table->string('value');
            $table->boolean('is_primary')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        $this->assertPartialIndexes();

        $this->backfillFromSmsNumber();

        Schema::table('corporations', function (Blueprint $table) {
            // SQLite refuses to drop a column an index still names.
            $table->dropUnique(['sms_number']);
            $table->dropColumn('sms_number');
        });
    }

    public function down(): void
    {
        Schema::table('corporations', function (Blueprint $table) {
            $table->string('sms_number')->nullable()->unique();
        });

        // Only one number survives the trip back, since only one fits.
        foreach ($this->livePrimaryPhones() as $address) {
            DB::table('corporations')
                ->where('id', $address->corporation_id)
                ->update(['sms_number' => $address->value]);
        }

        Schema::drop('corporation_addresses');
    }

    /**
     * Every firm that texts today keeps texting from the same number, now as
     * its primary phone address.
     */
    private function backfillFromSmsNumber(): void
    {
        foreach (DB::table('corporations')->whereNotNull('sms_number')->get() as $corporation) {
            DB::table('corporation_addresses')->insert([
                'corporation_id' => $corporation->id,
                'address_type' => 'phone',
                'value' => $corporation->sms_number,
                'is_primary' => true,
                // It was already sending, so it was already ours.
                'verified_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * @return iterable<object>
     */
    private function livePrimaryPhones(): iterable
    {
        return DB::table('corporation_addresses')
            ->where('address_type', 'phone')
            ->where('is_primary', true)
            ->whereNull('deleted_at')
            ->get();
    }

    /**
     * Both invariants this table carries are conditional, so neither can be
     * a plain unique index. Kept in one place because a later migration that
     * touches this table will have to re-assert them — see the SQLite
     * rebuild trap in CLAUDE.md.
     */
    private function assertPartialIndexes(): void
    {
        // One primary per kind of address, per firm: new exchanges have
        // exactly one place to start from.
        DB::statement('drop index if exists corporation_addresses_primary_unique');
        DB::statement(
            'create unique index corporation_addresses_primary_unique
             on corporation_addresses (corporation_id, address_type)
             where is_primary and deleted_at is null'
        );

        // An address belongs to one firm, installation-wide: inbound is
        // routed by the address it arrived at, so two claimants would make
        // that ambiguous.
        DB::statement('drop index if exists corporation_addresses_address_type_value_unique');
        DB::statement(
            'create unique index corporation_addresses_address_type_value_unique
             on corporation_addresses (address_type, value)
             where deleted_at is null'
        );
    }
};
