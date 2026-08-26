<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The conversation: a thin per-contact entity carrying team state
     * (status, later watchers and ownership). It is NOT the message store —
     * messages hang off threads, which hang off identities, which point back
     * here. Gathering happens at read time, so linking or unlinking a number
     * never moves a message row.
     *
     * customer_id is nullable on purpose: an unknown number that texts in
     * gets a conversation with no customer, and the conversation itself is
     * the anonymous anchor. Naming that person later just fills this column
     * in. A customer holds at most one live conversation (partial unique
     * index below); anonymous ones are unlimited.
     */
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('corporation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            // Reserved for the "take over" feature; nothing writes it yet.
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('open');
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['corporation_id', 'last_message_at']);
        });

        // Partial unique index (identical syntax on PostgreSQL and SQLite).
        // One live conversation per customer, so a reply never lands in a
        // second thread of the same person; anonymous rows (null customer)
        // are exempt, and a merged-away conversation releases its claim.
        DB::statement(
            'create unique index conversations_corporation_id_customer_id_unique
             on conversations (corporation_id, customer_id)
             where customer_id is not null and deleted_at is null'
        );
    }

    public function down(): void
    {
        Schema::drop('conversations');
    }
};
