<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            // A staff member's optional login (D3: admin-provisioned, never
            // self-serve). The admin role lives here — it gates the Staff
            // page and Corporation settings.
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('email')->nullable();
            $table->boolean('is_admin')->default(false);
        });

        // One live login-email per corporation (mirrors the customers index).
        DB::statement(
            'create unique index staff_corporation_id_email_unique
             on staff (corporation_id, email) where email is not null and deleted_at is null'
        );

        // Link each existing founder staff to its founder user and make it an
        // admin. Today there is exactly one user and one staff per corporation.
        foreach (DB::table('users')->get() as $user) {
            DB::table('staff')
                ->where('corporation_id', $user->corporation_id)
                ->whereNull('user_id')
                ->orderBy('id')
                ->limit(1)
                ->update([
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'is_admin' => true,
                ]);
        }
    }

    public function down(): void
    {
        DB::statement('drop index if exists staff_corporation_id_email_unique');

        Schema::table('staff', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn(['email', 'is_admin']);
        });
    }
};
