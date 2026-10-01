<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // The partner panel signs in on the "Partners" realm, not "el_int". Saved settings may still hold the old address.
    private const OLD = 'https://sso.earthlink.iq/auth/realms/el_int/protocol/openid-connect/token';

    public function up(): void
    {
        DB::table('settings')->where('key', 'sync.token_url')->where('value', json_encode(self::OLD))->delete();
    }

    public function down(): void
    {
    }
};
