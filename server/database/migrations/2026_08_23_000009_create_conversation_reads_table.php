<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How far each person has read in each conversation.
     *
     * Per user, not per firm: the inbox is shared, but "have I seen this" is
     * not. One colleague opening a conversation must not make it look handled
     * to everybody else — that is exactly how a message gets dropped.
     *
     * The marker is a message id rather than a timestamp because ids are
     * unambiguous: two messages can share a second, and a merge can bring
     * older messages in under newer ids.
     */
    public function up(): void
    {
        Schema::create('conversation_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('corporation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('last_read_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->timestamps();

            $table->unique(['conversation_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::drop('conversation_reads');
    }
};
