<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A mailbox is an address, and addresses are identities.
     *
     * `customers.email` was a second store for the same kind of fact the
     * inbox already keeps in `identities` — exactly the split that
     * `customers.phone` had before it, and it fails the same way: an address
     * typed on a customer's record was invisible to the inbox, and an
     * address the inbox learned was invisible on the record. One store, and
     * the moment an email channel exists a mailbox written here is one the
     * inbox can answer on.
     *
     * The uniqueness that lived on `customers.email` is not lost — it is the
     * identities index, which is stricter: one live owner per address per
     * corporation, across every kind of address.
     *
     * An email nobody can parse is left behind on purpose rather than
     * dropped silently: it is written to the log so somebody can go and look.
     */
    public function up(): void
    {
        $this->moveEmailsOntoIdentities();

        // SQLite refuses to drop a column an index still names.
        DB::statement('drop index if exists customers_corporation_id_email_unique');

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('email');
        });

        // Dropping a column rebuilds the table on SQLite, and a rebuild
        // copies indexes without their WHERE clause.
        $this->assertPartialIndexes();
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('email')->nullable();
        });

        // Only one mailbox survives the trip back, since only one fits.
        foreach ($this->liveEmailIdentities() as $identity) {
            DB::table('customers')
                ->where('id', $identity->customer_id)
                ->update(['email' => $identity->value]);
        }

        $this->assertPartialIndexes();

        DB::statement('drop index if exists customers_corporation_id_email_unique');
        DB::statement(
            'create unique index customers_corporation_id_email_unique
             on customers (corporation_id, email)
             where email is not null and deleted_at is null'
        );
    }

    /**
     * Every email on a live customer becomes an address they can actually be
     * reached at. An address already claimed by somebody else is left alone —
     * the identities index is the stricter authority, and overwriting it
     * would move history onto the wrong person.
     */
    private function moveEmailsOntoIdentities(): void
    {
        $customers = DB::table('customers')
            ->whereNotNull('email')
            ->whereNull('deleted_at')
            ->get();

        foreach ($customers as $customer) {
            $value = mb_strtolower(trim($customer->email));

            if ($value === '' || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                logger()->warning('Customer email could not be moved onto an identity.', [
                    'customer_id' => $customer->id,
                    'email' => $customer->email,
                ]);

                continue;
            }

            $taken = DB::table('identities')
                ->where('corporation_id', $customer->corporation_id)
                ->where('address_type', 'email')
                ->where('value', $value)
                ->whereNull('deleted_at')
                ->exists();

            if ($taken) {
                continue;
            }

            DB::table('identities')->insert([
                'corporation_id' => $customer->corporation_id,
                'customer_id' => $customer->id,
                'address_type' => 'email',
                'value' => $value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * @return iterable<object>
     */
    private function liveEmailIdentities(): iterable
    {
        return DB::table('identities')
            ->where('address_type', 'email')
            ->whereNull('deleted_at')
            ->get();
    }

    private function assertPartialIndexes(): void
    {
        // The email index goes with the column; everything else on customers
        // has to be put back exactly as it was.
        DB::statement('drop index if exists customers_corporation_id_email_unique');

        DB::statement('drop index if exists customers_corporation_id_external_ref_unique');
        DB::statement(
            'create unique index customers_corporation_id_external_ref_unique
             on customers (corporation_id, external_ref)
             where external_ref is not null and deleted_at is null'
        );
    }
};
