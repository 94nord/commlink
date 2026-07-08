<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The customer: the durable anchor, scoped to one corporation. Visits
     * and work orders reference the customer, never the email — the inline
     * email is a single contact identity that can move to a contact-points
     * table later without touching those relationships.
     */
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('corporation_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->string('name');
            $table->string('email');
            $table->externalRef();
            $table->timestamps();
            $table->softDeletes();
        });

        // Partial unique indexes (identical syntax on PostgreSQL and SQLite).
        // Scoped to live rows so a soft-deleted (erased) customer releases
        // its email and host link; null external_refs never collide.
        DB::statement(
            'create unique index customers_corporation_id_email_unique
             on customers (corporation_id, email) where deleted_at is null'
        );
        DB::statement(
            'create unique index customers_corporation_id_external_ref_unique
             on customers (corporation_id, external_ref)
             where external_ref is not null and deleted_at is null'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::drop('customers');
    }
};
