<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            // Optional human label (shown in lists when present) and free-form
            // notes; the address stays the fallback identity.
            $table->string('title')->nullable()->after('project_id');
            $table->text('notes')->nullable()->after('address');
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropColumn(['title', 'notes']);
        });
    }
};
