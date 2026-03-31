<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class UserSeeder extends Seeder
{
    /**
     * Seed the application's users. Generates secure random passwords
     * and displays them once in the terminal output.
     */
    public function run(): void
    {
        $users = [
            ['name' => 'Thomas', 'email' => 'thomas@cyclowax.com'],
            ['name' => 'Jakob', 'email' => 'jakob@cyclowax.com'],
            ['name' => 'Stan', 'email' => 'stan@cyclowax.com'],
            ['name' => 'Frederik', 'email' => 'frederik@cyclowax.com'],
        ];

        $credentials = [];

        foreach ($users as $userData) {
            $existing = User::where('email', $userData['email'])->first();

            if ($existing) {
                $this->command->line("  Skipped: {$userData['email']} (already exists)");

                continue;
            }

            $password = Str::password(16);

            User::create([
                'name' => $userData['name'],
                'email' => $userData['email'],
                'password' => bcrypt($password),
                'email_verified_at' => now(),
            ]);

            $credentials[] = [$userData['name'], $userData['email'], $password];
        }

        if (empty($credentials)) {
            $this->command->info('All users already exist — no new accounts created.');

            return;
        }

        $this->command->newLine();
        $this->command->warn('⚠ Copy these credentials now — they will not be shown again:');
        $this->command->newLine();
        $this->command->table(
            ['Name', 'Email', 'Password'],
            $credentials,
        );
        $this->command->newLine();
        $this->command->info(count($credentials).' user(s) created.');
    }
}
