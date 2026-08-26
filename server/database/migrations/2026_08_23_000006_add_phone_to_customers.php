<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A phone number on the customer record, and an email that is no longer
     * required — plenty of trade customers are a name and a mobile number,
     * and demanding an address nobody has invites made-up ones.
     *
     * This is the address book, not the inbox's routing table. `identities`
     * stays the thing inbound messages are matched against and the thing a
     * reply is addressed to; this column is what staff maintain, and what
     * the inbox reaches for when it needs a number for somebody it has
     * never texted. The two are allowed to differ: a customer can be
     * contacted on a number that is not the one on their card.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('phone')->nullable();
        });

        // Emails become optional. SQLite cannot drop a NOT NULL in place, so
        // the table is rebuilt — which silently drops the WHERE clause from
        // the partial indexes below. Re-assert both afterwards.
        Schema::table('customers', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });

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
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('phone');
        });
    }
};
