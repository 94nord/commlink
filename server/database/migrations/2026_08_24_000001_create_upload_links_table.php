<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A way for a customer to hand the firm a photo without an account.
     *
     * The number most Danish firms text from cannot receive MMS at all, so a
     * link is how a picture gets in: the firm texts it, the customer opens it
     * on their phone and uploads.
     *
     * Only a hash of the token is kept. The token itself exists in the text
     * message and nowhere else, so a copy of this table hands out no live
     * links — the same reason password reset tokens are stored this way.
     * expires_at and upload_count are what close it again: this is the one
     * unauthenticated way into the system, and it is not left standing open.
     */
    public function up(): void
    {
        Schema::create('upload_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('corporation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            // Who asked for the photos, kept for the audit trail. Null once
            // that person is deleted — the link outlives them.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('token_hash')->unique();
            $table->timestamp('expires_at');
            $table->unsignedInteger('upload_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::drop('upload_links');
    }
};
