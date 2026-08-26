<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A thread is one continuous exchange on one identity. How threads form
     * is the channel's business, and external_key is where that decision is
     * recorded: SMS has no notion of separate threads, so its numbers keep a
     * single default thread with a null key. A channel that does thread —
     * email, whose mail clients follow a reference chain — stores that
     * chain's key here and gets several threads on the same identity.
     */
    public function up(): void
    {
        Schema::create('threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('corporation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('identity_id')->constrained()->cascadeOnDelete();
            $table->string('external_key')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // At most one default (unkeyed) thread per identity — the whole of
        // SMS threading — and keyed threads unique within their identity.
        DB::statement(
            'create unique index threads_identity_id_default_unique
             on threads (identity_id) where external_key is null and deleted_at is null'
        );
        DB::statement(
            'create unique index threads_identity_id_external_key_unique
             on threads (identity_id, external_key)
             where external_key is not null and deleted_at is null'
        );
    }

    public function down(): void
    {
        Schema::drop('threads');
    }
};
