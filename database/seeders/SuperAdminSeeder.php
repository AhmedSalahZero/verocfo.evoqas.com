<?php

namespace Database\Seeders;

// ══════════════════════════════════════════════════════════════════
//  SuperAdminSeeder
//
//  Creates the platform-level Super Admin user for Vero CFO.
//
//  The Super Admin:
//    - Has no company_id (not bound to any company)
//    - Bypasses company permission checks
//    - Can create companies and assign Company Admins
//    - Accesses company data via impersonation session only
//    - Is the only user with role = 'super_admin'
//
//  AUDIT C4 FIX (Sept 2026) — no password in the source code:
//    Before: the email AND password were typed in this file, so
//    anyone holding the code (a contractor, a backup, a zip) could
//    log in as the platform owner on any server not yet changed.
//
//    Now:
//      - Email    → SUPER_ADMIN_EMAIL in the server's .env
//                   (defaults to admin@vero-cfo.com — an email is not
//                   a secret).
//      - Password → SUPER_ADMIN_PASSWORD in the server's .env.
//                   If it is missing, a strong random password is
//                   generated and shown ONCE in the terminal.
//      - Re-running the seeder NEVER changes the password of an
//        existing Super Admin. To change it, use:
//            php artisan vero:super-admin-password
//      - The existing Super Admin is found by ROLE, not by email,
//        so changing SUPER_ADMIN_EMAIL can never create a second
//        Super Admin by accident.
//
//  Run individually:
//    php artisan db:seed --class=SuperAdminSeeder
//
//  @layer    Seeder
//  @depends  App\Models\User
// ══════════════════════════════════════════════════════════════════

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $existing = User::where('role', User::ROLE_SUPER_ADMIN)->first();

        if ($existing) {
            $this->command->info("✓ Super Admin already exists ({$existing->email}) — password left unchanged.");
            $this->command->line('  To change it run: php artisan vero:super-admin-password');
            return;
        }

        $email     = env('SUPER_ADMIN_EMAIL', 'admin@vero-cfo.com');
        $password  = env('SUPER_ADMIN_PASSWORD');
        $generated = empty($password);

        if ($generated) {
            $password = Str::password(20);
        }

        User::create([
            'name'       => 'Mahmoud Youssef',
            'name_ar'    => 'محمود يوسف',
            'email'      => $email,
            'password'   => Hash::make($password),
            'role'       => User::ROLE_SUPER_ADMIN,
            'company_id' => null,
            'lang_pref'  => 'en',
            'timezone'   => 'Africa/Cairo',
            'is_active'  => true,
        ]);

        $this->command->info("✓ Super Admin created: {$email}");

        if ($generated) {
            $this->command->warn('  No SUPER_ADMIN_PASSWORD in .env — a random password was generated.');
            $this->command->warn("  Password (shown ONCE — save it now): {$password}");
        }
    }
}
