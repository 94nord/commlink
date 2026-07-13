<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('corporation_id')->constrained()->cascadeOnDelete();
            $table->morphs('owner');
            $table->foreignId('work_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->string('type');
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->externalRef();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['staff_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visits');
    }
};
