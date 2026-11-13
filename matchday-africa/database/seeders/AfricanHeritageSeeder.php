<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

/** Seeds heritage records from database/seeders/data/african_heritage_epl.json (players:heritage-import). */
class AfricanHeritageSeeder extends Seeder
{
    public function run(): void
    {
        if (is_file(database_path('seeders/data/african_heritage_epl.json'))) {
            Artisan::call('players:heritage-import');
            $this->command?->getOutput()->write(Artisan::output());
        }
    }
}
