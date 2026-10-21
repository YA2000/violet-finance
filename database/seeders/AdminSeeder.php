<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        if (User::where('is_super', true)->exists()) {
            return;
        }
        $email = env('ADMIN_EMAIL', 'admin@violet.local');
        $pass = env('ADMIN_PASSWORD', 'ChangeMe!2026');
        User::create(['name' => 'Administrator', 'email' => $email, 'password' => $pass, 'role' => 'admin', 'is_super' => true, 'active' => true]);
        $this->command?->info("Administrator: $email / $pass  (change the password after the first login)");
    }
}
