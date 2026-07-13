<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The outbox (X1): the event spine queues rows here; a scheduler
        // command drains them through Mailables. Durable, so bulk sendouts
        // (E2) survive restarts and failures are visible per row.
        Schema::create('outbox_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('corporation_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('recipient_email');
            $table->json('payload');
            $table->string('status')->default('pending');
            $table->text('error')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        // Per-recipient invite delivery state (SO4): not yet sent → sent,
        // plus failure. Booked/cancelled derive from the round's visits.
        Schema::table('round_work_orders', function (Blueprint $table) {
            $table->dateTime('invite_sent_at')->nullable();
            $table->dateTime('invite_failed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('round_work_orders', function (Blueprint $table) {
            $table->dropColumn(['invite_sent_at', 'invite_failed_at']);
        });

        Schema::dropIfExists('outbox_messages');
    }
};
