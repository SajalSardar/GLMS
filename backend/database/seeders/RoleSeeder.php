<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Roles come from `inspiration/AI_Teacher_Training_Proposal_v2.md` §3
     * (User Types and Permissions) — the 7 original roles plus the 3 new
     * roles it recommends (Parent/Guardian, IT/System Administrator, Auditor).
     */
    public function run(): void
    {
        $roles = [
            ['name' => 'national_admin', 'label' => 'National/Platform Administrator', 'description' => 'Manages national settings, districts, schools, users, courses, analytics, and reports.'],
            ['name' => 'district_officer', 'label' => 'District/Regional Officer', 'description' => 'Monitors assigned districts and schools, coordinates support.'],
            ['name' => 'school_admin', 'label' => 'Head Teacher/School Administrator', 'description' => 'Manages students, teachers, classes, attendance, results, fees, timetables, and reports for their school.'],
            ['name' => 'teacher', 'label' => 'Teacher', 'description' => 'Completes training and assessments; manages class attendance, results, and homework.'],
            ['name' => 'student', 'label' => 'Student', 'description' => 'Accesses permitted learning materials, assessments, results, and personal progress.'],
            ['name' => 'trainer', 'label' => 'Trainer/Content Reviewer', 'description' => 'Creates courses, reviews AI-generated questions, maintains rubrics and assessment quality.'],
            ['name' => 'office_staff', 'label' => 'Office/Accounts Staff', 'description' => 'Manages fees, receipts, admission registers, and official documents within assigned permissions.'],
            ['name' => 'parent_guardian', 'label' => 'Parent/Guardian', 'description' => 'Read-only access to their own child\'s attendance, results, fees, and notices.'],
            ['name' => 'it_admin', 'label' => 'IT/System Administrator', 'description' => 'Manages integrations, API keys, backups, and audit log review, without access to student academic data by default.'],
            ['name' => 'auditor', 'label' => 'Auditor', 'description' => 'Read-only, time-boxed access to audit logs and score history for compliance review.'],
        ];

        foreach ($roles as $role) {
            Role::updateOrCreate(['name' => $role['name']], $role);
        }
    }
}
