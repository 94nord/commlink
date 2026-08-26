<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('corporations', function (Blueprint $table) {
            // Path on the default filesystem disk; embedded at the foot of
            // every outgoing email.
            $table->string('logo_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('corporations', function (Blueprint $table) {
            $table->dropColumn('logo_path');
        });
    }
};
