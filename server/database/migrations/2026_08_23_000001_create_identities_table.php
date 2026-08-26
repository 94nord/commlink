<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An identity is one address the customer can be reached at on one
     * channel — a phone number for SMS, later a mailbox for email. Values
     * are stored already normalized (E.164 for phone), because matching an
     * inbound message is a plain equality lookup on this column.
     *
     * Identities point at a conversation rather than a customer: that single
     * anchor covers anonymous numbers too, and it makes merge and unlink one
     * column update each with no message rows touched.
     */
    public function up(): void
    {
        Schema::create('identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('corporation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('channel');
            $table->string('value');
            $table->timestamps();
            $table->softDeletes();
        });

        // One live owner per address within a corporation: the same number
        // cannot be gathered under two conversations at once, which is what
        // makes inbound matching unambiguous.
        DB::statement(
            'create unique index identities_corporation_id_channel_value_unique
             on identities (corporation_id, channel, value) where deleted_at is null'
        );
    }

    public function down(): void
    {
        Schema::drop('identities');
    }
};
