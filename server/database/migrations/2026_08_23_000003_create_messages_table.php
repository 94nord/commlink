<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One message, inbound or outbound. Append-only and never soft-deleted:
     * this is the record of what was actually said to a customer, and the
     * link/unlink operations above it are deliberately built so that no
     * message ever has to move or disappear.
     *
     * Anything a specific channel needs to remember about a message — the
     * Twilio SID and the from/to pair for SMS, a reference chain for email
     * later — goes into channel_data rather than a column, so the core stays
     * ignorant of every channel's shape. user_id is the staff author of an
     * outbound message; inbound messages have none.
     */
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('corporation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('thread_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel');
            $table->string('direction');
            $table->text('body');
            $table->json('channel_data')->nullable();
            $table->timestamp('sent_at');
            $table->timestamps();

            // The timeline reads messages by thread in time order.
            $table->index(['thread_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::drop('messages');
    }
};
