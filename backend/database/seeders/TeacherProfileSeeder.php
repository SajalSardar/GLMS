<?php

namespace Database\Seeders;

use App\Models\TeacherProfile;
use App\Models\TeacherUser;
use Illuminate\Database\Seeder;

class TeacherProfileSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Gives every demo teacher user a full profile — not just the
     * original sample account (teacher@tlap.test). TimetableGenerationDemoSeeder
     * creates seven more teacher users (one per Class 7 subject) that
     * otherwise have no profile at all, which left the Teacher Portal's
     * "My Profile" page and the Management Portal's teacher roster showing
     * a blank record for all of them. Each entry below matches the subject
     * that teacher was actually assigned to there.
     *
     * Any teacher user outside this known set (e.g. one created ad hoc, or
     * by a future seeder) still gets a generic profile from the fallback
     * loop at the end, so running this seeder always leaves zero teacher
     * users without a profile.
     */
    public function run(): void
    {
        $profiles = [
            'teacher@tlap.test' => [
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
            'nasrin.akter@tlap.test' => [
                'employee_id' => 'EMP-0002',
                'designation' => 'Senior Teacher',
                'subject_specialization' => 'Bangla',
                'qualification' => 'M.A in Bangla, B.Ed',
                'joining_date' => '2012-03-01',
                'phone' => '01710000002',
                'blood_group' => 'A+',
                'present_address' => 'Debidwar, Comilla',
                'permanent_address' => 'Debidwar, Comilla',
            ],
            'shafiqul.islam@tlap.test' => [
                'employee_id' => 'EMP-0003',
                'designation' => 'Assistant Teacher',
                'subject_specialization' => 'English',
                'qualification' => 'M.A in English, B.Ed',
                'joining_date' => '2016-07-15',
                'phone' => '01710000003',
                'blood_group' => 'O+',
                'present_address' => 'Debidwar, Comilla',
                'permanent_address' => 'Debidwar, Comilla',
            ],
            'karim.ahmed@tlap.test' => [
                'employee_id' => 'EMP-0004',
                'designation' => 'Assistant Teacher',
                'subject_specialization' => 'Mathematics',
                'qualification' => 'B.Sc in Mathematics, B.Ed',
                'joining_date' => '2017-02-20',
                'phone' => '01710000004',
                'blood_group' => 'B+',
                'present_address' => 'Debidwar, Comilla',
                'permanent_address' => 'Debidwar, Comilla',
            ],
            'farida.yasmin@tlap.test' => [
                'employee_id' => 'EMP-0005',
                'designation' => 'Assistant Teacher',
                'subject_specialization' => 'Science',
                'qualification' => 'B.Sc in Physics, B.Ed',
                'joining_date' => '2018-01-05',
                'phone' => '01710000005',
                'blood_group' => 'AB+',
                'present_address' => 'Debidwar, Comilla',
                'permanent_address' => 'Debidwar, Comilla',
            ],
            'rafiqul.hasan@tlap.test' => [
                'employee_id' => 'EMP-0006',
                'designation' => 'Assistant Teacher',
                'subject_specialization' => 'Bangladesh and Global Studies',
                'qualification' => 'M.S.S in Social Science, B.Ed',
                'joining_date' => '2014-09-10',
                'phone' => '01710000006',
                'blood_group' => 'A-',
                'present_address' => 'Debidwar, Comilla',
                'permanent_address' => 'Debidwar, Comilla',
            ],
            'shirin.sultana@tlap.test' => [
                'employee_id' => 'EMP-0007',
                'designation' => 'Assistant Teacher',
                'subject_specialization' => 'Information and Communication Technology',
                'qualification' => 'B.Sc in Computer Science, B.Ed',
                'joining_date' => '2019-04-18',
                'phone' => '01710000007',
                'blood_group' => 'O-',
                'present_address' => 'Debidwar, Comilla',
                'permanent_address' => 'Debidwar, Comilla',
            ],
            'abdul.malek@tlap.test' => [
                'employee_id' => 'EMP-0008',
                'designation' => 'Assistant Teacher',
                'subject_specialization' => 'Religious Studies',
                'qualification' => 'Fazil, B.Ed',
                'joining_date' => '2013-06-01',
                'phone' => '01710000008',
                'blood_group' => 'B-',
                'present_address' => 'Debidwar, Comilla',
                'permanent_address' => 'Debidwar, Comilla',
            ],
        ];

        foreach ($profiles as $email => $data) {
            $user = TeacherUser::where('email', $email)->first();

            if (! $user) {
                continue;
            }

            TeacherProfile::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'school_id' => $user->school_id,
                    'district_id' => $user->district_id,
                    ...$data,
                ],
            );
        }

        $this->seedFallbackProfiles();
    }

    /**
     * Give a generic profile to any teacher user not covered above, so
     * this seeder always leaves every teacher with a complete profile
     * regardless of how many demo teachers other seeders create.
     */
    private function seedFallbackProfiles(): void
    {
        $remaining = TeacherUser::whereDoesntHave('profile')->get();

        if ($remaining->isEmpty()) {
            return;
        }

        $nextNumber = TeacherProfile::withoutGlobalScopes()->count() + 1;
        $designations = ['Assistant Teacher', 'Senior Teacher', 'Head Teacher'];
        $subjects = ['Bangla', 'English', 'Mathematics', 'Science', 'Bangladesh and Global Studies'];
        $qualifications = ['B.A, B.Ed', 'B.Sc, B.Ed', 'M.A, B.Ed', 'M.Sc, B.Ed'];
        $bloodGroups = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

        foreach ($remaining as $user) {
            $city = fake()->city();

            TeacherProfile::create([
                'user_id' => $user->id,
                'school_id' => $user->school_id,
                'district_id' => $user->district_id,
                'employee_id' => sprintf('EMP-%04d', $nextNumber++),
                'designation' => fake()->randomElement($designations),
                'subject_specialization' => fake()->randomElement($subjects),
                'qualification' => fake()->randomElement($qualifications),
                'joining_date' => fake()->dateTimeBetween('-15 years', '-1 year')->format('Y-m-d'),
                'phone' => '017'.fake()->numerify('########'),
                'blood_group' => fake()->randomElement($bloodGroups),
                'present_address' => "{$city}, Bangladesh",
                'permanent_address' => "{$city}, Bangladesh",
            ]);
        }
    }
}
