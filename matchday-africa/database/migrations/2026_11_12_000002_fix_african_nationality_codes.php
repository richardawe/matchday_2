<?php

use App\Services\PlayerService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Players from Niger, Libya, Sudan, Malawi, Burundi, Mauritania, Eswatini … were stored with the first
 * three letters of the country (LIB, SUD, MAL …) and so never counted as African. Re-codes them from
 * the nationality name; rows with other names are left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('players')) {
            PlayerService::fixAfricanNationalityCodes();
        }
    }

    public function down(): void
    {
        // The old codes were wrong; nothing to restore.
    }
};
