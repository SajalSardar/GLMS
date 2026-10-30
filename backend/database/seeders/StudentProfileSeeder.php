<?php

namespace Database\Seeders;

use App\Models\SchoolClass;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Database\Seeder;

class StudentProfileSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Attaches a profile to the sample student user created by UserSeeder,
     * placed in the school's Class 6 seeded by ClassSeeder.
     */
    public function run(): void
    {
        $user = User::where('email', 'student@tlap.test')->first();

        if (! $user) {
            return;
        }

        $class = SchoolClass::where('school_id', $user->school_id)->where('name', 'Class 6')->first();

        StudentProfile::updateOrCreate(
            ['user_id' => $user->id],
            [
                'school_id' => $user->school_id,
                'district_id' => $user->district_id,
                'class_id' => $class?->id,
                'student_id' => 'STU-0001',
                'roll_number' => '1',
                'admission_date' => '2023-01-05',
                'date_of_birth' => '2012-04-15',
                'gender' => 'male',
                'blood_group' => 'O+',
                'present_address' => 'Debidwar, Comilla',
                'permanent_address' => 'Debidwar, Comilla',
            ],
        );
    }
}
