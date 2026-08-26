<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Two things the dialog needs: to read a link back, and to close one.
     *
     * `token` lets the office reopen the dialog and copy the same URL again
     * instead of minting a second one. The hash stays and is still what a
     * token is looked up by — it is deterministic, indexed and unique, which
     * an encrypted value cannot be. The trade is deliberate: a stolen
     * database alone gave up no working links before and now gives them up
     * to anyone who also has APP_KEY. What that buys is bounded — one
     * conversation, a photo cap, an expiry — and it is what makes the link
     * re-copyable at all.
     *
     * `revoked_at` is the firm closing a link early, kept apart from
     * expires_at so the log still shows which links ran out on their own and
     * which were replaced.
     */
    public function up(): void
    {
        Schema::table('upload_links', function (Blueprint $table) {
            $table->text('token')->nullable()->after('token_hash');
            $table->timestamp('revoked_at')->nullable()->after('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('upload_links', function (Blueprint $table) {
            $table->dropColumn(['token', 'revoked_at']);
        });
    }
};
