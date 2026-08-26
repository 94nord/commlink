<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A pointer to the newest message, so the inbox list can show each
     * conversation's last line without walking identities and threads for
     * every row. `last_message_at` already denormalizes the same fact for
     * ordering; this is its other half, and the two are always written
     * together (see Conversation::touchLastMessage / recomputeLastMessage).
     *
     * The inbox list is polled every few seconds by every open browser, so
     * it is the one query in the module worth denormalizing for.
     */
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->foreignId('last_message_id')->nullable()->constrained('messages')->nullOnDelete();
        });

        // Adding a foreign key makes SQLite rebuild the whole table, and the
        // rebuild copies indexes without their WHERE clause — quietly turning
        // the claim index below into a plain unique one, so a merged-away
        // conversation would hold its customer hostage forever. PostgreSQL
        // does no such thing, so this only ever bites in the test suite.
        // Re-assert it; both statements are valid on either engine.
        //
        // Any future migration that alters this table needs to do the same.
        DB::statement('drop index if exists conversations_corporation_id_customer_id_unique');
        DB::statement(
            'create unique index conversations_corporation_id_customer_id_unique
             on conversations (corporation_id, customer_id)
             where customer_id is not null and deleted_at is null'
        );
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('last_message_id');
        });
    }
};
