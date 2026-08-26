<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An identity is an address, not a transport.
     *
     * It was keyed on the channel a message arrived over, which would have
     * split one person across several conversations the moment a second
     * channel reached the same place: a text, a picture message and a
     * voicemail all go to one phone number, and the firm thinks of them as
     * one exchange with one person. Keying on the *kind of address* keeps
     * them together — one identity, one thread, one conversation — while
     * each message still records the transport it actually used.
     */
    public function up(): void
    {
        // Drop first: the index names the column, and recreating it after
        // the rename is cheaper than reasoning about what each engine does
        // to an index whose column moved underneath it.
        DB::statement('drop index if exists identities_corporation_id_channel_value_unique');

        Schema::table('identities', function (Blueprint $table) {
            $table->renameColumn('channel', 'address_type');
        });

        DB::table('identities')->where('address_type', 'sms')->update(['address_type' => 'phone']);

        DB::statement(
            'create unique index identities_corporation_id_address_type_value_unique
             on identities (corporation_id, address_type, value) where deleted_at is null'
        );
    }

    public function down(): void
    {
        DB::statement('drop index if exists identities_corporation_id_address_type_value_unique');

        DB::table('identities')->where('address_type', 'phone')->update(['address_type' => 'sms']);

        Schema::table('identities', function (Blueprint $table) {
            $table->renameColumn('address_type', 'channel');
        });

        DB::statement(
            'create unique index identities_corporation_id_channel_value_unique
             on identities (corporation_id, channel, value) where deleted_at is null'
        );
    }
};
