<?php

namespace Database\Seeders;

use App\Models\Guardian;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Database\Seeder;

class GuardianSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Attaches a guardian to the sample parent_guardian user created by
     * UserSeeder and links them to the sample student profile as father.
     */
    public function run(): void
    {
        $user = User::where('email', 'parent.guardian@tlap.test')->first();

        $guardian = Guardian::updateOrCreate(
            ['phone' => '01810000001'],
            [
                'user_id' => $user?->id,
                'name' => 'Abdul Karim',
                'bn_name' => 'আব্দুল করিম',
                'email' => 'parent.guardian@tlap.test',
                'occupation' => 'Farmer',
                'present_address' => 'Debidwar, Comilla',
            ],
        );

        $studentProfile = StudentProfile::where('student_id', 'STU-0001')->first();

        if ($studentProfile) {
            $studentProfile->guardians()->syncWithoutDetaching([
                $guardian->id => ['relation' => 'father', 'is_primary' => true],
            ]);
        }
    }
}
