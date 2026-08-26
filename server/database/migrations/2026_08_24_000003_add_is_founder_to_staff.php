<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marks the staff row `app:create-corporation` creates for the person
     * who provisioned the firm — the one always-admin, always-present S2
     * invariant already relies on, now recorded on the row itself instead
     * of being implied by "whichever admin happened to be created first".
     *
     * This is a stronger, unconditional guard than the existing "last admin
     * with a login" / "last staff member" checks: those stop the corporation
     * from ending up with zero admins or zero staff, but say nothing about
     * *which* staff member survives. The founder can neither be deleted nor
     * demoted, regardless of how many other admins exist.
     */
    public function up(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->boolean('is_founder')->default(false)->after('is_admin');
        });
    }

    public function down(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->dropColumn('is_founder');
        });
    }
};
