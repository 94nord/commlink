<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The name to offer back on download.
     *
     * Nullable: inbound MMS has none to give — Twilio's webhook carries only
     * a media URL and a content type — so a filename is something only an
     * upload-link submission can supply, from the browser's own file picker.
     */
    public function up(): void
    {
        Schema::table('message_attachments', function (Blueprint $table) {
            $table->string('filename')->nullable()->after('content_type');
        });
    }

    public function down(): void
    {
        Schema::table('message_attachments', function (Blueprint $table) {
            $table->dropColumn('filename');
        });
    }
};
