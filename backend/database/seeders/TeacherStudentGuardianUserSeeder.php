<?php

namespace Database\Seeders;

use App\Models\GuardianUser;
use App\Models\Role;
use App\Models\StudentUser;
use App\Models\TeacherUser;
use Illuminate\Database\Seeder;

class TeacherStudentGuardianUserSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * The teacher/student/parent_guardian sample accounts UserSeeder used
     * to create directly in `users` — moved to their own tables since the
     * split. Same emails/phones/scope as before, so every downstream
     * seeder that looks these up by email keeps working.
     */
    public function run(): void
    {
        $teacherRole = Role::where('name', 'teacher')->firstOrFail();

        TeacherUser::updateOrCreate(
            ['email' => 'teacher@tlap.test'],
            [
                'name' => 'Teacher',
                'phone' => '01700000004',
                'password' => 'password',
                'role_id' => $teacherRole->id,
                'school_id' => 1,
                'district_id' => 1,
                'email_verified_at' => now(),
            ],
        );

        $studentRole = Role::where('name', 'student')->firstOrFail();

        StudentUser::updateOrCreate(
            ['email' => 'student@tlap.test'],
            [
                'name' => 'Student',
                'phone' => '01700000005',
                'password' => 'password',
                'role_id' => $studentRole->id,
                'school_id' => 1,
                'district_id' => 1,
                'email_verified_at' => now(),
            ],
        );

        $guardianRole = Role::where('name', 'parent_guardian')->firstOrFail();

        GuardianUser::updateOrCreate(
            ['email' => 'parent.guardian@tlap.test'],
            [
                'name' => 'Parent Guardian',
                'phone' => '01700000008',
                'password' => 'password',
                'role_id' => $guardianRole->id,
                'school_id' => 1,
                'district_id' => 1,
                'email_verified_at' => now(),
            ],
        );
    }
}
