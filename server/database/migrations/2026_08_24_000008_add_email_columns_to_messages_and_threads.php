<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a channel richer than SMS needs the core to hold.
     *
     * `messages.body_html` is deliberately generic, not an email column:
     * `body` stays the plain-text canonical form on every channel — that is
     * what search and the list preview read — and `body_html` carries the
     * rich version when a channel produces one. Rendering is a presence
     * check on this column, never a check on which channel a message came
     * from. It is not in `channel_data`, which is for transport metadata
     * nobody reads on the way to the screen.
     *
     * `threads.subject` labels a thread for channels that have such a thing;
     * null for SMS, where a number is one unbroken exchange.
     *
     * `threads.corporation_address_id` is the From binding, set when the
     * thread is created and never resolved again. Changing which address new
     * exchanges start from must not silently move an exchange already under
     * way onto a different one — a customer replying to a months-old message
     * replies to whatever we sent from at the time.
     */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->text('body_html')->nullable()->after('body');
        });

        Schema::table('threads', function (Blueprint $table) {
            $table->string('subject')->nullable();
            $table->foreignId('corporation_address_id')->nullable()->constrained()->nullOnDelete();
        });

        // Adding a foreign key rebuilds the table on SQLite, and a rebuild
        // copies indexes without their WHERE clause — which would silently
        // turn "one default thread per identity" into "one thread per
        // identity" and break every keyed thread email is about to create.
        $this->assertThreadIndexes();
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('body_html');
        });

        Schema::table('threads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('corporation_address_id');
            $table->dropColumn('subject');
        });

        $this->assertThreadIndexes();
    }

    private function assertThreadIndexes(): void
    {
        DB::statement('drop index if exists threads_identity_id_default_unique');
        DB::statement(
            'create unique index threads_identity_id_default_unique
             on threads (identity_id) where external_key is null and deleted_at is null'
        );

        DB::statement('drop index if exists threads_identity_id_external_key_unique');
        DB::statement(
            'create unique index threads_identity_id_external_key_unique
             on threads (identity_id, external_key)
             where external_key is not null and deleted_at is null'
        );
    }
};
