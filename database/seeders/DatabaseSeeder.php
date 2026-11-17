<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Creates or updates the admin and test client from config/site.php.
     * Re-running it resets their passwords to the values in .env.
     */
    public function run(): void
    {
        $this->seedAccount('admin', UserRole::Admin);
        $this->seedAccount('test_client', UserRole::Client);
    }

    private function seedAccount(string $configKey, UserRole $role): void
    {
        $account = config("site.{$configKey}");

        if (empty($account['email'])) {
            $this->command?->warn("Skipping {$configKey}: email is not set in .env.");

            return;
        }

        if (empty($account['password'])) {
            $this->command?->warn("Skipping {$configKey}: password is not set in .env.");

            return;
        }

        $user = User::query()->firstOrNew(['email' => $account['email']]);

        $user->forceFill([
            'name' => $account['name'],
            'password' => $account['password'],
            'role' => $role,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        $this->command?->info("Seeded {$role->value} account {$account['email']}.");
    }
}
