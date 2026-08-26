<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rounds', function (Blueprint $table) {
            // The scheduled-start instant (SO1 timing axis): a draft round
            // with this set opens automatically when the time arrives.
            $table->dateTime('scheduled_open_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('rounds', function (Blueprint $table) {
            $table->dropColumn('scheduled_open_at');
        });
    }
};
