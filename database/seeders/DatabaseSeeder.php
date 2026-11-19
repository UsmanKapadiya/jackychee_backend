<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $email = strtolower((string) env('ADMIN_EMAIL', 'admin@example.com'));
        $password = (string) env('ADMIN_PASSWORD', '');
        $generated = false;

        if ($password === '') {
            $password = Str::password(20);
            $generated = true;
        }

        // Skip when users already exist (e.g. imported from WordPress with their old passwords)
        if (! User::query()->exists()) {
            User::create([
                'name' => env('ADMIN_NAME', 'Site Admin'),
                'username' => 'admin',
                'email' => $email,
                'password' => $password,
                'role' => 'admin',
            ]);

            $this->command?->info("Admin user created: {$email}");
            if ($generated) {
                $this->command?->warn("Generated password (shown once, change it after login): {$password}");
            }
        }

        foreach (['site_name' => 'Jacky Chee', 'site_tagline' => null, 'footer_text' => null] as $key => $value) {
            if (! Setting::query()->whereKey($key)->exists()) {
                Setting::put($key, $value);
            }
        }
    }
}
