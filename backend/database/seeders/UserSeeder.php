<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * One login-ready sample user per role, for exercising the auth API
     * and the permission model end to end. Password is "password" for all.
     * School/district-scoped roles are pinned to school_id 1 (Debidwar
     * Pilot Government High School, district_id 1 / Comilla) seeded by
     * SchoolSeeder; platform-level roles are left unscoped.
     */
    public function run(): void
    {
        $users = [
            ['role' => 'national_admin', 'name' => 'National Admin', 'email' => 'national.admin@tlap.test', 'phone' => '01700000001'],
            ['role' => 'district_officer', 'name' => 'District Officer', 'email' => 'district.officer@tlap.test', 'phone' => '01700000002', 'district_id' => 1],
            ['role' => 'school_admin', 'name' => 'School Admin', 'email' => 'school.admin@tlap.test', 'phone' => '01700000003', 'school_id' => 1, 'district_id' => 1],
            ['role' => 'trainer', 'name' => 'Trainer', 'email' => 'trainer@tlap.test', 'phone' => '01700000006'],
            ['role' => 'office_staff', 'name' => 'Office Staff', 'email' => 'office.staff@tlap.test', 'phone' => '01700000007', 'school_id' => 1, 'district_id' => 1],
            ['role' => 'it_admin', 'name' => 'IT Admin', 'email' => 'it.admin@tlap.test', 'phone' => '01700000009'],
            ['role' => 'auditor', 'name' => 'Auditor', 'email' => 'auditor@tlap.test', 'phone' => '01700000010'],
        ];

        foreach ($users as $user) {
            $role = Role::where('name', $user['role'])->firstOrFail();

            User::updateOrCreate(
                ['email' => $user['email']],
                [
                    'name' => $user['name'],
                    'phone' => $user['phone'],
                    'password' => 'password',
                    'role_id' => $role->id,
                    'school_id' => $user['school_id'] ?? null,
                    'district_id' => $user['district_id'] ?? null,
                    'email_verified_at' => now(),
                ],
            );
        }
    }
}
