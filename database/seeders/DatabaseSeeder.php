<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([MasterDataSeeder::class, FormsConfigSeeder::class, AdminSeeder::class]);
        \App\Support\Upgrade::run();
    }
}
