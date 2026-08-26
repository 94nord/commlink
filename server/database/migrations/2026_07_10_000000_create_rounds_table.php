<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('corporation_id')->constrained()->cascadeOnDelete();
            // Organizational belonging only (SO8) — never membership.
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->string('status')->default('draft');
            $table->unsignedInteger('appointment_minutes');
            $table->unsignedInteger('buffer_minutes')->default(0);
            $table->date('window_starts_on')->nullable();
            $table->date('window_ends_on')->nullable();
            $table->date('close_off_on');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rounds');
    }
};
