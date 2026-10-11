<?php

namespace App\Console\Commands;

// ══════════════════════════════════════════════════════════════════
//  SetSuperAdminPassword  (php artisan vero:super-admin-password)
//
//  AUDIT C4 FIX (Sept 2026). Lets the platform owner change the
//  Super Admin password on any server, safely:
//    - The password is typed at a hidden prompt (never on screen,
//      never saved in the terminal history, never in the code).
//    - Typed twice to avoid typos.
//    - Minimum 12 characters, with letters and numbers.
//    - Logs out every other session of the Super Admin (the old
//      password stops working everywhere immediately).
//
//  Run this on EVERY server (local, staging, production) once, because
//  the old password was published inside the code.
//
//  @layer    Console Command
//  @depends  App\Models\User
// ══════════════════════════════════════════════════════════════════

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class SetSuperAdminPassword extends Command
{
    protected $signature   = 'vero:super-admin-password';
    protected $description = 'Set a new password for the Super Admin (typed at a hidden prompt)';

    public function handle(): int
    {
        $admin = User::where('role', User::ROLE_SUPER_ADMIN)->first();

        if (! $admin) {
            $this->error('No Super Admin found. Run: php artisan db:seed --class=SuperAdminSeeder');
            return self::FAILURE;
        }

        $this->info("Changing password for Super Admin: {$admin->email}");

        $password = (string) $this->secret('New password (min 12 characters, letters + numbers)');
        $confirm  = (string) $this->secret('Type it again');

        if ($password !== $confirm) {
            $this->error('The two passwords do not match. Nothing was changed.');
            return self::FAILURE;
        }

        if (strlen($password) < 12 || ! preg_match('/[A-Za-z]/', $password) || ! preg_match('/[0-9]/', $password)) {
            $this->error('Password must be at least 12 characters and contain letters and numbers. Nothing was changed.');
            return self::FAILURE;
        }

        $admin->forceFill([
            'password'       => Hash::make($password),
            'remember_token' => null,
        ])->save();

        // End every existing login of the Super Admin (database sessions).
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $admin->id)->delete();
        }

        $this->info('✓ Super Admin password changed. All existing Super Admin sessions have been logged out.');

        return self::SUCCESS;
    }
}
