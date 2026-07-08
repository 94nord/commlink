<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The central event log (X1): every state change records a row here.
     * Append-only — rows are never updated, hence no updated_at.
     */
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('corporation_id')->constrained()->cascadeOnDelete();
            $table->string('type')->index();
            $table->nullableMorphs('actor');
            $table->nullableMorphs('subject');
            $table->json('payload')->nullable();
            $table->timestamp('created_at');

            $table->index(['corporation_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::drop('events');
    }
};
