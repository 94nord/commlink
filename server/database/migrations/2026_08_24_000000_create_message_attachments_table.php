<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A file that arrived with a message. Channel-agnostic on purpose: MMS
     * fills it today, WhatsApp and email will fill the same shape later, so
     * the core never learns which channel a picture came from.
     *
     * path points into the configured Flysystem disk rather than at the
     * sender's own URL — a provider's media link is authenticated and not
     * kept forever, so the bytes have to become ours on arrival.
     */
    public function up(): void
    {
        Schema::create('message_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('corporation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->string('content_type');
            $table->string('path');
            $table->unsignedInteger('byte_size');
            $table->timestamps();

            $table->index('message_id');
        });
    }

    public function down(): void
    {
        Schema::drop('message_attachments');
    }
};
