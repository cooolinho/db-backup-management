<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Creates the first admin user from BACKUP_ADMIN_* when the users table
 * is still empty. Run from the Docker entrypoint on every container start;
 * a no-op once at least one user exists, so it never touches accounts an
 * admin has since renamed, repassworded or otherwise took ownership of.
 */
class EnsureAdminCommand extends Command
{
    protected $signature = 'app:ensure-admin';

    protected $description = 'Create the first admin user from BACKUP_ADMIN_* if no user exists yet';

    public function handle(): int
    {
        if (User::query()->exists()) {
            $this->components->info('Admin user already exists, skipping.');

            return self::SUCCESS;
        }

        $email = config('backup.admin.email');

        if (blank($email)) {
            $this->components->warn(
                'No BACKUP_ADMIN_EMAIL set and no user exists yet. '.
                'Create one with "php artisan make:filament-user".'
            );

            return self::SUCCESS;
        }

        $password = config('backup.admin.password');
        $generated = blank($password);

        if ($generated) {
            $password = Str::password(20);
        }

        User::query()->create([
            'name' => config('backup.admin.name', 'Admin'),
            'email' => $email,
            'password' => Hash::make($password),
            'email_verified_at' => now(),
        ]);

        $this->components->info("Admin user created: {$email}");

        if ($generated) {
            $this->components->warn(
                "BACKUP_ADMIN_PASSWORD was empty, so a random password was generated: {$password}\n".
                '  Save it now — it is not stored anywhere and will not be shown again.'
            );
        }

        return self::SUCCESS;
    }
}
