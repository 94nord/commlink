<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Which hours of a day the round offers slots in — a stopgap until
        // staff availability windows exist (the admin defines the offer per
        // round, as in the original examination concept).
        Schema::table('rounds', function (Blueprint $table) {
            $table->time('day_starts_at')->default('08:00');
            $table->time('day_ends_at')->default('16:00');
        });

        // The magic-link capability (A3): unguessable, scoped to one case in
        // one round, revocable by clearing the row.
        Schema::table('round_work_orders', function (Blueprint $table) {
            $table->string('token', 64)->nullable()->unique();
        });

        // The "was it batch-scheduled" link — set when a customer books
        // through a round.
        Schema::table('visits', function (Blueprint $table) {
            $table->foreignId('round_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->dropConstrainedForeignId('round_id');
        });

        Schema::table('round_work_orders', function (Blueprint $table) {
            $table->dropColumn('token');
        });

        Schema::table('rounds', function (Blueprint $table) {
            $table->dropColumn(['day_starts_at', 'day_ends_at']);
        });
    }
};
