<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every `Message-ID` we have sent or seen, and which exchange it belongs
     * to.
     *
     * A reply's `In-Reply-To` may name *any* message in the chain, not just
     * the one that started it — mail clients quote whichever message the
     * person happened to hit reply on, and a long chain fans out. Matching
     * only the root would scatter half a conversation into new threads. So
     * every id is recorded, and any of them resolves back to the same
     * thread.
     *
     * Deliberately adapter-owned: this table is written and read only by
     * `App\Inbox\Email`, and nothing in the core knows it exists. That is the
     * channel-specifics rule bending exactly as far as it has to — a
     * `message_id` column on core `messages` would leak email into every
     * channel, which is the thing the rule exists to prevent.
     */
    public function up(): void
    {
        Schema::create('email_message_ids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained()->cascadeOnDelete();
            // Installation-wide unique: a Message-ID is globally unique by
            // definition, so two threads claiming one would mean we had
            // already filed something wrong.
            $table->string('message_id')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::drop('email_message_ids');
    }
};
