<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DB-level backstop for the per-staff no-double-book invariant (S1/B1).
 *
 * The app-level check in App\Actions\ScheduleVisit locks and inspects
 * existing rows, but a SELECT … FOR UPDATE matches zero rows for an empty
 * slot, so two truly simultaneous transactions could both pass it. This
 * exclusion constraint makes the database itself reject the second insert;
 * ScheduleVisit maps the violation to the same clean "just taken" error.
 *
 * Postgres-only (PHPUnit runs SQLite, where single-process execution makes
 * the race impossible). The [) range bounds match the app's semantics:
 * back-to-back visits (end == next start) do not collide. If existing data
 * ever contained an overlap, ADD CONSTRAINT fails at migrate time — that is
 * the desired signal, not something to work around.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        DB::statement(<<<'SQL'
            ALTER TABLE visits ADD CONSTRAINT visits_no_staff_overlap
            EXCLUDE USING gist (
                staff_id WITH =,
                tsrange(starts_at, ends_at) WITH &&
            )
            WHERE (
                cancelled_at IS NULL
                AND deleted_at IS NULL
                AND staff_id IS NOT NULL
                AND starts_at IS NOT NULL
                AND ends_at IS NOT NULL
            )
        SQL);
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        // The extension is shared and idempotent — leave it in place.
        DB::statement('ALTER TABLE visits DROP CONSTRAINT IF EXISTS visits_no_staff_overlap');
    }
};
