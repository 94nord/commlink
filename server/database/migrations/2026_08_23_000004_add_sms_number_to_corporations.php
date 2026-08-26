<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each firm texts from its own number, and inbound texts are routed back
     * to the firm by the number they were sent to — so this has to be unique
     * across the installation, and a firm without one cannot send yet.
     *
     * Numbers are bought in the Twilio console and attached with
     * `php artisan app:attach-sms-number`; nothing here provisions them.
     */
    public function up(): void
    {
        Schema::table('corporations', function (Blueprint $table) {
            $table->string('sms_number')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('corporations', function (Blueprint $table) {
            $table->dropColumn('sms_number');
        });
    }
};
