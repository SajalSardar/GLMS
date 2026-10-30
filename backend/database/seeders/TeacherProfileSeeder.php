<?php

namespace Database\Seeders;

use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Database\Seeder;

class TeacherProfileSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Attaches a profile to the sample teacher user created by UserSeeder.
     */
    public function run(): void
    {
        $user = User::where('email', 'teacher@tlap.test')->first();

        if (! $user) {
            return;
        }

        TeacherProfile::updateOrCreate(
            ['user_id' => $user->id],
            [
                'school_id' => $user->school_id,
                'district_id' => $user->district_id,
                'employee_id' => 'EMP-0001',
                'designation' => 'Assistant Teacher',
                'subject_specialization' => 'Mathematics',
                'qualification' => 'B.Sc, B.Ed',
                'joining_date' => '2015-01-10',
                'phone' => '01710000001',
                'blood_group' => 'B+',
                'present_address' => 'Debidwar, Comilla',
                'permanent_address' => 'Debidwar, Comilla',
            ],
        );
    }
}
