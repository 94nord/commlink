<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An address belongs to a person, not to an exchange.
     *
     * Identities used to hang off conversations, so an unknown number had
     * somewhere to live. The cost was an indirection everywhere else: a
     * customer's numbers were reachable only through a conversation, and
     * merely writing one down forced an exchange into being that had not
     * happened. Now every address points straight at a customer, and an
     * unknown number simply creates a customer nobody has named yet.
     *
     * `customers.name` becomes nullable to allow that. An unnamed customer is
     * not junk — it is a real person the firm is talking to, shown by their
     * number until somebody says who they are. The customer *list* hides
     * them; the inbox is where they belong.
     *
     * `customers.phone` goes away in the same move: it was a second store for
     * the same fact, and the two had no way of agreeing.
     *
     * A conversation stays what it always was — the team's state about one
     * person — but is created only when there is something to have state
     * about, which is the first message.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('name')->nullable()->change();
        });

        Schema::table('identities', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->constrained()->cascadeOnDelete();
        });

        $this->backfillIdentityOwners();
        $this->movePhonesOntoIdentities();

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('phone');
        });

        Schema::table('identities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('conversation_id');
        });

        // Every change above rebuilds a table on SQLite, and a rebuild copies
        // indexes without their WHERE clause. Re-assert all of them last.
        $this->assertPartialIndexes();
    }

    public function down(): void
    {
        Schema::table('identities', function (Blueprint $table) {
            $table->foreignId('conversation_id')->nullable()->constrained()->cascadeOnDelete();
        });

        foreach (DB::table('identities')->whereNull('deleted_at')->get() as $identity) {
            DB::table('identities')->where('id', $identity->id)->update([
                'conversation_id' => DB::table('conversations')
                    ->where('customer_id', $identity->customer_id)
                    ->whereNull('deleted_at')
                    ->value('id'),
            ]);
        }

        Schema::table('customers', function (Blueprint $table) {
            $table->string('phone')->nullable();
            $table->string('name')->nullable(false)->change();
        });

        Schema::table('identities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
        });

        $this->assertPartialIndexes();
    }

    /**
     * Every existing address belongs to whoever its conversation was about.
     * An anonymous conversation becomes a customer nobody has named.
     */
    private function backfillIdentityOwners(): void
    {
        foreach (DB::table('identities')->whereNull('deleted_at')->get() as $identity) {
            $conversation = DB::table('conversations')->find($identity->conversation_id);

            if ($conversation === null) {
                continue;
            }

            $customerId = $conversation->customer_id ?? DB::table('customers')->insertGetId([
                'corporation_id' => $conversation->corporation_id,
                'kind' => 'person',
                'name' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('identities')->where('id', $identity->id)->update(['customer_id' => $customerId]);
            DB::table('conversations')->where('id', $conversation->id)->update(['customer_id' => $customerId]);
        }
    }

    /**
     * A number written on a customer record becomes an address they can
     * actually be reached at — no conversation required.
     */
    private function movePhonesOntoIdentities(): void
    {
        foreach (DB::table('customers')->whereNotNull('phone')->whereNull('deleted_at')->get() as $customer) {
            $taken = DB::table('identities')
                ->where('corporation_id', $customer->corporation_id)
                ->where('value', $customer->phone)
                ->whereNull('deleted_at')
                ->exists();

            if ($taken) {
                continue;
            }

            DB::table('identities')->insert([
                'corporation_id' => $customer->corporation_id,
                'customer_id' => $customer->id,
                'conversation_id' => null,
                'address_type' => 'phone',
                'value' => $customer->phone,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function assertPartialIndexes(): void
    {
        DB::statement('drop index if exists customers_corporation_id_email_unique');
        DB::statement(
            'create unique index customers_corporation_id_email_unique
             on customers (corporation_id, email)
             where email is not null and deleted_at is null'
        );

        DB::statement('drop index if exists customers_corporation_id_external_ref_unique');
        DB::statement(
            'create unique index customers_corporation_id_external_ref_unique
             on customers (corporation_id, external_ref)
             where external_ref is not null and deleted_at is null'
        );

        DB::statement('drop index if exists identities_corporation_id_address_type_value_unique');
        DB::statement(
            'create unique index identities_corporation_id_address_type_value_unique
             on identities (corporation_id, address_type, value) where deleted_at is null'
        );

        DB::statement('drop index if exists conversations_corporation_id_customer_id_unique');
        DB::statement(
            'create unique index conversations_corporation_id_customer_id_unique
             on conversations (corporation_id, customer_id)
             where customer_id is not null and deleted_at is null'
        );
    }
};
