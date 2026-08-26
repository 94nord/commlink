<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which parts of the product each firm is switched on for.
     *
     * Presence of the row is the entire state — there is no `enabled`
     * column, because a boolean invites a third meaning (a row that is off,
     * versus no row at all) that nothing needs. Opting out deletes the row;
     * what "opted out" *does* — freeze, never delete — lives in code, and the
     * event log keeps the history of who switched what and when.
     *
     * Nothing is enabled at corporation creation. Existing firms are the one
     * exception: a firm already texting has to keep texting, so anything
     * holding a phone address is switched on for SMS here.
     */
    public function up(): void
    {
        Schema::create('corporation_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('corporation_id')->constrained()->cascadeOnDelete();
            $table->string('module');
            $table->timestamps();

            $table->unique(['corporation_id', 'module']);
        });

        $this->backfillFromExistingAddresses();
    }

    public function down(): void
    {
        Schema::drop('corporation_modules');
    }

    /**
     * A firm that could text yesterday can text today. Firms created from
     * here on start with nothing and are switched on deliberately.
     */
    private function backfillFromExistingAddresses(): void
    {
        $corporationIds = DB::table('corporation_addresses')
            ->where('address_type', 'phone')
            ->whereNull('deleted_at')
            ->distinct()
            ->pluck('corporation_id');

        foreach ($corporationIds as $corporationId) {
            DB::table('corporation_modules')->insert([
                'corporation_id' => $corporationId,
                'module' => 'sms',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};
